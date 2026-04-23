<?php

namespace App\Console\Commands;

use App\Models\AiLesson;
use App\Models\DailyReview;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExtractWeeklyLessons extends Command
{
    protected $signature = 'stock:extract-weekly-lessons {--mode=all : intraday/overnight/all} {--weeks=1 : 往回幾週}';
    protected $description = '從整週 AI 檢討報告萃取通用教訓（取代每日萃取）';

    private string $apiKey;
    private string $model;

    public function __construct()
    {
        parent::__construct();
        $this->apiKey = config('services.anthropic.api_key', '');
        $this->model  = config('services.anthropic.model', 'claude-opus-4-6');
    }

    public function handle(): int
    {
        $mode  = $this->option('mode');
        $weeks = (int) $this->option('weeks');

        if (!in_array($mode, ['intraday', 'overnight', 'all'])) {
            $this->error("--mode 必須為 intraday / overnight / all");
            return 1;
        }

        // 計算週範圍：本週一 ~ 本週五
        $friday = Carbon::now()->endOfWeek(Carbon::FRIDAY);
        $monday = $friday->copy()->startOfWeek(Carbon::MONDAY);

        if ($weeks > 1) {
            $monday = $monday->subWeeks($weeks - 1);
        }

        $dateFrom = $monday->format('Y-m-d');
        $dateTo   = $friday->format('Y-m-d');

        $this->info("萃取範圍：{$dateFrom} ~ {$dateTo}");

        $modes = $mode === 'all' ? ['intraday', 'overnight'] : [$mode];
        $totalLessons = 0;

        foreach ($modes as $m) {
            $count = $this->extractForMode($m, $dateFrom, $dateTo);
            $totalLessons += $count;
        }

        // Telegram 通知
        if ($totalLessons > 0) {
            app(TelegramService::class)->send(
                "📚 *週教訓萃取完成* ({$dateFrom}~{$dateTo})\n萃取 {$totalLessons} 條通用教訓"
            );
        }

        return self::SUCCESS;
    }

    private function extractForMode(string $mode, string $dateFrom, string $dateTo): int
    {
        $reviews = DailyReview::where('mode', $mode)
            ->whereBetween('trade_date', [$dateFrom, $dateTo])
            ->orderBy('trade_date')
            ->get();

        if ($reviews->isEmpty()) {
            $this->warn("[{$mode}] 無檢討報告，跳過");
            return 0;
        }

        $this->info("[{$mode}] 找到 {$reviews->count()} 份檢討報告");

        // 合併所有報告
        $combined = $reviews->map(function ($r) {
            return "### {$r->trade_date->format('Y-m-d')}（{$r->candidates_count} 檔）\n{$r->report}";
        })->implode("\n\n---\n\n");

        $modeLabel = $mode === 'overnight' ? '隔日沖' : '當沖';
        $dayCount  = $reviews->count();

        $prompt = <<<PROMPT
以下是本週 {$dayCount} 天的台股{$modeLabel}交易檢討報告。請從整週數據中萃取結構化教訓，供未來 AI 選股參考。

## 本週檢討報告
{$combined}

## 萃取規則
- **最多 5 條**，只留最重要、最有統計支撐的教訓
- 必須是**跨多日重複出現**的模式，單日單一個股的特例不算
- 每條教訓必須標註「本週 N 天/N 次出現此模式」以及相關勝率或虧損統計
- **禁止在 content 中提到具體股票名稱或代號**，用「該類型標的」「此類個股」等通用描述
- **禁止提到具體日期**，用「本週」「多日」等取代
- 忽略籠統的建議（例如「要注意風險」「需要更謹慎」）
- 每條教訓 type 分類：
  - `screening`：選股階段的教訓
  - `entry`：進場策略的教訓（建議買入價、進場條件）
  - `exit`：出場策略的教訓（目標價、停損設定）
  - `market`：大盤/產業面的教訓
PROMPT;

        if ($mode === 'intraday') {
            $prompt .= <<<EXTRA

  - `calibration`：開盤校準的教訓（開盤數據如何判讀）
- category 可選：breakout, bounce, gap, momentum, sector, volume, price_setting, timing
EXTRA;
        }

        $prompt .= <<<FORMAT

## 回覆格式（JSON array，不要加 markdown 標記）
[
  {
    "type": "screening",
    "category": "volume",
    "content": "本週3天共8檔出現此模式，勝率僅12%：連漲超過5日且量縮的標的隔日反轉機率極高，應自動排除或大幅降低信心分數"
  }
]
FORMAT;

        $this->info("[{$mode}] 呼叫 AI 萃取中...");

        try {
            $response = Http::timeout(90)
                ->withHeaders([
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version'  => '2023-06-01',
                    'content-type'       => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model'      => $this->model,
                    'max_tokens' => 2048,
                    'messages'   => [['role' => 'user', 'content' => $prompt]],
                ]);

            if (!$response->successful()) {
                $this->error("[{$mode}] API 錯誤：{$response->status()}");
                Log::error("ExtractWeeklyLessons API error: " . $response->body());
                return 0;
            }

            $text    = trim($response->json('content.0.text', ''));
            $lessons = $this->parseJson($text);

            if (!is_array($lessons)) {
                $this->error("[{$mode}] 無法解析 JSON");
                Log::error("ExtractWeeklyLessons: JSON parse failed", ['raw' => mb_substr($text, 0, 500)]);
                return 0;
            }

            // 清除該週舊教訓（非 tip）
            $deleted = AiLesson::where('mode', $mode)
                ->where('source', '!=', 'tip')
                ->whereBetween('trade_date', [$dateFrom, $dateTo])
                ->delete();
            if ($deleted > 0) {
                $this->info("[{$mode}] 清除 {$deleted} 條舊教訓");
            }

            $expiresAt  = now()->addDays(7)->toDateString();
            $tradeDate  = $dateTo; // 以週五為教訓日期
            $validTypes = $mode === 'intraday'
                ? ['screening', 'calibration', 'entry', 'exit', 'market']
                : ['screening', 'entry', 'exit', 'market'];
            $count = 0;

            foreach ($lessons as $lesson) {
                if (empty($lesson['content']) || empty($lesson['type'])) continue;
                if (!in_array($lesson['type'], $validTypes)) continue;
                if ($count >= 5) break;

                AiLesson::create([
                    'trade_date' => $tradeDate,
                    'mode'       => $mode,
                    'type'       => $lesson['type'],
                    'category'   => $lesson['category'] ?? null,
                    'content'    => $lesson['content'],
                    'expires_at' => $expiresAt,
                    'source'     => 'weekly',
                ]);
                $count++;
            }

            $this->info("[{$mode}] 萃取完成：{$count} 條教訓");
            Log::info("ExtractWeeklyLessons [{$mode}]: {$dateFrom}~{$dateTo}, {$count} 條教訓");

            return $count;
        } catch (\Exception $e) {
            $this->error("[{$mode}] 萃取失敗：{$e->getMessage()}");
            Log::error("ExtractWeeklyLessons: " . $e->getMessage());
            return 0;
        }
    }

    private function parseJson(string $text): ?array
    {
        $cleaned = preg_replace('/^```json?\s*/i', '', $text);
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);
        $decoded = json_decode(trim($cleaned), true);
        if (is_array($decoded)) return $decoded;

        if (preg_match('/\[[\s\S]*\]/u', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) return $decoded;
        }

        // 嘗試修復截斷 JSON
        if (($pos = strpos($text, '[')) !== false) {
            $partial = substr($text, $pos);
            $last = strrpos($partial, '},');
            if ($last !== false) {
                $fixed = substr($partial, 0, $last + 1) . ']';
                $decoded = json_decode($fixed, true);
                if (is_array($decoded)) return $decoded;
            }
        }

        return null;
    }
}

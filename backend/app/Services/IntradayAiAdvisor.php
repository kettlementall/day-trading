<?php

namespace App\Services;

use App\Models\AiLesson;
use App\Models\Candidate;
use App\Models\CandidateMonitor;
use App\Models\DailyQuote;
use App\Models\IntradaySnapshot;
use App\Models\UsMarketIndex;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IntradayAiAdvisor
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key', '');
        $this->model = config('services.anthropic.intraday_model', 'claude-sonnet-4-6');
    }

    /**
     * 09:05 AI 開盤校準（取代 MorningScreener）
     *
     * @return array<string, array>  keyed by symbol: {approved, reason?, strategy_override?, adjusted_support, adjusted_resistance, entry_conditions, notes}
     */
    public function openingCalibration(string $date, Collection $candidates, Collection $snapshots): array
    {
        if ($candidates->isEmpty()) {
            return [];
        }

        if (!$this->apiKey) {
            Log::warning('IntradayAiAdvisor: API key 未設定，使用 fallback');
            return $this->fallbackCalibration($candidates, $snapshots);
        }

        $prompt = $this->buildCalibrationPrompt($date, $candidates, $snapshots);

        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $this->model,
                    'max_tokens' => 4096,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

            if (!$response->successful()) {
                Log::error('IntradayAiAdvisor calibration API error: ' . $response->body());
                return $this->fallbackCalibration($candidates, $snapshots);
            }

            $text = $response->json('content.0.text', '');
            $result = $this->parseJsonResponse($text);

            if (!is_array($result)) {
                Log::error('IntradayAiAdvisor: 無法解析校準回應');
                return $this->fallbackCalibration($candidates, $snapshots);
            }

            // 轉為 symbol keyed map
            $map = [];
            foreach ($result as $item) {
                if (isset($item['symbol'])) {
                    $map[$item['symbol']] = $item;
                }
            }

            return $map;
        } catch (\Exception $e) {
            Log::error('IntradayAiAdvisor calibration: ' . $e->getMessage());
            return $this->fallbackCalibration($candidates, $snapshots);
        }
    }

    /**
     * 每 30 分鐘 AI 滾動判斷
     *
     * @return array  {action: hold|exit|skip|entry, notes, adjustments?: {target, stop}}
     */
    public function rollingAdvice(string $date, CandidateMonitor $monitor, Collection $recentSnapshots): array
    {
        $fallback = ['action' => 'hold', 'notes' => 'AI 不可用，維持現狀', 'adjustments' => null];

        if (!$this->apiKey) {
            return $fallback;
        }

        $candidate = $monitor->candidate;
        $stock = $candidate->stock;

        $prompt = $this->buildRollingPrompt($date, $monitor, $candidate, $stock, $recentSnapshots);

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $this->model,
                    'max_tokens' => 500,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

            if (!$response->successful()) {
                Log::error("IntradayAiAdvisor rolling error for {$stock->symbol}: " . $response->body());
                return $fallback;
            }

            $text = $response->json('content.0.text', '');
            $result = $this->parseJsonResponse($text);

            if (!is_array($result) || !isset($result['action'])) {
                return $fallback;
            }

            return $result;
        } catch (\Exception $e) {
            Log::error("IntradayAiAdvisor rolling {$stock->symbol}: " . $e->getMessage());
            return $fallback;
        }
    }

    // ===== Fallback =====

    /**
     * Fallback 校準：使用 MorningScreener 的 4 條規則
     */
    private function fallbackCalibration(Collection $candidates, Collection $snapshots): array
    {
        $snapshotMap = $snapshots->groupBy(fn($s) => $s->stock_id);
        $results = [];

        foreach ($candidates as $candidate) {
            $stock = $candidate->stock;
            $stockSnapshots = $snapshotMap->get($stock->id, collect());
            $latest = $stockSnapshots->sortByDesc('snapshot_time')->first();

            if (!$latest) {
                $results[$stock->symbol] = [
                    'symbol' => $stock->symbol,
                    'approved' => false,
                    'reason' => '無開盤數據',
                ];
                continue;
            }

            $score = 0;
            $notes = [];

            // 規則 1：預估量爆發 >= 1.5x
            if ((float) $latest->estimated_volume_ratio >= 1.5) {
                $score += 30;
                $notes[] = sprintf('量比 %.1fx ✓', $latest->estimated_volume_ratio);
            }

            // 規則 2：開盤漲幅 2-5%
            $openGap = (float) $latest->open_change_percent;
            if ($openGap >= 2 && $openGap <= 5) {
                $score += 25;
                $notes[] = sprintf('開盤 +%.1f%% ✓', $openGap);
            }

            // 規則 3：現價 > 開盤高（簡化為現價 > 開盤價）
            if ((float) $latest->current_price > (float) $latest->open) {
                $score += 25;
                $notes[] = '價格走強 ✓';
            }

            // 規則 4：外盤比 > 55%
            if ((float) $latest->external_ratio > 55) {
                $score += 20;
                $notes[] = sprintf('外盤 %.0f%% ✓', $latest->external_ratio);
            }

            // 否決：跳空 > 7%
            if ($openGap > 7) {
                $results[$stock->symbol] = [
                    'symbol' => $stock->symbol,
                    'approved' => false,
                    'reason' => sprintf('跳空過大 +%.1f%%，隔日沖風險', $openGap),
                ];
                continue;
            }

            // 至少通過量能 + 3/4 規則
            $approved = $score >= 75; // 量能(30) + 至少2個25分規則 + 外盤(20) = 75+

            $results[$stock->symbol] = [
                'symbol' => $stock->symbol,
                'approved' => $approved,
                'reason' => $approved ? null : '規則式校準未通過（分數 ' . $score . '）',
                'adjusted_support' => $candidate->reference_support,
                'adjusted_resistance' => $candidate->reference_resistance,
                'entry_conditions' => [
                    'min_volume_ratio' => 1.5,
                    'min_external_ratio' => 55,
                ],
                'notes' => 'Fallback 規則式：' . implode('、', $notes),
            ];
        }

        return $results;
    }

    // ===== Prompt Builders =====

    private function buildCalibrationPrompt(string $date, Collection $candidates, Collection $snapshots): string
    {
        $snapshotMap = $snapshots->groupBy(fn($s) => $s->stock_id);

        // 候選標的 TSV
        $lines = [];
        foreach ($candidates as $c) {
            $stock = $c->stock;
            $stockSnaps = $snapshotMap->get($stock->id, collect());
            $latest = $stockSnaps->sortByDesc('snapshot_time')->first();

            $lines[] = implode("\t", [
                $stock->symbol,
                $stock->name,
                $c->score,
                $c->intraday_strategy ?? '-',
                $c->reference_support ?? '-',
                $c->reference_resistance ?? '-',
                $latest ? $latest->open : '-',
                $latest ? $latest->current_price : '-',
                $latest ? $latest->estimated_volume_ratio : '-',
                $latest ? $latest->external_ratio : '-',
                $latest ? $latest->open_change_percent : '-',
            ]);
        }

        $header = "代號\t名稱\t分數\t策略\t支撐\t壓力\t開盤價\t現價\t量比\t外盤%\t開盤漲幅%";
        $tsv = $header . "\n" . implode("\n", $lines);

        // 近 5 日 K 線
        $klineLines = [];
        foreach ($candidates as $c) {
            $quotes = DailyQuote::where('stock_id', $c->stock_id)
                ->where('date', '<', $date)
                ->orderByDesc('date')
                ->limit(5)
                ->get()->reverse();

            foreach ($quotes as $q) {
                $klineLines[] = implode("\t", [
                    $c->stock->symbol, $q->date->format('m/d'),
                    $q->open, $q->high, $q->low, $q->close, $q->volume,
                ]);
            }
        }
        $klineTsv = "代號\t日期\t開\t高\t低\t收\t量\n" . implode("\n", $klineLines);

        $lessonsSection = AiLesson::getIntradayLessons();
        $usMarketSection = UsMarketIndex::getSummary($date);

        return <<<PROMPT
你是台股當沖 AI 助手。現在是 {$date} 09:05，開盤剛滿 5 分鐘。
以下是 {$date} AI 選出的候選標的及其開盤數據。K 線資料截至前一交易日，請用實際日期描述。

{$usMarketSection}

## 候選標的 + 開盤數據
{$tsv}

## 近 5 日 K 線
{$klineTsv}

{$lessonsSection}

## 任務
根據開盤數據，對每檔標的做校準分級：

| 等級 | 條件 | 動作 |
|------|------|------|
| A（強力推薦） | score高 + 前日漲停或強勢 + est_vol>3 + ext_ratio>70% | 全額進場 |
| B（標準進場） | score中上 + 盤中走勢確認 | 半倉進場 |
| C（觀察） | score尚可但有矛盾訊號 | 紙上交易追蹤，不實際進場 |
| D（放棄） | 明確轉弱訊號（低開量縮、開盤即最高、跳空過大等） | 不進場 |

等級 A/B/C 的標的，請設定進場條件（C 級用於紙上追蹤）。

## 回覆格式（JSON array，不要加 markdown 標記）
[
  {
    "symbol": "2460",
    "grade": "A",
    "strategy_override": null,
    "adjusted_support": 29.5,
    "adjusted_resistance": 30.5,
    "entry_conditions": {
      "min_volume_ratio": 1.5,
      "min_external_ratio": 55,
      "price_rule": "站穩 30.0 以上"
    },
    "notes": "前日漲停鎖住，開盤量比4.2，外盤比78%，強力推薦"
  },
  {
    "symbol": "6206",
    "grade": "D",
    "reason": "低開量縮，開盤即最高，放棄",
    "notes": null
  }
]
PROMPT;
    }

    private function buildRollingPrompt(string $date, CandidateMonitor $monitor, Candidate $candidate, $stock, Collection $recentSnapshots): string
    {
        // 快照軌跡
        $snapLines = [];
        foreach ($recentSnapshots as $s) {
            $snapLines[] = implode("\t", [
                $s->snapshot_time->format('H:i'),
                $s->current_price,
                $s->accumulated_volume,
                $s->estimated_volume_ratio,
                $s->external_ratio,
                $s->change_percent,
            ]);
        }
        $snapTsv = "時間\t現價\t累積量\t量比\t外盤%\t漲跌%\n" . implode("\n", $snapLines);

        // 持有狀態
        $statusInfo = "狀態: {$monitor->status}";
        if ($monitor->status === CandidateMonitor::STATUS_HOLDING && $monitor->entry_price) {
            $latest = $recentSnapshots->last();
            $profitPct = $latest
                ? round(((float) $latest->current_price - (float) $monitor->entry_price) / (float) $monitor->entry_price * 100, 2)
                : 0;
            $statusInfo .= sprintf(
                " | 進場 %.2f @ %s | 損益 %+.1f%% | 目標 %.2f | 停損 %.2f",
                $monitor->entry_price,
                $monitor->entry_time?->format('H:i') ?? '-',
                $profitPct,
                $monitor->current_target ?? 0,
                $monitor->current_stop ?? 0
            );
        }

        $lessonsSection = AiLesson::getIntradayLessons(10);

        return <<<PROMPT
你是台股當沖 AI 助手。以下是 {$stock->symbol} {$stock->name} 的最近 30 分鐘快照。

## 策略: {$candidate->intraday_strategy}
## {$statusInfo}

## 快照軌跡
{$snapTsv}

{$lessonsSection}

## 任務
根據走勢判斷下一步動作：
- `hold`：維持現狀（可附帶調整 target/stop）
- `exit`：建議出場（持有中才適用）
- `skip`：建議放棄觀望（觀望中才適用）
- `entry`：建議進場（觀望中才適用）

## 回覆格式（JSON，不要加 markdown 標記）
{
  "action": "hold",
  "notes": "量能從 2.1x 降至 1.6x，上方壓力未破",
  "adjustments": {
    "target": 30.65,
    "stop": null
  }
}
PROMPT;
    }

    // ===== 解析 =====

    private function parseJsonResponse(string $text): mixed
    {
        $text = trim($text);
        $text = preg_replace('/^```json?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        return json_decode($text, true);
    }
}

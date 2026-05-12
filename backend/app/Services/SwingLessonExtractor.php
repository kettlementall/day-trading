<?php

namespace App\Services;

use App\Models\AiLesson;
use App\Models\DailyQuote;
use App\Models\DailyReview;
use App\Models\SwingPosition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 從本週使用者關閉的短線持倉 + AI 建議軌跡 + 出場後 5 日股價，
 * 萃取結構化教訓寫入 ai_lessons (mode=swing)，注入未來 SwingScreener / SwingPositionUpdate prompt。
 *
 * 排程：每週日 17:00（routes/console.php），有效期 14 天，最多 5 條。
 */
class SwingLessonExtractor
{
    private const LESSON_EXPIRES_DAYS = 14;
    private const MAX_LESSONS = 5;
    private const FORWARD_DAYS = 5;
    private const VALID_TYPES = ['screening', 'entry', 'exit', 'market'];

    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key', '');
        $this->model  = config('services.anthropic.model', 'claude-opus-4-6');
    }

    /**
     * 萃取單週教訓並寫入。回傳 ['written' => N, 'cases' => N, 'skipped_reason' => ?string]。
     *
     * @param  string  $weekEnd  以週日（含）為週末錨點
     * @param  bool    $dryRun   true 時不動 DB、不清舊資料、只 print prompt 與 LLM 回覆
     */
    public function extract(string $weekEnd, bool $dryRun = false, ?callable $log = null): array
    {
        $log ??= fn (string $m) => Log::info($m);
        $sunday = CarbonImmutable::parse($weekEnd)->endOfDay();
        $monday = $sunday->subDays(6)->startOfDay();
        $forwardCutoff = $sunday->subDays(self::FORWARD_DAYS); // 只納入有完整 5 日 forward 的持倉

        $positions = SwingPosition::with(['stock', 'candidate'])
            ->whereIn('status', [SwingPosition::STATUS_CLOSED, SwingPosition::STATUS_STOPPED])
            ->whereBetween('exit_date', [$monday->toDateString(), $sunday->toDateString()])
            ->whereDate('exit_date', '<=', $forwardCutoff->toDateString())
            ->get();

        if ($positions->isEmpty()) {
            $log("本週 {$monday->toDateString()}~{$sunday->toDateString()} 無已完成 forward window 的平倉持倉，跳過");
            return ['written' => 0, 'cases' => 0, 'skipped_reason' => 'no_eligible_positions'];
        }

        $log("找到 {$positions->count()} 筆已關閉持倉，開始計算 forward window 與聚合");

        $cases = $positions->map(fn (SwingPosition $p) => $this->buildCase($p))->filter()->values();
        $aggregate = $this->buildAggregate($cases);

        $prompt = $this->buildPrompt($monday, $sunday, $cases, $aggregate, $weekEnd);

        if ($dryRun) {
            $log("=== DRY RUN: PROMPT ===\n{$prompt}");
        }

        if (!$this->apiKey) {
            $log('未設定 ANTHROPIC_API_KEY，跳過');
            return ['written' => 0, 'cases' => $cases->count(), 'skipped_reason' => 'no_api_key'];
        }

        try {
            $response = Http::timeout(90)
                ->withHeaders([
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model'      => $this->model,
                    'max_tokens' => 2048,
                    'messages'   => [['role' => 'user', 'content' => $prompt]],
                ]);

            if (!$response->successful()) {
                $log('Anthropic API 錯誤：' . $response->status() . ' ' . mb_substr($response->body(), 0, 300));
                return ['written' => 0, 'cases' => $cases->count(), 'skipped_reason' => 'api_error'];
            }

            $text = trim($response->json('content.0.text', ''));
            if ($dryRun) {
                $log("=== DRY RUN: LLM RESPONSE ===\n{$text}");
            }

            $lessons = $this->parseJson($text);
            if (!is_array($lessons) || empty($lessons)) {
                $log('JSON 解析失敗或為空，保留上週教訓');
                return ['written' => 0, 'cases' => $cases->count(), 'skipped_reason' => 'parse_failed'];
            }
        } catch (\Throwable $e) {
            $log('呼叫失敗：' . $e->getMessage());
            return ['written' => 0, 'cases' => $cases->count(), 'skipped_reason' => 'exception'];
        }

        $valid = collect($lessons)
            ->filter(fn ($l) => !empty($l['content']) && !empty($l['type']) && in_array($l['type'], self::VALID_TYPES, true))
            ->reject(fn ($l) => preg_match('/\b\d{4}\b/', $l['content']) === 1) // 阻擋 4 碼代號洩漏
            ->take(self::MAX_LESSONS)
            ->values();

        if ($valid->isEmpty()) {
            $log('過濾後無有效教訓（type 不在白名單 / content 含代號）');
            return ['written' => 0, 'cases' => $cases->count(), 'skipped_reason' => 'no_valid_after_filter'];
        }

        if ($dryRun) {
            $log("=== DRY RUN: WOULD WRITE {$valid->count()} LESSONS ===");
            foreach ($valid as $l) {
                $log("- [{$l['type']}/{$l['category']}] {$l['content']}");
            }
            return ['written' => 0, 'cases' => $cases->count(), 'skipped_reason' => 'dry_run', 'would_write' => $valid->count()];
        }

        // 清除同週舊的非-tip 教訓（保留人工 tip）
        $deleted = AiLesson::where('mode', 'swing')
            ->where('source', '!=', 'tip')
            ->whereBetween('trade_date', [$monday->toDateString(), $sunday->toDateString()])
            ->delete();
        if ($deleted > 0) {
            $log("清除 {$deleted} 條舊 swing 教訓");
        }

        $expiresAt = CarbonImmutable::now()->addDays(self::LESSON_EXPIRES_DAYS)->toDateString();
        $tradeDate = $sunday->toDateString();
        foreach ($valid as $l) {
            AiLesson::create([
                'trade_date' => $tradeDate,
                'mode'       => 'swing',
                'type'       => $l['type'],
                'category'   => $l['category'] ?? null,
                'content'    => $l['content'],
                'expires_at' => $expiresAt,
                'source'     => 'weekly',
            ]);
        }
        $log("寫入 {$valid->count()} 條 swing 教訓，有效期至 {$expiresAt}");

        return ['written' => $valid->count(), 'cases' => $cases->count(), 'skipped_reason' => null];
    }

    /**
     * 把單筆已關閉持倉壓縮成 prompt 用的結構：
     * 含進出價、持有天數、AI 最後 3 筆建議、出場後 5 日後續走勢、論點。
     */
    private function buildCase(SwingPosition $position): ?array
    {
        $entry = (float) $position->entry_price;
        $exit  = (float) $position->exit_price;
        if ($entry <= 0 || $exit <= 0) {
            return null;
        }
        $realizedPct = round(($exit - $entry) / $entry * 100, 2);

        $forward = DailyQuote::where('stock_id', $position->stock_id)
            ->where('date', '>', $position->exit_date)
            ->orderBy('date')
            ->limit(self::FORWARD_DAYS)
            ->get(['date', 'high', 'low', 'close']);

        $fiveDayPct = null;
        $maxPct = null;
        $minPct = null;
        if ($forward->isNotEmpty()) {
            $lastClose = (float) $forward->last()->close;
            $fiveDayPct = round(($lastClose - $exit) / $exit * 100, 2);
            $maxHigh = (float) $forward->max('high');
            $minLow  = (float) $forward->min('low');
            $maxPct = round(($maxHigh - $exit) / $exit * 100, 2);
            $minPct = round(($minLow - $exit) / $exit * 100, 2);
        }

        $log = is_array($position->advice_log) ? $position->advice_log : [];
        $lastAdvices = array_slice($log, -3);
        $lastAction = $lastAdvices ? ($lastAdvices[count($lastAdvices) - 1]['action'] ?? null) : null;
        $userVsAi = in_array($lastAction, ['hold', 'trim'], true) && $position->status === SwingPosition::STATUS_CLOSED;

        $candidate = $position->candidate;
        $thesisTitle = $candidate?->swing_thesis['title'] ?? null;
        $strategy = $candidate?->swing_strategy ?? '-';
        $benefitLevel = $candidate?->swing_thesis['benefit_level'] ?? null;

        $holdingDays = ($position->entry_date && $position->exit_date)
            ? (int) $position->entry_date->diffInDays($position->exit_date) : null;

        return [
            'industry' => $position->stock?->industry ?? '未分類',
            'strategy' => $strategy,
            'thesis_title' => $thesisTitle,
            'benefit_level' => $benefitLevel,
            'holding_days' => $holdingDays,
            'realized_pct' => $realizedPct,
            'exit_reason' => $position->exit_reason ?? 'unknown',
            'exit_note' => $position->exit_note ? mb_substr($position->exit_note, 0, 60) : null,
            'last_ai_actions' => array_map(fn ($a) => $a['action'] ?? '-', $lastAdvices),
            'last_ai_reasoning' => $lastAdvices ? mb_substr($lastAdvices[count($lastAdvices) - 1]['reasoning'] ?? '', 0, 80) : null,
            'user_vs_ai_divergence' => $userVsAi,
            'forward_5d_pct' => $fiveDayPct,
            'forward_max_pct' => $maxPct,
            'forward_min_pct' => $minPct,
            'status' => $position->status,
        ];
    }

    private function buildAggregate(Collection $cases): array
    {
        $total = $cases->count();
        if ($total === 0) {
            return ['total' => 0];
        }
        $wins = $cases->where('realized_pct', '>', 0)->count();
        $avgHolding = round($cases->whereNotNull('holding_days')->avg('holding_days') ?? 0, 1);
        $avgReturn  = round($cases->avg('realized_pct'), 2);
        $reasonCounts = $cases->groupBy('exit_reason')->map->count()->toArray();
        $divergenceCount = $cases->where('user_vs_ai_divergence', true)->count();

        return [
            'total' => $total,
            'win_rate_pct' => round($wins / $total * 100, 1),
            'avg_holding_days' => $avgHolding,
            'avg_return_pct' => $avgReturn,
            'exit_reasons' => $reasonCounts,
            'user_vs_ai_divergence_count' => $divergenceCount,
        ];
    }

    private function buildPrompt(CarbonImmutable $monday, CarbonImmutable $sunday, Collection $cases, array $aggregate, string $weekEnd): string
    {
        $sampleNote = $cases->count() < 3
            ? '【樣本極少警告】本週案例 < 3，請只輸出 type=market 大方向教訓最多 2 條，避免過擬合。'
            : '';

        // 個股代號脫敏，用 [industry-序號] 代稱
        $industryCounts = [];
        $caseLines = $cases->map(function ($c) use (&$industryCounts) {
            $industry = $c['industry'] ?? '未分類';
            $industryCounts[$industry] = ($industryCounts[$industry] ?? 0) + 1;
            $code = sprintf('[%s-%d]', $industry, $industryCounts[$industry]);

            $forward = $c['forward_5d_pct'] !== null
                ? sprintf('forward_5d=%+.2f%% max=%+.2f%% min=%+.2f%%', $c['forward_5d_pct'], $c['forward_max_pct'], $c['forward_min_pct'])
                : 'forward=資料未齊';
            $actions = $c['last_ai_actions'] ? implode('→', $c['last_ai_actions']) : '無';
            $note = $c['exit_note'] ? " 註:{$c['exit_note']}" : '';
            $divergence = $c['user_vs_ai_divergence'] ? ' ⚠use_vs_ai_divergence' : '';

            return sprintf(
                "%s strategy=%s thesis=%s(%s) hold=%s日 realized=%+.2f%% exit_reason=%s last_ai=[%s]%s | %s%s",
                $code,
                $c['strategy'],
                $c['thesis_title'] ?? '-',
                $c['benefit_level'] ?? '-',
                $c['holding_days'] ?? '?',
                $c['realized_pct'],
                $c['exit_reason'],
                $actions,
                $divergence,
                $forward,
                $note
            );
        })->implode("\n");

        $aggLines = "總筆數={$aggregate['total']}，勝率={$aggregate['win_rate_pct']}%，"
            . "平均持有={$aggregate['avg_holding_days']}日，平均報酬={$aggregate['avg_return_pct']}%，"
            . 'exit_reason分布=' . json_encode($aggregate['exit_reasons'], JSON_UNESCAPED_UNICODE)
            . "，user_vs_ai_divergence={$aggregate['user_vs_ai_divergence_count']}";

        $weekReview = DailyReview::where('mode', 'swing')
            ->whereBetween('trade_date', [$monday->toDateString(), $sunday->toDateString()])
            ->orderByDesc('trade_date')
            ->limit(2)
            ->get();
        $reviewBlock = $weekReview->isEmpty()
            ? '（本週無短線檢討報告）'
            : $weekReview->map(fn ($r) => "## {$r->trade_date->format('Y-m-d')}\n" . mb_substr((string) $r->report, 0, 1200))->implode("\n\n");

        return <<<PROMPT
你是台股短線策略檢討顧問。以下是本週使用者關閉的短線持倉，請萃取**最多 {$cases->count()} 筆樣本能支撐**、可在未來 AI 選股／滾動建議直接套用的結構化教訓。

# 本週統計（{$monday->toDateString()} ~ {$sunday->toDateString()}）
{$aggLines}

{$sampleNote}

# 個別持倉（個股代號已脫敏為 [產業-序號]）
{$caseLines}

# 本週短線檢討報告（組合層級上下文，可參考）
{$reviewBlock}

# 重點分析角度（鎖死思考方向，逐項評估）
1. exit_reason=`take_profit_manual` 之後股價繼續漲（forward_5d > 0）vs 拉回（forward_5d ≤ 0）：哪種模式重複出現？是否暗示 AI 預設 target 過遠 / 過近？
2. exit_reason=`cut_loss_manual` 之後反彈（forward_5d > 0）vs 續跌（forward_5d ≤ 0）：殺低風險高還是停損及時？
3. `user_vs_ai_divergence=true` 案例（AI 建議 hold/trim 但使用者全平倉），forward_5d 數據說明 AI 還是使用者判斷正確？是否該調整 AI 預設持有區間？
4. 哪種 strategy（trend_pullback / trend_follow / base_breakout）本週命中率明顯偏低？
5. 哪種 thesis benefit_level（core / secondary / watch）對應表現最差？是否預示 watch 級不該進場？

# 萃取規則（嚴格）
- **最多 5 條**，只留最有統計支撐的教訓
- 必須是**跨多檔重複出現**的模式，單一案例的特例不算
- 每條 content 必須標註「本週 N 檔 / N 次出現此模式」+ 勝率或平均報酬等量化證據
- **禁止**在 content 中提到具體股票名稱、4 碼代號、具體日期；用「該類型標的」「此類個股」「本週」等通用描述
- 忽略籠統的建議（「要更謹慎」「注意風險」）
- 每條 type 限定 [screening | entry | exit | market]
- category 建議白名單：thesis_validation | exit_timing | divergence_from_ai | strategy_pattern | sector_rotation | valuation_trap | time_stop

# 輸出格式（JSON array，不加 markdown 包裹）
[
  {
    "type": "exit",
    "category": "divergence_from_ai",
    "content": "本週 3 檔 AI 建議 hold 但使用者在 +12-18% 主動停利，出場後 5 日平均續漲 +3.5%（最大 +7%），暗示對 trend_pullback 策略 AI 預設目標過遠；未來此類持倉若已達 +10% 應由 hold 轉 trim 上移停損"
  }
]
PROMPT;
    }

    private function parseJson(string $text): ?array
    {
        $cleaned = preg_replace('/^```json?\s*/i', '', $text);
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);
        $decoded = json_decode(trim($cleaned), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\[[\s\S]*\]/u', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // 嘗試修復截斷
        if (($pos = strpos($text, '[')) !== false) {
            $partial = substr($text, $pos);
            $last = strrpos($partial, '},');
            if ($last !== false) {
                $fixed = substr($partial, 0, $last + 1) . ']';
                $decoded = json_decode($fixed, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return null;
    }
}

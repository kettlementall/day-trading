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
     * @return array<string, array>  keyed by symbol
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
            $response = Http::timeout(120)
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
     * 滾動 AI 判斷（依時段動態頻率）
     *
     * @param  Collection  $allSnapshots  當日所有快照（用於 5 分 K 聚合）
     * @return array  {action: hold|exit|skip|entry, notes, adjustments?: {target, stop, support, resistance}}
     */
    public function rollingAdvice(string $date, CandidateMonitor $monitor, Collection $allSnapshots): array
    {
        $fallback = ['action' => 'hold', 'notes' => 'AI 不可用，維持現狀', 'adjustments' => null];

        if (!$this->apiKey) {
            return $fallback;
        }

        $candidate = $monitor->candidate;
        $stock = $candidate->stock;

        $systemPrompt = $this->buildRollingSystemPrompt($date, $monitor, $candidate, $stock);
        $userMessage  = $this->buildRollingUserMessage($monitor, $candidate, $stock, $allSnapshots);

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'anthropic-beta'    => 'prompt-caching-2024-07-31',
                    'content-type'      => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model'      => $this->model,
                    'max_tokens' => 512,
                    'system'     => [
                        [
                            'type'          => 'text',
                            'text'          => $systemPrompt,
                            'cache_control' => ['type' => 'ephemeral'],
                        ],
                    ],
                    'messages' => [
                        ['role' => 'user', 'content' => $userMessage],
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

    /**
     * 緊急 AI 判斷（HOLDING 中出現急殺訊號時立即觸發）
     *
     * @param  Collection  $allSnapshots  當日所有快照
     */
    public function emergencyAdvice(string $date, CandidateMonitor $monitor, Collection $allSnapshots, string $reason): array
    {
        $fallback = ['action' => 'hold', 'notes' => 'AI 不可用，維持現狀', 'adjustments' => null];

        if (!$this->apiKey) {
            return $fallback;
        }

        $candidate = $monitor->candidate;
        $stock = $candidate->stock;

        $systemPrompt = $this->buildRollingSystemPrompt($date, $monitor, $candidate, $stock);
        $userMessage  = $this->buildRollingUserMessage($monitor, $candidate, $stock, $allSnapshots, $reason);

        try {
            $response = Http::timeout(20)
                ->withHeaders([
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'anthropic-beta'    => 'prompt-caching-2024-07-31',
                    'content-type'      => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model'      => $this->model,
                    'max_tokens' => 256,
                    'system'     => [
                        [
                            'type'          => 'text',
                            'text'          => $systemPrompt,
                            'cache_control' => ['type' => 'ephemeral'],
                        ],
                    ],
                    'messages' => [
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                ]);

            if (!$response->successful()) {
                Log::error("IntradayAiAdvisor emergency error for {$stock->symbol}: " . $response->body());
                return $fallback;
            }

            $text = $response->json('content.0.text', '');
            $result = $this->parseJsonResponse($text);

            if (!is_array($result) || !isset($result['action'])) {
                return $fallback;
            }

            return $result;
        } catch (\Exception $e) {
            Log::error("IntradayAiAdvisor emergency {$stock->symbol}: " . $e->getMessage());
            return $fallback;
        }
    }

    // ===== Fallback =====

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

            if ((float) $latest->estimated_volume_ratio >= 1.5) {
                $score += 30;
                $notes[] = sprintf('量比 %.1fx ✓', $latest->estimated_volume_ratio);
            }

            $openGap = (float) $latest->open_change_percent;
            if ($openGap >= 2 && $openGap <= 5) {
                $score += 25;
                $notes[] = sprintf('開盤 +%.1f%% ✓', $openGap);
            }

            if ((float) $latest->current_price > (float) $latest->open) {
                $score += 25;
                $notes[] = '價格走強 ✓';
            }

            if ((float) $latest->external_ratio > 55) {
                $score += 20;
                $notes[] = sprintf('外盤 %.0f%% ✓', $latest->external_ratio);
            }

            if ($openGap > 7) {
                $results[$stock->symbol] = [
                    'symbol' => $stock->symbol,
                    'approved' => false,
                    'reason' => sprintf('跳空過大 +%.1f%%，隔日沖風險', $openGap),
                ];
                continue;
            }

            $approved = $score >= 75;

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

    /**
     * Rolling advice 靜態系統 prompt（每日每股首次後快取）
     * 包含：5日K線、開盤校準結果、AiLesson
     */
    private function buildRollingSystemPrompt(string $date, CandidateMonitor $monitor, Candidate $candidate, $stock): string
    {
        // 5日K線
        $quotes = DailyQuote::where('stock_id', $candidate->stock_id)
            ->where('date', '<', $date)
            ->orderByDesc('date')
            ->limit(5)
            ->get()->reverse();

        $klineLines = [];
        foreach ($quotes as $q) {
            $klineLines[] = sprintf('%s 開%.2f 高%.2f 低%.2f 收%.2f 量%d張 漲%+.2f%%',
                $q->date->format('m/d'),
                (float) $q->open,
                (float) $q->high,
                (float) $q->low,
                (float) $q->close,
                (int) round($q->volume / 1000),
                (float) ($q->change_percent ?? 0)
            );
        }
        $klineSection = implode("\n", $klineLines) ?: '無K線資料';

        // 開盤校準結果
        $cal = is_array($monitor->ai_calibration) ? $monitor->ai_calibration : [];
        $calGrade = $cal['grade'] ?? '-';
        $calSupport = $monitor->current_stop ?? $candidate->reference_support ?? '-';
        $calResistance = $monitor->current_target ?? $candidate->reference_resistance ?? '-';
        $calNotes = $cal['notes'] ?? '-';
        $minVolRatio = $cal['entry_conditions']['min_volume_ratio'] ?? 1.5;
        $minExtRatio = $cal['entry_conditions']['min_external_ratio'] ?? 55;

        $lessonsSection = AiLesson::getIntradayLessons(10);
        $industry = $stock->industry ?? '';
        $strategy = $candidate->intraday_strategy ?? 'momentum';

        return <<<SYSTEM
你是台股當沖 AI 助手，正在協助管理 {$stock->symbol} {$stock->name}（{$industry}）的盤中倉位。

## 時間規則
- 當沖部位必須在 13:30 收盤前平倉
- 距收盤 ≤ 30 分鐘（尾盤）：不建議新進場；持有中應積極決斷，傾向出場而非繼續觀望
- 距收盤 ≤ 15 分鐘：除非明確獲利且走勢強勁，否則應建議 exit

## 策略: {$strategy}

## 近 5 日 K 線（盤前參考，了解結構）
{$klineSection}

## 開盤校準結果
等級: {$calGrade} | 支撐位: {$calSupport} | 壓力/觸發位: {$calResistance}
進場門檻: 量比 ≥ {$minVolRatio}x，外盤 ≥ {$minExtRatio}%
校準備註: {$calNotes}

{$lessonsSection}
SYSTEM;
    }

    /**
     * Rolling advice 動態用戶訊息（每次都重新計算）
     * 包含：5分K聚合、開盤區間、當前狀態與關鍵位距離、任務
     */
    private function buildRollingUserMessage(CandidateMonitor $monitor, Candidate $candidate, $stock, Collection $allSnapshots, ?string $emergencyReason = null): string
    {
        $candles = $this->aggregateToCandles($allSnapshots);
        $latest = $allSnapshots->sortByDesc('snapshot_time')->first();
        $currentPrice = $latest ? (float) $latest->current_price : 0;
        $dayHigh = $latest ? (float) $latest->high : 0;
        $dayLow = $latest ? (float) $latest->low : 0;

        // 時間壓力
        $now = now()->timezone('Asia/Taipei');
        $currentTime = $now->format('H:i');
        $marketClose = '13:30';
        $minutesLeft = max(0, $now->diffInMinutes(\Carbon\Carbon::parse("today {$marketClose}", 'Asia/Taipei'), false));

        // 5分K表格
        $candleLines = [];
        foreach ($candles as $c) {
            $candleLines[] = sprintf('%s  %.2f  %.2f  %.2f  %.2f  %d張  %.0f%%',
                $c['time'], $c['open'], $c['high'], $c['low'], $c['close'],
                $c['volume_张'], $c['external_ratio']
            );
        }
        $candleHeader = "時段    開       高       低       收       量      外盤%";
        $candleTsv = $candleHeader . "\n" . implode("\n", $candleLines);

        // 開盤區間（取第一根 5 分 K）
        $openingRange = '';
        if (!empty($candles)) {
            $firstCandle = $candles[0];
            $openingRange = sprintf(
                "開盤區間（首根 5 分 K）: 高 %.2f / 低 %.2f | 突破 %.2f → 多方確認 | 跌破 %.2f → 多方失守",
                $firstCandle['high'], $firstCandle['low'],
                $firstCandle['high'], $firstCandle['low']
            );
        }

        // 當前狀態與距離
        $support = (float) ($monitor->current_stop ?? 0);
        $resistance = (float) ($monitor->current_target ?? 0);

        $status = $monitor->status;
        $statusLines = [];
        $taskSection = '';

        if ($status === CandidateMonitor::STATUS_HOLDING && $monitor->entry_price) {
            $entry = (float) $monitor->entry_price;
            $profitPct = $entry > 0 ? round(($currentPrice - $entry) / $entry * 100, 2) : 0;
            $distTarget = $resistance > 0 ? round(($resistance - $currentPrice) / $currentPrice * 100, 2) : 0;
            $distStop = $support > 0 ? round(($currentPrice - $support) / $currentPrice * 100, 2) : 0;
            $distDayHigh = $dayHigh > 0 ? round(($dayHigh - $currentPrice) / $currentPrice * 100, 2) : 0;

            $statusLines[] = sprintf("狀態: 持有中 | 進場 %.2f @ %s | 損益 %+.2f%%",
                $entry, $monitor->entry_time?->format('H:i') ?? '-', $profitPct);
            $statusLines[] = sprintf("目標 %.2f（%+.2f%%）| 停損 %.2f（%.2f%%）| 今日最高 %.2f（距今 %.2f%%）",
                $resistance, $distTarget, $support, $distStop, $dayHigh, $distDayHigh);

            $profitContext = $profitPct >= 2 ? '獲利中' : ($profitPct <= -1 ? '虧損中' : '持平');
            $taskSection = <<<TASK
## 任務（持有中 — {$profitContext}）
1. 走勢是否仍支持持有到目標？是否建議調整目標或收緊停損？
2. 是否出現出場訊號？（明確建議 hold 或 exit）
TASK;
        } else {
            // WATCHING
            $distResistance = $resistance > 0 && $currentPrice > 0
                ? round(($resistance - $currentPrice) / $currentPrice * 100, 2) : 0;
            $distSupport = $support > 0 && $currentPrice > 0
                ? round(($currentPrice - $support) / $currentPrice * 100, 2) : 0;

            $entryTrigger = match ($candidate->intraday_strategy ?? 'momentum') {
                'breakout_fresh', 'momentum' => "突破 {$resistance} → 進場",
                'breakout_retest', 'gap_pullback' => "回測至 {$support} 附近止穩 → 進場",
                'bounce' => "觸及 {$support} 後反彈確認 → 進場",
                default => "突破 {$resistance} → 進場",
            };

            $statusLines[] = sprintf("狀態: 觀望中 | 現價 %.2f | 距支撐 %.2f（%.2f%%）| 距壓力 %.2f（%+.2f%%）",
                $currentPrice, $support, $distSupport, $resistance, $distResistance);
            $statusLines[] = "進場條件: {$entryTrigger} | 今日高低: {$dayHigh} / {$dayLow}";

            $taskSection = <<<TASK
## 任務（觀望中）
1. 當前走勢是否已達或即將達到進場條件？（建議 entry / hold / skip）
2. 支撐位或壓力位是否需根據今日盤中走勢調整？（在 adjustments 中提供 support / resistance）
TASK;
        }

        $statusSection = implode("\n", $statusLines);

        // 緊急觸發說明
        $emergencySection = '';
        if ($emergencyReason) {
            $emergencySection = "\n⚠️ **緊急觸發：{$emergencyReason}** — 請明確回覆 hold 或 exit，不要回覆 hold 而不帶任何調整。\n";
        }

        $timeWarning = $minutesLeft <= 30 ? "⚠️ 尾盤階段，當沖部位必須在收盤前平倉" : '';

        return <<<MSG
## {$stock->symbol} {$stock->name} 盤中狀態
現在時間：{$currentTime}　距收盤：{$minutesLeft}分鐘
{$timeWarning}
{$statusSection}
{$emergencySection}
## 今日 5 分 K
{$candleTsv}

{$openingRange}

{$taskSection}

## 回覆格式（JSON，不要加 markdown 標記）
{
  "action": "hold",
  "notes": "量能從 2.1x 降至 1.6x，支撐有效，繼續持有",
  "adjustments": {
    "target": null,
    "stop": null,
    "support": null,
    "resistance": null
  }
}
MSG;
    }

    /**
     * 將快照聚合為 5 分 K 線
     */
    private function aggregateToCandles(Collection $snapshots, int $periodMinutes = 5): array
    {
        if ($snapshots->isEmpty()) return [];

        $sorted = $snapshots->sortBy('snapshot_time')->values();
        $buckets = [];

        foreach ($sorted as $snap) {
            $time = $snap->snapshot_time;
            $slot = (int) floor((int) $time->format('i') / $periodMinutes) * $periodMinutes;
            $key = $time->format('H') . ':' . str_pad($slot, 2, '0', STR_PAD_LEFT);
            $buckets[$key][] = $snap;
        }

        ksort($buckets);

        $candles = [];
        $prevAccVol = 0;

        foreach ($buckets as $time => $snaps) {
            $first = $snaps[0];
            $last = $snaps[count($snaps) - 1];

            $open = (float) $first->current_price;
            $close = (float) $last->current_price;
            $high = max(array_map(fn($s) => (float) $s->high, $snaps));
            $low = min(array_map(fn($s) => (float) $s->low, $snaps));

            $accVolNow = (int) $last->accumulated_volume;
            $periodVolShares = max(0, $accVolNow - $prevAccVol);
            $prevAccVol = $accVolNow;

            $candles[] = [
                'time'          => $time,
                'open'          => $open,
                'high'          => $high,
                'low'           => $low,
                'close'         => $close,
                'volume_张'     => (int) round($periodVolShares / 1000),
                'external_ratio' => (float) $last->external_ratio,
            ];
        }

        return $candles;
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

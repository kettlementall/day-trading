<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\CandidateMonitor;
use App\Models\IntradaySnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OvernightExitMonitorService
{
    private string $apiKey;
    private string $model;

    public function __construct(private FugleRealtimeClient $fugle)
    {
        $this->apiKey = config('services.anthropic.api_key', '');
        $this->model  = config('services.anthropic.haiku_model', 'claude-haiku-4-5-20251001');
    }

    /**
     * 指定時段的監控執行（9:30 / 10:00 / 10:30 / 11:00）
     *
     * @param  string  $tradeDate  T+1 出場日（YYYY-MM-DD）
     * @param  string  $slot       時段代碼，如 '930'、'1000'
     */
    public function checkTimeSlot(string $tradeDate, string $slot): array
    {
        $summary = ['slot' => $slot, 'checked' => 0, 'target_hit' => 0, 'stop_hit' => 0, 'adjusted' => 0, 'held' => 0, 'exited' => 0];

        // 取得今日隔日沖、AI 選入、監控尚未終止的候選
        $candidates = Candidate::with(['stock', 'monitor'])
            ->where('mode', 'overnight')
            ->where('trade_date', $tradeDate)
            ->where('ai_selected', true)
            ->whereHas('monitor', fn ($q) => $q->whereNotIn('status', CandidateMonitor::TERMINAL_STATUSES))
            ->get();

        if ($candidates->isEmpty()) {
            Log::info("OvernightExitMonitor [{$slot}] {$tradeDate}：無活躍監控標的");
            return $summary;
        }

        // 批次抓 Fugle 快照
        $stocks  = $candidates->map(fn ($c) => $c->stock)->all();
        $quotes  = $this->fugle->fetchQuotes($stocks);

        foreach ($candidates as $candidate) {
            $symbol  = $candidate->stock->symbol;
            $monitor = $candidate->monitor;

            if (!$monitor) {
                continue;
            }

            $quote = $quotes[$symbol] ?? null;
            $summary['checked']++;

            if (!$quote) {
                Log::warning("OvernightExitMonitor [{$slot}] {$symbol}：無 Fugle 快照，跳過");
                continue;
            }

            $currentTarget = (float) $monitor->current_target;
            $currentStop   = (float) $monitor->current_stop;
            $high          = (float) $quote['high'];
            $low           = (float) $quote['low'];

            // ── 已達目標 ──────────────────────────────────────────────
            if ($currentTarget > 0 && $high >= $currentTarget) {
                $this->transition($monitor, CandidateMonitor::STATUS_TARGET_HIT,
                    "{$slot} 盤中最高 {$high} 達到目標 {$currentTarget}");
                $summary['target_hit']++;
                Log::info("OvernightExitMonitor [{$slot}] {$symbol}：目標達成（high={$high}）");
                continue;
            }

            // ── 觸發停損 ──────────────────────────────────────────────
            if ($currentStop > 0 && $low <= $currentStop) {
                $this->transition($monitor, CandidateMonitor::STATUS_STOP_HIT,
                    "{$slot} 盤中最低 {$low} 觸及停損 {$currentStop}");
                $summary['stop_hit']++;
                Log::info("OvernightExitMonitor [{$slot}] {$symbol}：停損觸發（low={$low}）");
                continue;
            }

            // ── AI 滾動判斷 ───────────────────────────────────────────
            $advice = $this->askHaiku($slot, $candidate, $monitor, $quote);

            match ($advice['action']) {
                'exit' => $this->handleExit($monitor, $slot, $advice, $summary),
                'adjust' => $this->handleAdjust($monitor, $slot, $advice, $summary),
                default => $this->handleHold($monitor, $slot, $advice, $summary),
            };

            Log::info("OvernightExitMonitor [{$slot}] {$symbol}：{$advice['action']} — {$advice['reasoning']}");
        }

        return $summary;
    }

    // -------------------------------------------------------------------------

    private function transition(CandidateMonitor $monitor, string $toStatus, string $reason): void
    {
        $monitor->logTransition($monitor->status, $toStatus, $reason);
        $monitor->status = $toStatus;
        $monitor->save();
    }

    private function handleExit(CandidateMonitor $monitor, string $slot, array $advice, array &$summary): void
    {
        $monitor->logAiAdvice('exit', $advice['reasoning']);
        $this->transition($monitor, CandidateMonitor::STATUS_CLOSED, "{$slot} AI 建議提前出場：{$advice['reasoning']}");
        $summary['exited']++;
    }

    private function handleAdjust(CandidateMonitor $monitor, string $slot, array $advice, array &$summary): void
    {
        $updates = [];

        if (!empty($advice['adjusted_target']) && $advice['adjusted_target'] > 0) {
            $updates['current_target'] = $advice['adjusted_target'];
        }
        if (!empty($advice['adjusted_stop']) && $advice['adjusted_stop'] > 0) {
            $updates['current_stop'] = $advice['adjusted_stop'];
        }

        $monitor->logAiAdvice('adjust', $advice['reasoning'], [
            'adjusted_target' => $advice['adjusted_target'] ?? null,
            'adjusted_stop'   => $advice['adjusted_stop']   ?? null,
            'slot'            => $slot,
        ]);

        if (!empty($updates)) {
            foreach ($updates as $k => $v) {
                $monitor->$k = $v;
            }
        }

        $monitor->save();
        $summary['adjusted']++;
    }

    private function handleHold(CandidateMonitor $monitor, string $slot, array $advice, array &$summary): void
    {
        $monitor->logAiAdvice('hold', $advice['reasoning']);
        $monitor->save();
        $summary['held']++;
    }

    // -------------------------------------------------------------------------
    // Haiku AI 判斷
    // -------------------------------------------------------------------------

    private function askHaiku(string $slot, Candidate $candidate, CandidateMonitor $monitor, array $quote): array
    {
        $fallback = ['action' => 'hold', 'adjusted_target' => null, 'adjusted_stop' => null, 'reasoning' => 'AI 不可用，維持現狀'];

        if (!$this->apiKey) {
            return $fallback;
        }

        $symbol        = $candidate->stock->symbol;
        $name          = $candidate->stock->name;
        $industry      = $candidate->stock->industry ?? '';
        $origTarget    = (float) $candidate->target_price;
        $origStop      = (float) $candidate->stop_loss;
        $currentTarget = (float) $monitor->current_target;
        $currentStop   = (float) $monitor->current_stop;
        $suggestedBuy  = (float) $candidate->suggested_buy;
        $prevClose     = (float) $quote['prev_close'];

        $open    = (float) $quote['open'];
        $high    = (float) $quote['high'];
        $low     = (float) $quote['low'];
        $current = (float) $quote['current_price'];
        $volume  = round($quote['accumulated_volume'] / 1000); // 張

        $openGapPct    = $prevClose > 0 ? round(($open - $prevClose) / $prevClose * 100, 2) : 0;
        $profitPct     = $suggestedBuy > 0 ? round(($current - $suggestedBuy) / $suggestedBuy * 100, 2) : 0;
        $distTarget    = $currentTarget > 0 ? round(($currentTarget - $current) / $current * 100, 2) : 0;
        $distStop      = $currentStop > 0 ? round(($current - $currentStop) / $current * 100, 2) : 0;

        $profitLabel   = $profitPct >= 2 ? '獲利中' : ($profitPct <= -1 ? '虧損中' : '持平');
        $profitFmt     = sprintf('%+.2f', $profitPct);
        $distTargetFmt = sprintf('%+.2f', $distTarget);
        $distStopFmt   = sprintf('%.2f', $distStop);
        $openGapFmt    = sprintf('%+.2f', $openGapPct);

        $h = intdiv((int) $slot, 100);
        $m = (int) $slot % 100;
        $slotLabel = sprintf('%02d:%02d', $h, $m);

        // 今日 5 分 K 聚合
        $candleSection = $this->buildCandleSection($candidate->stock_id, $candidate->trade_date->format('Y-m-d'));

        // 歷史建議
        $prevAdviceSection = '';
        $log = $monitor->ai_advice_log ?? [];
        if (!empty($log)) {
            $prevAdviceSection = "\n## 先前 AI 判斷紀錄\n";
            foreach (array_slice($log, -3) as $entry) {
                $prevAdviceSection .= "- [{$entry['time']}] {$entry['action']}: {$entry['notes']}\n";
            }
        }

        // 隔日沖策略
        $entryType = $candidate->overnight_strategy ?? '';
        $overnightReasoning = $candidate->overnight_reasoning ?? '';

        // 時間壓力提示（收盤 13:30，最晚 13:25 前需平倉）
        $slotMinutes = $h * 60 + $m;
        $deadlineMinutes = 13 * 60 + 25; // 13:25
        $remainingMin = $deadlineMinutes - $slotMinutes;

        if ($remainingMin <= 60) {
            $urgency = "⚠️ **時間緊迫：距最終平倉期限（13:25）僅剩 {$remainingMin} 分鐘。除非走勢明確向上攻擊中，否則應優先建議出場鎖定損益。**";
        } elseif ($remainingMin <= 120) {
            $urgency = "⏰ 距收盤平倉期限（13:25）約 " . round($remainingMin / 60, 1) . " 小時。進入尾盤階段，獲利部位應考慮收緊停損鎖利，虧損部位應評估是否提前止損。";
        } else {
            $urgency = "距收盤平倉期限（13:25）尚有 " . round($remainingMin / 60, 1) . " 小時，可正常持有觀察。";
        }

        $prompt = <<<PROMPT
你是台股隔日沖出場管理 AI。

**重要前提：我們已於昨日（T+0）收盤前建倉，目前持有 {$symbol} {$name}（{$industry}），現在是 T+1 的 {$slotLabel}，你的任務是管理這筆已建倉的持倉出場策略。**

{$urgency}

## 持倉狀態（已進場）
狀態: 持有中 — {$profitLabel}
建倉價（T+0 建議買入）: {$suggestedBuy}
昨日收盤: {$prevClose}
當前損益: {$profitFmt}%
距目標: {$distTargetFmt}%（目標 {$currentTarget}）
距停損: {$distStopFmt}%（停損 {$currentStop}）
原始目標: {$origTarget}　原始停損: {$origStop}
建倉策略: {$entryType}
{$overnightReasoning}

## T+1 盤中即時快照（{$slotLabel}）
開盤: {$open}（跳空 {$openGapFmt}%）
最高: {$high}　最低: {$low}　現價: {$current}
累積量: {$volume} 張
{$candleSection}
{$prevAdviceSection}

## 任務（持有中 — {$profitLabel}）
1. 根據 5 分 K 走勢判斷：盤中趨勢是否支持繼續持有到目標？
2. 是否需要調整目標或收緊停損來鎖利？
3. 是否出現明確的出場訊號？（反轉、量縮價跌、支撐跌破）
4. 考量剩餘時間：還有足夠時間等待目標達成嗎？

決定策略：hold（維持）/ adjust（調整目標或停損）/ exit（建議提前出場）
adjust 時必須給出新的 adjusted_target 或 adjusted_stop（或兩者），且需合理：target > current > stop

請直接回覆 JSON（不要加 markdown）：
{"action":"hold","adjusted_target":null,"adjusted_stop":null,"reasoning":"一句話"}
PROMPT;

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model'      => $this->model,
                    'max_tokens' => 256,
                    'messages'   => [['role' => 'user', 'content' => $prompt]],
                ]);

            if (!$response->successful()) {
                Log::warning("OvernightExitMonitor Haiku {$symbol}: HTTP {$response->status()}");
                return $fallback;
            }

            $text = trim($response->json('content.0.text', ''));
            $text = preg_replace('/^```json?\s*/i', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
            $data = json_decode($text, true);

            if (!is_array($data) || !isset($data['action'])) {
                Log::warning("OvernightExitMonitor Haiku {$symbol}: 無法解析回應");
                return $fallback;
            }

            return [
                'action'          => in_array($data['action'], ['hold', 'adjust', 'exit']) ? $data['action'] : 'hold',
                'adjusted_target' => isset($data['adjusted_target']) ? (float) $data['adjusted_target'] ?: null : null,
                'adjusted_stop'   => isset($data['adjusted_stop'])   ? (float) $data['adjusted_stop']   ?: null : null,
                'reasoning'       => $data['reasoning'] ?? '',
            ];
        } catch (\Exception $e) {
            Log::error("OvernightExitMonitor Haiku {$symbol}: " . $e->getMessage());
            return $fallback;
        }
    }

    // -------------------------------------------------------------------------
    // 5 分 K 聚合（從 IntradaySnapshot 建構）
    // -------------------------------------------------------------------------

    private function buildCandleSection(int $stockId, string $tradeDate): string
    {
        $snapshots = IntradaySnapshot::where('stock_id', $stockId)
            ->where('trade_date', $tradeDate)
            ->orderBy('snapshot_time')
            ->get();

        if ($snapshots->isEmpty()) {
            return '';
        }

        $candles = $this->aggregateToCandles($snapshots);

        if (empty($candles)) {
            return '';
        }

        $header = "時段    開       高       低       收       量      外盤%";
        $lines = [$header];
        foreach ($candles as $c) {
            $lines[] = sprintf('%s  %.2f  %.2f  %.2f  %.2f  %d張  %.0f%%',
                $c['time'], $c['open'], $c['high'], $c['low'], $c['close'],
                $c['volume_lots'], $c['external_ratio']
            );
        }

        // 開盤區間
        $first = $candles[0];
        $openingRange = sprintf(
            "開盤區間（首根 5 分 K）: 高 %.2f / 低 %.2f",
            $first['high'], $first['low']
        );

        return "\n## T+1 今日 5 分 K\n" . implode("\n", $lines) . "\n\n" . $openingRange;
    }

    private function aggregateToCandles(Collection $snapshots, int $periodMinutes = 5): array
    {
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

            $open  = (float) $first->current_price;
            $close = (float) $last->current_price;
            $high  = max(array_map(fn($s) => (float) $s->high, $snaps));
            $low   = min(array_map(fn($s) => (float) $s->low, $snaps));

            $accVolNow = (int) $last->accumulated_volume;
            $periodVolShares = max(0, $accVolNow - $prevAccVol);
            $prevAccVol = $accVolNow;

            $candles[] = [
                'time'           => $time,
                'open'           => $open,
                'high'           => $high,
                'low'            => $low,
                'close'          => $close,
                'volume_lots'    => (int) round($periodVolShares / 1000),
                'external_ratio' => (float) $last->external_ratio,
            ];
        }

        return $candles;
    }
}

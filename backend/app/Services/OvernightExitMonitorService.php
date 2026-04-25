<?php

namespace App\Services;

use App\Models\AiLesson;
use App\Models\Candidate;
use App\Models\CandidateMonitor;
use App\Models\DailyQuote;
use App\Models\IntradaySnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OvernightExitMonitorService
{
    private string $apiKey;
    private string $model;

    public function __construct(
        private FugleRealtimeClient $fugle,
        private TelegramService $telegram,
    ) {
        $this->apiKey = config('services.anthropic.api_key', '');
        $this->model  = config('services.anthropic.overnight_model', 'claude-sonnet-4-6');
    }

    /**
     * 指定時段的監控執行（09:05~13:15，每 15 分鐘）
     *
     * @param  string  $tradeDate  T+1 出場日（YYYY-MM-DD）
     * @param  string  $slot       時段代碼，如 '905'、'930'、'1000'
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

        // 批次撈昨量（消除 per-stock N+1 query）
        $yesterdayVolumes = [];
        foreach ($candidates->pluck('stock_id')->unique() as $sid) {
            $yesterdayVolumes[$sid] = DailyQuote::where('stock_id', $sid)
                ->where('date', '<', $tradeDate)
                ->orderByDesc('date')
                ->value('volume') ?? 0;
        }

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
            $open          = (float) ($quote['open'] ?? 0);
            $high          = (float) $quote['high'];
            $low           = (float) $quote['low'];

            $name = $candidate->stock->name;

            // ── 已達目標 ──────────────────────────────────────────────
            if ($currentTarget > 0 && $high >= $currentTarget) {
                $this->transition($monitor, CandidateMonitor::STATUS_TARGET_HIT,
                    "{$slot} 盤中最高 {$high} 達到目標 {$currentTarget}");
                $monitor->update(['exit_price' => $currentTarget, 'exit_time' => now()]);
                $summary['target_hit']++;
                $buyPrice = (float) $candidate->suggested_buy;
                $profitPct = $buyPrice > 0 ? round(($currentTarget - $buyPrice) / $buyPrice * 100, 1) : 0;
                $this->telegram->send(sprintf(
                    "✅✅✅ *隔日沖達標* ✅✅✅\n\n"
                    . "📌 *%s %s*\n"
                    . "💰 買進：*%.2f* → 目標：*%.2f*\n"
                    . "📈 損益：*+%.1f%%*\n"
                    . "📊 盤中高 %.2f\n"
                    . "⏰ %s",
                    $symbol, $name, $buyPrice, $currentTarget, $profitPct, $high, $slot
                ));
                Log::info("OvernightExitMonitor [{$slot}] {$symbol}：目標達成（high={$high}）");
                continue;
            }

            // ── 觸發停損 ──────────────────────────────────────────────
            if ($currentStop > 0 && $low <= $currentStop) {
                // 跳空跌破停損 → 出場價用開盤價（不可能賣在停損價）
                $exitPrice = ($open > 0 && $open < $currentStop) ? $open : $currentStop;
                $this->transition($monitor, CandidateMonitor::STATUS_STOP_HIT,
                    "{$slot} 盤中最低 {$low} 觸及停損 {$currentStop}");
                $monitor->update(['exit_price' => $exitPrice, 'exit_time' => now()]);
                $summary['stop_hit']++;
                $buyPrice = (float) $candidate->suggested_buy;
                $profitPct = $buyPrice > 0 ? round(($exitPrice - $buyPrice) / $buyPrice * 100, 1) : 0;
                $this->telegram->send(sprintf(
                    "❌❌❌ *隔日沖停損* ❌❌❌\n\n"
                    . "📌 *%s %s*\n"
                    . "💰 買進：*%.2f* → 出場：*%.2f*\n"
                    . "📈 損益：*%.1f%%*\n"
                    . "📊 盤中低 %.2f｜停損 %.2f\n"
                    . "⏰ %s",
                    $symbol, $name, $buyPrice, $exitPrice, $profitPct, $low, $currentStop, $slot
                ));
                Log::info("OvernightExitMonitor [{$slot}] {$symbol}：停損觸發（low={$low}）");
                continue;
            }

            // ── AI 滾動判斷 ───────────────────────────────────────────
            $advice = $this->askSonnet($slot, $candidate, $monitor, $quote, $yesterdayVolumes[$candidate->stock_id] ?? 0);

            match ($advice['action']) {
                'exit' => $this->handleExit($monitor, $slot, $advice, $summary, $symbol, $name),
                'adjust' => $this->handleAdjust($monitor, $slot, $advice, $summary, $symbol, $name),
                default => $this->handleHold($monitor, $slot, $advice, $summary),
            };

            Log::info("OvernightExitMonitor [{$slot}] {$symbol}：{$advice['action']} — {$advice['reasoning']}");
        }

        return $summary;
    }

    /**
     * 純規則到價檢查（每 30 秒由 monitor-intraday 呼叫，不含 AI）
     *
     * @param  array  $quotes  Fugle 報價（keyed by symbol）
     * @return int  觸發數量
     */
    public function checkPriceHits(string $tradeDate, array $quotes): int
    {
        $candidates = Candidate::with(['stock', 'monitor'])
            ->where('mode', 'overnight')
            ->where('trade_date', $tradeDate)
            ->where('ai_selected', true)
            ->whereHas('monitor', fn ($q) => $q->whereNotIn('status', CandidateMonitor::TERMINAL_STATUSES))
            ->get();

        if ($candidates->isEmpty()) {
            return 0;
        }

        $triggered = 0;
        $timeStr = now()->format('H:i');

        foreach ($candidates as $candidate) {
            $symbol  = $candidate->stock->symbol;
            $monitor = $candidate->monitor;
            $quote   = $quotes[$symbol] ?? null;

            if (!$monitor || !$quote) continue;

            $currentTarget = (float) $monitor->current_target;
            $currentStop   = (float) $monitor->current_stop;
            $open          = (float) ($quote['open'] ?? 0);
            $high          = (float) $quote['high'];
            $low           = (float) $quote['low'];
            $name          = $candidate->stock->name;

            if ($currentTarget > 0 && $high >= $currentTarget) {
                $this->transition($monitor, CandidateMonitor::STATUS_TARGET_HIT,
                    "即時偵測 {$timeStr} 盤中最高 {$high} 達到目標 {$currentTarget}");
                $monitor->update(['exit_price' => $currentTarget, 'exit_time' => now()]);
                $buyPrice = (float) $candidate->suggested_buy;
                $profitPct = $buyPrice > 0 ? round(($currentTarget - $buyPrice) / $buyPrice * 100, 1) : 0;
                $this->telegram->send(sprintf(
                    "✅✅✅ *隔日沖達標* ✅✅✅\n\n"
                    . "📌 *%s %s*\n"
                    . "💰 買進：*%.2f* → 目標：*%.2f*\n"
                    . "📈 損益：*+%.1f%%*\n"
                    . "📊 盤中高 %.2f\n"
                    . "⏰ 即時偵測 %s",
                    $symbol, $name, $buyPrice, $currentTarget, $profitPct, $high, $timeStr
                ));
                Log::info("OvernightPriceHit [{$timeStr}] {$symbol}：目標達成（high={$high}）");
                $triggered++;
                continue;
            }

            if ($currentStop > 0 && $low <= $currentStop) {
                $exitPrice = ($open > 0 && $open < $currentStop) ? $open : $currentStop;
                $this->transition($monitor, CandidateMonitor::STATUS_STOP_HIT,
                    "即時偵測 {$timeStr} 盤中最低 {$low} 觸及停損 {$currentStop}");
                $monitor->update(['exit_price' => $exitPrice, 'exit_time' => now()]);
                $buyPrice = (float) $candidate->suggested_buy;
                $profitPct = $buyPrice > 0 ? round(($exitPrice - $buyPrice) / $buyPrice * 100, 1) : 0;
                $this->telegram->send(sprintf(
                    "❌❌❌ *隔日沖停損* ❌❌❌\n\n"
                    . "📌 *%s %s*\n"
                    . "💰 買進：*%.2f* → 出場：*%.2f*\n"
                    . "📈 損益：*%.1f%%*\n"
                    . "📊 盤中低 %.2f｜停損 %.2f\n"
                    . "⏰ 即時偵測 %s",
                    $symbol, $name, $buyPrice, $exitPrice, $profitPct, $low, $currentStop, $timeStr
                ));
                Log::info("OvernightPriceHit [{$timeStr}] {$symbol}：停損觸發（low={$low}）");
                $triggered++;
                continue;
            }
        }

        return $triggered;
    }

    // -------------------------------------------------------------------------

    private function transition(CandidateMonitor $monitor, string $toStatus, string $reason): void
    {
        $monitor->logTransition($monitor->status, $toStatus, $reason);
        $monitor->status = $toStatus;
        $monitor->save();
    }

    private function handleExit(CandidateMonitor $monitor, string $slot, array $advice, array &$summary, string $symbol = '', string $name = ''): void
    {
        $monitor->logAiAdvice('exit', $advice['reasoning']);
        $this->transition($monitor, CandidateMonitor::STATUS_CLOSED, "{$slot} AI 建議提前出場：{$advice['reasoning']}");
        $monitor->update(['exit_time' => now()]);
        $summary['exited']++;
        $this->telegram->send(sprintf(
            "🔴🔴🔴 *隔日沖AI出場* 🔴🔴🔴\n\n"
            . "📌 *%s %s*\n"
            . "💡 %s\n"
            . "⏰ %s",
            $symbol, $name, $advice['reasoning'], $slot
        ));
    }

    private function handleAdjust(CandidateMonitor $monitor, string $slot, array $advice, array &$summary, string $symbol = '', string $name = ''): void
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

        $adjustParts = [];
        if (!empty($advice['adjusted_target'])) $adjustParts[] = sprintf('目標→%.2f', $advice['adjusted_target']);
        if (!empty($advice['adjusted_stop']))   $adjustParts[] = sprintf('停損→%.2f', $advice['adjusted_stop']);
        $this->telegram->send(sprintf(
            "🟡 *隔日沖AI調整* %s %s\n\n%s\n💡 %s\n⏰ %s",
            $symbol, $name, implode("\n", $adjustParts), $advice['reasoning'], $slot
        ));
    }

    private function handleHold(CandidateMonitor $monitor, string $slot, array $advice, array &$summary): void
    {
        $monitor->logAiAdvice('hold', $advice['reasoning']);
        $monitor->save();
        $summary['held']++;
    }

    // -------------------------------------------------------------------------
    // Sonnet AI 判斷
    // -------------------------------------------------------------------------

    private function askSonnet(string $slot, Candidate $candidate, CandidateMonitor $monitor, array $quote, ?int $yesterdayVolume = null): array
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

        // ── 量比（預估全日量 vs 昨量）──
        if ($yesterdayVolume === null) {
            $yesterdayVolume = DailyQuote::where('stock_id', $candidate->stock_id)
                ->where('date', '<', $candidate->trade_date->format('Y-m-d'))
                ->orderByDesc('date')
                ->value('volume') ?? 0;
        }

        $slotMinutes = $h * 60 + $m;
        $elapsedMin  = max(1, $slotMinutes - 9 * 60); // 距 09:00 的分鐘數
        $totalMin    = 270; // 09:00~13:30
        $estimatedDailyVol = ($quote['accumulated_volume'] / $elapsedMin) * $totalMin;
        $volumeRatio = $yesterdayVolume > 0 ? round($estimatedDailyVol / $yesterdayVolume, 2) : 0;
        $volumeRatioFmt = $volumeRatio > 0 ? sprintf('%.2fx', $volumeRatio) : 'N/A';

        // ── 跳空預測 vs 實際 ──
        $gapPredicted = (float) ($candidate->gap_potential_percent ?? 0);
        $gapSection = '';
        if ($gapPredicted != 0) {
            $gapDiff = round($openGapPct - $gapPredicted, 2);
            $gapLabel = $gapDiff > 0.5 ? '超預期' : ($gapDiff < -0.5 ? '不及預期' : '符合預期');
            $gapSection = sprintf(
                "\n預測跳空: %+.2f%%　實際跳空: %+.2f%%　差異: %+.2f%%（%s）",
                $gapPredicted, $openGapPct, $gapDiff, $gapLabel
            );
        }

        // ── 關鍵價位（Opus 選股時設定）──
        $keyLevelsSection = '';
        $keyLevels = $candidate->overnight_key_levels ?? [];
        if (!empty($keyLevels)) {
            $keyLevelsSection = "\n## 關鍵價位（Opus 選股設定）\n";
            foreach ($keyLevels as $level) {
                $price  = $level['price'] ?? '?';
                $type   = $level['type'] ?? '';
                $reason = $level['reason'] ?? '';
                $keyLevelsSection .= "- {$type} {$price}: {$reason}\n";
            }
        }

        // ── 今日 5 分 K 聚合 ──
        $candleSection = $this->buildCandleSection($candidate->stock_id, $candidate->trade_date->format('Y-m-d'), $symbol);

        // ── 歷史建議 ──
        $prevAdviceSection = '';
        $log = $monitor->ai_advice_log ?? [];
        if (!empty($log)) {
            $prevAdviceSection = "\n## 先前 AI 判斷紀錄\n";
            foreach (array_slice($log, -3) as $entry) {
                $prevAdviceSection .= "- [{$entry['time']}] {$entry['action']}: {$entry['notes']}\n";
            }
        }

        // ── AI 歷史教訓 ──
        $lessonsSection = AiLesson::getOvernightLessons();

        // ── 隔日沖策略 ──
        $entryType = $candidate->overnight_strategy ?? '';
        $overnightReasoning = $candidate->overnight_reasoning ?? '';

        // ── 時間壓力提示 ──
        $deadlineMinutes = 13 * 60 + 25; // 13:25
        $remainingMin = $deadlineMinutes - $slotMinutes;

        if ($remainingMin <= 60) {
            $urgency = "⚠️ **時間緊迫：距最終平倉期限（13:25）僅剩 {$remainingMin} 分鐘。除非走勢明確向上攻擊中，否則應優先建議出場鎖定損益。**";
        } elseif ($remainingMin <= 120) {
            $urgency = "⏰ 距收盤平倉期限（13:25）約 " . round($remainingMin / 60, 1) . " 小時。進入尾盤階段，獲利部位應考慮收緊停損鎖利，虧損部位應評估是否提前止損。";
        } else {
            $urgency = "距收盤平倉期限（13:25）尚有 " . round($remainingMin / 60, 1) . " 小時，可正常持有觀察。";
        }

        // =====================================================================
        // System prompt（靜態，可被 prompt cache 快取）
        // =====================================================================
        $systemPrompt = <<<SYSTEM
你是台股隔日沖出場管理 AI。你的角色是管理已建倉持股的 T+1 出場策略。

## 背景
- 隔日沖策略：T+0 收盤前建倉，T+1 盤中出場
- 台股交易時間 09:00-13:30，最晚 13:25 前必須平倉
- 你每 15 分鐘被呼叫一次，根據最新盤中數據決定持倉操作

## 決策框架
1. **趨勢判斷**：根據 5 分 K 走勢，盤中趨勢是否支持繼續持有到目標？
2. **風控管理**：是否需要調整目標或收緊停損來鎖利？
3. **出場訊號**：是否出現反轉、量縮價跌、支撐跌破等明確出場訊號？
4. **時間因素**：剩餘時間是否足夠等待目標達成？
5. **量能判斷**：量比偏低代表市場參與度不足，走勢可能缺乏持續性
6. **跳空驗證**：實際跳空 vs 預測跳空的落差，反映市場對利多/利空的真實反應
7. **關鍵價位**：支撐/壓力位是重要的進出參考

## 回覆格式
決定策略：hold（維持）/ adjust（調整目標或停損）/ exit（建議提前出場）
adjust 時必須給出新的 adjusted_target 或 adjusted_stop（或兩者），且需合理：target > current > stop

請直接回覆 JSON（不要加 markdown）：
{"action":"hold","adjusted_target":null,"adjusted_stop":null,"reasoning":"一句話"}
SYSTEM;

        // =====================================================================
        // User message（動態，每次呼叫都不同）
        // =====================================================================
        $userMessage = <<<USER
**持有 {$symbol} {$name}（{$industry}），現在 T+1 {$slotLabel}**

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
開盤: {$open}（跳空 {$openGapFmt}%）{$gapSection}
最高: {$high}　最低: {$low}　現價: {$current}
累積量: {$volume} 張　量比（預估全日/昨量）: {$volumeRatioFmt}
{$keyLevelsSection}{$candleSection}
{$prevAdviceSection}
{$lessonsSection}

## 任務（持有中 — {$profitLabel}）
請根據以上所有資訊，決定操作策略。
USER;

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
                    'max_tokens' => 256,
                    'system'     => [
                        [
                            'type'          => 'text',
                            'text'          => $systemPrompt,
                            'cache_control' => ['type' => 'ephemeral'],
                        ],
                    ],
                    'messages'   => [['role' => 'user', 'content' => $userMessage]],
                ]);

            if (!$response->successful()) {
                Log::warning("OvernightExitMonitor Sonnet {$symbol}: HTTP {$response->status()}");
                return $fallback;
            }

            $text = trim($response->json('content.0.text', ''));
            $text = preg_replace('/^```json?\s*/i', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
            $data = json_decode($text, true);

            if (!is_array($data) || !isset($data['action'])) {
                Log::warning("OvernightExitMonitor Sonnet {$symbol}: 無法解析回應");
                return $fallback;
            }

            return [
                'action'          => in_array($data['action'], ['hold', 'adjust', 'exit']) ? $data['action'] : 'hold',
                'adjusted_target' => isset($data['adjusted_target']) ? (float) $data['adjusted_target'] ?: null : null,
                'adjusted_stop'   => isset($data['adjusted_stop'])   ? (float) $data['adjusted_stop']   ?: null : null,
                'reasoning'       => $data['reasoning'] ?? '',
            ];
        } catch (\Exception $e) {
            Log::error("OvernightExitMonitor Sonnet {$symbol}: " . $e->getMessage());
            return $fallback;
        }
    }

    // -------------------------------------------------------------------------
    // 5 分 K 聚合（從 IntradaySnapshot 建構）
    // -------------------------------------------------------------------------

    private function buildCandleSection(int $stockId, string $tradeDate, string $symbol = ''): string
    {
        $snapshots = IntradaySnapshot::where('stock_id', $stockId)
            ->where('trade_date', $tradeDate)
            ->orderBy('snapshot_time')
            ->get();

        if ($snapshots->isEmpty()) {
            return $symbol ? $this->buildCandleSectionFromFugle($symbol) : '';
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

    /**
     * 無快照時用 Fugle 5 分 K API 補抓
     */
    private function buildCandleSectionFromFugle(string $symbol): string
    {
        $candles = $this->fugle->fetchCandles($symbol);

        if (empty($candles)) {
            return '';
        }

        $header = "時段    開       高       低       收       量";
        $lines = [$header];
        foreach ($candles as $c) {
            $lines[] = sprintf('%s  %.2f  %.2f  %.2f  %.2f  %d張',
                $c['time'], $c['open'], $c['high'], $c['low'], $c['close'], $c['volume']
            );
        }

        $first = $candles[0];
        $openingRange = sprintf(
            "開盤區間（首根 5 分 K）: 高 %.2f / 低 %.2f",
            $first['high'], $first['low']
        );

        return "\n## T+1 今日 5 分 K（Fugle）\n" . implode("\n", $lines) . "\n\n" . $openingRange;
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

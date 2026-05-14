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
     * 指定時段的監控執行（09:05~13:25；13:25 對未出場部位強制平倉）
     *
     * @param  string  $tradeDate  T+1 出場日（YYYY-MM-DD）
     * @param  string  $slot       時段代碼，如 '905'、'930'、'1000'
     */
    public function checkTimeSlot(string $tradeDate, string $slot): array
    {
        $summary = ['slot' => $slot, 'checked' => 0, 'target_hit' => 0, 'stop_hit' => 0, 'adjusted' => 0, 'held' => 0, 'exited' => 0];
        $forceClose = $this->slotToMinutes($slot) >= 13 * 60 + 25;

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
                $this->telegram->broadcast(sprintf(
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
                $this->telegram->broadcast(sprintf(
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

            // ── 13:25 強制平倉 ────────────────────────────────────────
            if ($forceClose) {
                $this->handleForceClose($monitor, $slot, $quote, $summary, $symbol, $name, $candidate);
                continue;
            }

            // ── AI 滾動判斷 ───────────────────────────────────────────
            $advice = $this->askSonnet($slot, $candidate, $monitor, $quote, $yesterdayVolumes[$candidate->stock_id] ?? 0);

            match ($advice['action']) {
                'exit' => $this->handleExit($monitor, $slot, $advice, $summary, $symbol, $name, $quote, $candidate),
                'adjust' => $this->handleAdjust($monitor, $slot, $advice, $summary, $symbol, $name),
                default => $this->handleHold($monitor, $slot, $advice, $summary),
            };

            Log::info("OvernightExitMonitor [{$slot}] {$symbol}：{$advice['action']} — {$advice['reasoning']}");
        }

        return $summary;
    }

    /**
     * 純規則到價檢查（已不再由 monitor-intraday 呼叫，改由 checkTimeSlot 內建的到價檢查取代）
     *
     * @deprecated 隔日沖排程已加密至每 5 分鐘（早盤），checkTimeSlot 自帶到價檢查
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
                $this->telegram->broadcast(sprintf(
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
                $this->telegram->broadcast(sprintf(
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

    private function handleExit(
        CandidateMonitor $monitor,
        string $slot,
        array $advice,
        array &$summary,
        string $symbol = '',
        string $name = '',
        array $quote = [],
        ?Candidate $candidate = null
    ): void
    {
        $exitPrice = (float) ($quote['current_price'] ?? $quote['close'] ?? $quote['open'] ?? 0);
        $buyPrice = $candidate ? (float) $candidate->suggested_buy : 0.0;
        $profitPct = ($buyPrice > 0 && $exitPrice > 0)
            ? round(($exitPrice - $buyPrice) / $buyPrice * 100, 1)
            : 0;

        $monitor->logAiAdvice('exit', $advice['reasoning'], null, [
            'strategy_state' => $advice['strategy_state'] ?? null,
            'strategy_issue' => $advice['strategy_issue'] ?? null,
        ]);
        $this->transition($monitor, CandidateMonitor::STATUS_CLOSED, "{$slot} AI 建議提前出場：{$advice['reasoning']}");
        $updates = ['exit_time' => now()];
        if ($exitPrice > 0) {
            $updates['exit_price'] = $exitPrice;
        }
        $monitor->update($updates);
        $summary['exited']++;
        $this->telegram->broadcast(sprintf(
            "🔴🔴🔴 *隔日沖AI出場* 🔴🔴🔴\n\n"
            . "📌 *%s %s*\n"
            . "💰 出場：*%.2f*｜損益：*%+.1f%%*\n"
            . "💡 %s\n"
            . "⏰ %s",
            $symbol, $name, $exitPrice, $profitPct, $advice['reasoning'], $slot
        ));
    }

    private function handleForceClose(
        CandidateMonitor $monitor,
        string $slot,
        array $quote,
        array &$summary,
        string $symbol,
        string $name,
        Candidate $candidate
    ): void {
        $exitPrice = (float) ($quote['current_price'] ?? $quote['close'] ?? $quote['open'] ?? 0);
        $buyPrice = (float) $candidate->suggested_buy;
        $profitPct = ($buyPrice > 0 && $exitPrice > 0)
            ? round(($exitPrice - $buyPrice) / $buyPrice * 100, 1)
            : 0;

        $monitor->logAiAdvice('exit', '13:25 強制平倉，不再等待 AI 判斷', ['slot' => $slot]);
        $this->transition($monitor, CandidateMonitor::STATUS_CLOSED, "{$slot} 收盤前強制平倉");
        $monitor->update(['exit_price' => $exitPrice, 'exit_time' => now()]);
        $summary['exited']++;

        $this->telegram->broadcast(sprintf(
            "🔴 *隔日沖強制平倉* %s %s\n\n"
            . "💰 買進：*%.2f* → 出場：*%.2f*\n"
            . "📈 損益：*%+.1f%%*\n"
            . "⏰ %s",
            $symbol,
            $name,
            $buyPrice,
            $exitPrice,
            $profitPct,
            $slot
        ));

        Log::info("OvernightExitMonitor [{$slot}] {$symbol}：13:25 強制平倉（price={$exitPrice}）");
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
        ], [
            'strategy_state' => $advice['strategy_state'] ?? null,
            'strategy_issue' => $advice['strategy_issue'] ?? null,
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
        $this->telegram->broadcast(sprintf(
            "🟡 *隔日沖AI調整* %s %s\n\n%s\n💡 %s\n⏰ %s",
            $symbol, $name, implode("\n", $adjustParts), $advice['reasoning'], $slot
        ));
    }

    private function handleHold(CandidateMonitor $monitor, string $slot, array $advice, array &$summary): void
    {
        $monitor->logAiAdvice('hold', $advice['reasoning'], null, [
            'strategy_state' => $advice['strategy_state'] ?? null,
            'strategy_issue' => $advice['strategy_issue'] ?? null,
        ]);
        $monitor->save();
        $summary['held']++;
    }

    private function slotToMinutes(string $slot): int
    {
        $slot = (int) $slot;
        return intdiv($slot, 100) * 60 + ($slot % 100);
    }

    // -------------------------------------------------------------------------
    // Sonnet AI 判斷
    // -------------------------------------------------------------------------

    private function askSonnet(string $slot, Candidate $candidate, CandidateMonitor $monitor, array $quote, ?int $yesterdayVolume = null): array
    {
        $fallback = [
            'action' => 'hold',
            'strategy_state' => 'uncertain',
            'strategy_issue' => 'AI 不可用',
            'adjusted_target' => null,
            'adjusted_stop' => null,
            'reasoning' => 'AI 不可用，維持現狀',
        ];

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
            $gapLabel = self::classifyGapDiff($gapDiff);
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
- 你在早盤（09:05~09:25）每 5 分鐘、之後每 15 分鐘被呼叫一次，根據最新盤中數據決定持倉操作

## 策略狀態與出場框架
每次先判斷 strategy_state：

- valid：原 entry_type 仍成立，走勢支持持有到目標
- adjusted：原方向仍可交易，但目標或停損需要調整；回覆 adjusted_target/adjusted_stop
- uncertain：早盤或訊號矛盾，尚不足以判定失效；優先 hold 或 adjust
- failed：原 entry_type 前提明確失效，且沒有合理調整空間；才 exit

exit 只用在 strategy_state=failed，或尾盤時間不足且走勢沒有明確向上攻擊。
跳空不如預期不是單獨 exit 理由，須結合首根 K、量比、支撐/停損與策略容忍度。

## entry_type 判讀（依本次持倉套用）
所有「跳空」皆指「(T+1 開盤 − T+0 收盤) / T+0 收盤」。
- **gap_up_open（跳空高開）**：要求正跳空；若未高開但首根 K 快速收復、量能接手，可先 uncertain/adjust，不必立刻 failed。
- **pullback_entry（拉回建倉）**：允許小幅弱開或拉回，重點看是否在早盤止穩回升；連續破首根 K 低點且量縮才偏 failed。
- **open_follow_through（延續開盤）**：允許開盤落差，重點看是否收復昨收、量能是否支持延續；不能只因跳空不及預期 exit。
- **limit_up_chase（漲停追強）**：要求強勢延續；若負跳空且無接手、跌破支撐，偏 failed；若快速收復則可 adjust/hold。
- **未指定 entry_type**：無容忍度框架，回到通用決策框架判斷。

## 早盤觀察期紀律（09:05~09:25）
此時段資訊有限，除非直接跌破停損且無收復、或量價結構明確失守，否則優先 uncertain/hold 或 adjusted，避免在策略未展開前就退出。
「強彈、站穩支撐、量比放大」屬於策略生效或修復訊號，不應與「跳空小幅不及預期」並列為退出理由。

## 回覆格式
決定策略：hold（維持）/ adjust（調整目標或停損）/ exit（建議提前出場）
strategy_state：valid / adjusted / uncertain / failed
adjust 時必須給出新的 adjusted_target 或 adjusted_stop（或兩者），且需合理：target > current > stop
strategy_issue 說明策略仍有效、需要調整、資料不足或結構失效的原因。
reasoning 必須包含三段：(a) strategy_state 判斷 (b) 主要正/負向訊號 (c) 結論依據。

請直接回覆 JSON（不要加 markdown）：
{"action":"hold","strategy_state":"valid","strategy_issue":null,"adjusted_target":null,"adjusted_stop":null,"reasoning":"簡短說明（策略狀態+主要訊號+結論）"}
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
                    'max_tokens' => 768,
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
                Log::warning("OvernightExitMonitor Sonnet {$symbol}: HTTP {$response->status()} — "
                    . mb_substr($response->body(), 0, 500));
                return $fallback;
            }

            $text = trim($response->json('content.0.text', ''));
            $data = $this->parseSonnetJson($text);

            if (!is_array($data) || !isset($data['action'])) {
                Log::warning("OvernightExitMonitor Sonnet {$symbol}: 無法解析回應 — "
                    . mb_substr($text, 0, 500));
                return $fallback;
            }

            $reasoning = $data['reasoning'] ?? '';
            // reasoning 三段格式（策略檢核+主要訊號+結論）大致需 30+ 漢字；過短代表 AI 偷懶或回應被截斷
            if (mb_strlen($reasoning) < 30) {
                Log::warning("OvernightExitMonitor Sonnet {$symbol}: reasoning 過短（"
                    . mb_strlen($reasoning) . "字, action={$data['action']}）— "
                    . $reasoning);
            }

            return [
                'action'          => in_array($data['action'], ['hold', 'adjust', 'exit']) ? $data['action'] : 'hold',
                'strategy_state'  => in_array($data['strategy_state'] ?? null, ['valid', 'adjusted', 'uncertain', 'failed'], true)
                    ? $data['strategy_state']
                    : null,
                'strategy_issue'  => $data['strategy_issue'] ?? null,
                'adjusted_target' => isset($data['adjusted_target']) ? (float) $data['adjusted_target'] ?: null : null,
                'adjusted_stop'   => isset($data['adjusted_stop'])   ? (float) $data['adjusted_stop']   ?: null : null,
                'reasoning'       => $reasoning,
            ];
        } catch (\Exception $e) {
            Log::error("OvernightExitMonitor Sonnet {$symbol}: " . $e->getMessage());
            return $fallback;
        }
    }

    private function parseSonnetJson(string $text): mixed
    {
        $text = trim($text);
        $text = preg_replace('/^```json?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{[\s\S]*\}/u', $text, $match)) {
            $decoded = json_decode($match[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * 將「實際跳空 - 預測跳空」的差異分類為五段標籤。
     * public 方便測試；無實例狀態。
     */
    public static function classifyGapDiff(float $gapDiff): string
    {
        return match (true) {
            $gapDiff > 1.5  => '顯著超預期',
            $gapDiff > 0.5  => '小幅超預期',
            $gapDiff < -1.5 => '顯著不及預期',
            $gapDiff < -0.5 => '小幅偏弱（雜訊範圍）',
            default         => '符合預期',
        };
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

<?php

namespace App\Console\Commands;

use App\Models\Candidate;
use App\Models\CandidateMonitor;
use App\Models\CandidateResult;
use App\Models\DailyQuote;
use App\Models\IntradaySnapshot;
use Illuminate\Console\Command;

class UpdateCandidateResults extends Command
{
    protected $signature = 'stock:update-results {date?}';
    protected $description = '更新候選標的盤後實際結果';

    public function handle(): int
    {
        $date = $this->argument('date') ?? now()->format('Y-m-d');

        $candidates = Candidate::where('trade_date', $date)
            ->where('mode', 'intraday')
            ->whereDoesntHave('result')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info("日期 {$date} 無需更新的候選標的");
            return self::SUCCESS;
        }

        $count = 0;

        foreach ($candidates as $candidate) {
            $quote = DailyQuote::where('stock_id', $candidate->stock_id)
                ->where('date', $date)
                ->first();

            if (!$quote) continue;

            $open = (float) $quote->open;
            $high = (float) $quote->high;
            $low = (float) $quote->low;
            $close = (float) $quote->close;
            $suggestedBuy = (float) $candidate->suggested_buy;

            $targetPrice = (float) $candidate->target_price;
            $stopLoss = (float) $candidate->stop_loss;

            // Monitor 相關欄位
            $monitor = CandidateMonitor::where('candidate_id', $candidate->id)->first();
            $monitorData = $this->getMonitorData($candidate, $date, $monitor);

            // 若有 monitor 結果，以 monitor 狀態為準（因盤中 AI 可能調整目標/停損）
            if ($monitor && $monitor->status !== CandidateMonitor::STATUS_PENDING) {
                $hitTarget = $monitor->status === CandidateMonitor::STATUS_TARGET_HIT;
                $hitStopLoss = $monitor->status === CandidateMonitor::STATUS_STOP_HIT;
                // 用 monitor 最終目標/停損計算
                $effectiveTarget = (float) ($monitor->current_target ?? $targetPrice);
                $effectiveStop = (float) ($monitor->current_stop ?? $stopLoss);
                $buyReachable = in_array($monitor->status, [
                    CandidateMonitor::STATUS_HOLDING,
                    CandidateMonitor::STATUS_TARGET_HIT,
                    CandidateMonitor::STATUS_STOP_HIT,
                    CandidateMonitor::STATUS_TRAILING_STOP,
                    CandidateMonitor::STATUS_CLOSED,
                ]);
                $targetReachable = $hitTarget;
            } else {
                // 無 monitor 時用原始價格比對日 K
                $hitTarget = $high >= $targetPrice;
                $hitStopLoss = $low <= $stopLoss;
                $effectiveTarget = $targetPrice;
                $effectiveStop = $stopLoss;
                $buyReachable = $low <= $suggestedBuy;
                $targetReachable = $high >= $targetPrice;
            }

            $maxProfit = $suggestedBuy > 0
                ? round(($high - $suggestedBuy) / $suggestedBuy * 100, 2)
                : 0;
            $maxLoss = $suggestedBuy > 0
                ? round(($suggestedBuy - $low) / $suggestedBuy * 100, 2)
                : 0;

            $buyGap = $suggestedBuy > 0
                ? round(($suggestedBuy - $low) / $suggestedBuy * 100, 2)
                : 0;
            $targetGap = $effectiveTarget > 0
                ? round(($high - $effectiveTarget) / $effectiveTarget * 100, 2)
                : 0;

            CandidateResult::create([
                'candidate_id' => $candidate->id,
                'actual_open' => $open,
                'actual_high' => $high,
                'actual_low' => $low,
                'actual_close' => $close,
                'hit_target' => $hitTarget,
                'hit_stop_loss' => $hitStopLoss,
                'max_profit_percent' => $maxProfit,
                'max_loss_percent' => $maxLoss,
                'buy_reachable' => $buyReachable,
                'target_reachable' => $targetReachable,
                'buy_gap_percent' => $buyGap,
                'target_gap_percent' => $targetGap,
                ...$monitorData,
            ]);

            $count++;
        }

        $this->info("已更新 {$count} 筆候選標的結果");
        return self::SUCCESS;
    }

    /**
     * 從 CandidateMonitor + IntradaySnapshot 取得監控相關數據
     */
    private function getMonitorData(Candidate $candidate, string $date, ?CandidateMonitor $monitor = null): array
    {
        if (!$monitor) {
            return [];
        }

        $data = [
            'entry_time' => $monitor->entry_time,
            'exit_time' => $monitor->exit_time,
            'entry_price_actual' => $monitor->entry_price,
            'exit_price_actual' => $monitor->exit_price,
            'entry_type' => $monitor->entry_type,
            'monitor_status' => $monitor->status,
            'valid_entry' => in_array($monitor->status, [
                CandidateMonitor::STATUS_HOLDING,
                CandidateMonitor::STATUS_TARGET_HIT,
                CandidateMonitor::STATUS_STOP_HIT,
                CandidateMonitor::STATUS_TRAILING_STOP,
                CandidateMonitor::STATUS_CLOSED,
            ]),
        ];

        // 計算 MFE/MAE（從進場到出場期間的快照）
        if ($monitor->entry_price && $monitor->entry_time) {
            $entryPrice = (float) $monitor->entry_price;
            $exitTime = $monitor->exit_time ?? now();

            $snapshots = IntradaySnapshot::where('stock_id', $candidate->stock_id)
                ->where('trade_date', $date)
                ->whereBetween('snapshot_time', [$monitor->entry_time, $exitTime])
                ->get();

            if ($snapshots->isNotEmpty() && $entryPrice > 0) {
                $maxHigh = $snapshots->max(fn($s) => (float) $s->high);
                $minLow = $snapshots->min(fn($s) => (float) $s->low);

                $data['mfe_percent'] = round(($maxHigh - $entryPrice) / $entryPrice * 100, 2);
                $data['mae_percent'] = round(($entryPrice - $minLow) / $entryPrice * 100, 2);
            }
        }

        return $data;
    }
}

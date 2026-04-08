<?php

namespace App\Console\Commands;

use App\Models\Candidate;
use App\Models\CandidateResult;
use App\Models\DailyQuote;
use Illuminate\Console\Command;

class UpdateCandidateResults extends Command
{
    protected $signature = 'stock:update-results {date?}';
    protected $description = '更新候選標的盤後實際結果';

    public function handle(): int
    {
        $date = $this->argument('date') ?? now()->format('Y-m-d');

        $candidates = Candidate::where('trade_date', $date)
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

            $hitTarget = $high >= $targetPrice;
            $hitStopLoss = $low <= $stopLoss;

            $maxProfit = $suggestedBuy > 0
                ? round(($high - $suggestedBuy) / $suggestedBuy * 100, 2)
                : 0;
            $maxLoss = $suggestedBuy > 0
                ? round(($suggestedBuy - $low) / $suggestedBuy * 100, 2)
                : 0;

            // 回測指標
            $buyReachable = $low <= $suggestedBuy;
            $targetReachable = $high >= $targetPrice;
            $buyGap = $suggestedBuy > 0
                ? round(($suggestedBuy - $low) / $suggestedBuy * 100, 2)
                : 0;
            $targetGap = $targetPrice > 0
                ? round(($high - $targetPrice) / $targetPrice * 100, 2)
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
            ]);

            $count++;
        }

        $this->info("已更新 {$count} 筆候選標的結果");
        return self::SUCCESS;
    }
}

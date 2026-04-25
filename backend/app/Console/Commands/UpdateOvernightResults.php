<?php

namespace App\Console\Commands;

use App\Models\Candidate;
use App\Models\CandidateResult;
use App\Models\DailyQuote;
use App\Models\MarketHoliday;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateOvernightResults extends Command
{
    protected $signature = 'stock:update-overnight-results {date?}';
    protected $description = '更新隔日沖候選標的盤後實際結果（T+1 15:05 執行）';

    public function handle(): int
    {
        $tradeDate = $this->argument('date') ?? now()->format('Y-m-d');

        // 排程執行時跳過休市日
        if (!$this->argument('date') && MarketHoliday::isHoliday($tradeDate)) {
            $this->info("{$tradeDate} 為休市日，跳過");
            return self::SUCCESS;
        }

        $candidates = Candidate::where('trade_date', $tradeDate)
            ->where('mode', 'overnight')
            ->whereDoesntHave('result')
            ->with('stock')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info("日期 {$tradeDate} 無需更新的隔日沖候選標的");
            return self::SUCCESS;
        }

        $count = 0;

        foreach ($candidates as $candidate) {
            $quote = DailyQuote::where('stock_id', $candidate->stock_id)
                ->where('date', $tradeDate)
                ->first();

            if (!$quote) {
                $this->warn("{$candidate->stock->symbol} 找不到 {$tradeDate} 日K資料，跳過");
                continue;
            }

            $prevQuote = DailyQuote::where('stock_id', $candidate->stock_id)
                ->where('date', '<', $tradeDate)
                ->orderByDesc('date')
                ->first();

            $open      = (float) $quote->open;
            $high      = (float) $quote->high;
            $low       = (float) $quote->low;
            $close     = (float) $quote->close;
            $prevClose = $prevQuote ? (float) $prevQuote->close : $open;

            $suggestedBuy = (float) $candidate->suggested_buy;
            $targetPrice  = (float) $candidate->target_price;
            $stopLoss     = (float) $candidate->stop_loss;

            // 跳空缺口
            $openGapPct = $prevClose > 0
                ? round(($open - $prevClose) / $prevClose * 100, 2)
                : 0.0;

            // 跳空方向預測正確率
            $gapPredictedCorrectly = null;
            $entryType = $candidate->overnight_strategy;
            if ($entryType !== null) {
                $predictedGapUp       = in_array($entryType, ['gap_up_open', 'limit_up_chase']);
                $actualGapUp          = $openGapPct > 0.3;
                $gapPredictedCorrectly = ($predictedGapUp === $actualGapUp);
            }

            // 基本結果
            $hitTarget    = $suggestedBuy > 0 && $targetPrice > 0 && $high >= $targetPrice;
            $hitStopLoss  = $suggestedBuy > 0 && $stopLoss > 0 && $low <= $stopLoss;
            $buyReachable = $suggestedBuy > 0 && $low <= $suggestedBuy;

            $maxProfit = $suggestedBuy > 0
                ? round(($high - $suggestedBuy) / $suggestedBuy * 100, 2)
                : 0.0;
            $maxLoss = $suggestedBuy > 0
                ? round(($suggestedBuy - $low) / $suggestedBuy * 100, 2)
                : 0.0;

            // 隔日沖結果標籤
            $overnightOutcome = match(true) {
                $hitTarget             => 'hit_target',
                $hitStopLoss           => 'hit_stop',
                $openGapPct > 3.0      => 'gap_up_strong',
                $openGapPct > 0.5      => 'gap_up',
                $openGapPct < -2.0     => 'gap_down',
                $close > $prevClose    => 'up',
                $close < $prevClose    => 'down',
                default                => 'neutral',
            };

            CandidateResult::create([
                'candidate_id'            => $candidate->id,
                'actual_open'             => $open,
                'actual_high'             => $high,
                'actual_low'              => $low,
                'actual_close'            => $close,
                'hit_target'              => $hitTarget,
                'hit_stop_loss'           => $hitStopLoss,
                'max_profit_percent'      => $maxProfit,
                'max_loss_percent'        => $maxLoss,
                'buy_reachable'           => $buyReachable,
                'target_reachable'        => $hitTarget,
                'open_gap_percent'        => $openGapPct,
                'gap_predicted_correctly' => $gapPredictedCorrectly,
                'overnight_outcome'       => $overnightOutcome,
            ]);

            $count++;
            $this->line(sprintf(
                '  %s: 開%.2f(跳空%+.2f%%) 高%.2f 低%.2f 收%.2f → %s',
                $candidate->stock->symbol,
                $open, $openGapPct, $high, $low, $close,
                $overnightOutcome
            ));
        }

        $this->info("已更新 {$count} 筆隔日沖候選標的結果");
        Log::info("UpdateOvernightResults {$tradeDate}：更新 {$count} 筆");

        return self::SUCCESS;
    }
}

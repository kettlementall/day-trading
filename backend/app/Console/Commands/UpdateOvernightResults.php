<?php

namespace App\Console\Commands;

use App\Models\Candidate;
use App\Models\CandidateMonitor;
use App\Models\CandidateResult;
use App\Models\DailyQuote;
use App\Models\DividendEvent;
use App\Models\MarketHoliday;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateOvernightResults extends Command
{
    protected $signature = 'stock:update-overnight-results {date?} {--force-recompute : 強制重新計算所有欄位（不僅補齊 null），用於歷史回填}';
    protected $description = '更新隔日沖候選標的盤後實際結果（T+1 15:05 執行）';

    public function handle(): int
    {
        $tradeDate = $this->argument('date') ?? now()->format('Y-m-d');
        $force = (bool) $this->option('force-recompute');

        // 排程執行時跳過休市日
        if (!$this->argument('date') && MarketHoliday::isHoliday($tradeDate)) {
            $this->info("{$tradeDate} 為休市日，跳過");
            return self::SUCCESS;
        }

        $baseQuery = Candidate::where('trade_date', $tradeDate)->where('mode', 'overnight');

        if ($force) {
            $candidates = $baseQuery->with(['stock', 'result', 'monitor'])->get();
            $this->info("--force-recompute：重算 {$tradeDate} 全部 {$candidates->count()} 筆 overnight 候選");
        } else {
            $candidates = $baseQuery
                ->where(function ($query) {
                    $query->whereDoesntHave('result')
                        ->orWhereHas('result', function ($resultQuery) {
                            $resultQuery
                                ->whereNull('overnight_outcome')
                                ->orWhereNull('open_gap_percent');
                        })
                        ->orWhere(function ($candidateQuery) {
                            $candidateQuery
                                ->whereNotNull('overnight_strategy')
                                ->whereHas('result', function ($resultQuery) {
                                    $resultQuery->whereNull('gap_predicted_correctly');
                                });
                        })
                        ->orWhere(function ($candidateQuery) {
                            $candidateQuery
                                ->whereHas('monitor', function ($monitorQuery) {
                                    $monitorQuery->whereIn('status', CandidateMonitor::TERMINAL_STATUSES);
                                })
                                ->where(function ($needsMonitorBackfill) {
                                    $needsMonitorBackfill
                                        ->whereHas('result', function ($resultQuery) {
                                            $resultQuery->whereNull('monitor_status');
                                        })
                                        ->orWhere(function ($needsEntryBackfill) {
                                            $needsEntryBackfill
                                                ->whereHas('monitor', function ($monitorQuery) {
                                                    $monitorQuery->where('status', '!=', CandidateMonitor::STATUS_SKIPPED);
                                                })
                                                ->whereHas('result', function ($resultQuery) {
                                                    $resultQuery->whereNull('entry_price_actual');
                                                });
                                        });
                                });
                        })
                        ->orWhere(function ($candidateQuery) {
                            $candidateQuery
                                ->whereHas('monitor', function ($monitorQuery) {
                                    $monitorQuery->whereNotNull('exit_price');
                                })
                                ->whereHas('result', function ($resultQuery) {
                                    $resultQuery->whereNull('exit_price_actual');
                                });
                        });
                })
                ->with(['stock', 'result', 'monitor'])
                ->get();
        }

        if ($candidates->isEmpty()) {
            $this->info("日期 {$tradeDate} 無需更新的隔日沖候選標的");
            return self::SUCCESS;
        }

        // 批次預載 T+0 / T-1 日 K（trade_date 之前最近兩筆，避免 N+1）
        $stockIds = $candidates->pluck('stock_id')->unique()->all();
        $recentQuotesByStock = DailyQuote::whereIn('stock_id', $stockIds)
            ->where('date', '<', $tradeDate)
            ->orderBy('stock_id')
            ->orderByDesc('date')
            ->get()
            ->groupBy('stock_id');

        // T+1（trade_date 當日）日 K 也批次預載
        $t1QuotesByStock = DailyQuote::whereIn('stock_id', $stockIds)
            ->where('date', $tradeDate)
            ->get()
            ->keyBy('stock_id');

        $count = 0;

        foreach ($candidates as $candidate) {
            $quote = $t1QuotesByStock->get($candidate->stock_id);

            if (!$quote) {
                $this->warn("{$candidate->stock->symbol} 找不到 {$tradeDate} 日K資料，跳過");
                continue;
            }

            $stockHistory = $recentQuotesByStock->get($candidate->stock_id, collect());
            $prevQuote = $stockHistory->first();    // T+0
            $tMinus1   = $stockHistory->skip(1)->first(); // T-1（用來算 T+0 漲幅判斷鎖漲停）

            $open      = (float) $quote->open;
            $high      = (float) $quote->high;
            $low       = (float) $quote->low;
            $close     = (float) $quote->close;
            $prevClose = $prevQuote ? (float) $prevQuote->close : $open;

            // 除權息校正：T+1（=$tradeDate）若為除息日，prevClose（T+0 收盤）是除息前價，
            // 直接算 gap 會把除權息的價格調整誤判成假跳空，污染 gap_predicted_correctly / avg_open_gap 統計。
            // 改用除息參考價當基準。（符合「假數據不可進回測」原則）
            $dividend = DividendEvent::onDate($candidate->stock_id, $tradeDate);
            if ($dividend && $prevClose > 0) {
                $prevClose = $dividend->referenceCloseFrom($prevClose);
            }

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

            // T+0 是否鎖死漲停（建倉日盤後）→ 物理上無法以 suggested_buy 成交
            $t0LimitUpLocked = self::isT0LimitUpLocked($prevQuote, $tMinus1);
            $unreachableReason = $t0LimitUpLocked ? 't0_limit_up_locked' : null;

            // 基本結果
            $hitTarget    = $suggestedBuy > 0 && $targetPrice > 0 && $high >= $targetPrice;
            $hitStopLoss  = $suggestedBuy > 0 && $stopLoss > 0 && $low <= $stopLoss;
            $buyReachable = $suggestedBuy > 0 && $low <= $suggestedBuy && !$t0LimitUpLocked;

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

            $payload = [
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
                'unreachable_reason'      => $unreachableReason,
                'target_reachable'        => $hitTarget,
                'open_gap_percent'        => $openGapPct,
                'gap_predicted_correctly' => $gapPredictedCorrectly,
                'overnight_outcome'       => $overnightOutcome,
                ...$this->getMonitorPayload($candidate, $suggestedBuy),
            ];

            CandidateResult::updateOrCreate(
                ['candidate_id' => $candidate->id],
                $payload
            );

            $count++;
            $this->line(sprintf(
                '  %s: 開%.2f(跳空%+.2f%%) 高%.2f 低%.2f 收%.2f → %s%s',
                $candidate->stock->symbol,
                $open, $openGapPct, $high, $low, $close,
                $overnightOutcome,
                $t0LimitUpLocked ? '  ⚠ T+0鎖漲停(buy_reachable=false)' : ''
            ));
        }

        $this->info("已建立或補齊 {$count} 筆隔日沖候選標的結果");
        Log::info("UpdateOvernightResults {$tradeDate}：更新 {$count} 筆");

        return self::SUCCESS;
    }

    /**
     * 判斷 T+0（建倉日）是否鎖死漲停 → 物理上無法以 suggested_buy 成交買進。
     * 條件：T+0 漲幅 ≥ 9.5% AND T+0 收盤 = 日高 AND T+0 收盤 > 日低（排除一字漲停跌停同價的退市股噪音）
     */
    private static function isT0LimitUpLocked(?DailyQuote $t0, ?DailyQuote $tMinus1): bool
    {
        if (!$t0 || !$tMinus1) {
            return false;
        }

        $prevClose = (float) $tMinus1->close;
        if ($prevClose <= 0) {
            return false;
        }

        $t0Close = (float) $t0->close;
        $t0High  = (float) $t0->high;
        $t0Low   = (float) $t0->low;
        $changePct = ($t0Close - $prevClose) / $prevClose * 100;

        return $changePct >= 9.5
            && abs($t0Close - $t0High) < 0.01
            && $t0Close > $t0Low + 0.01;
    }

    private function getMonitorPayload(Candidate $candidate, float $suggestedBuy): array
    {
        $monitor = $candidate->monitor;

        if (!$monitor) {
            return [];
        }

        $entryPrice = (float) ($monitor->entry_price ?? 0);
        if ($entryPrice <= 0 && $suggestedBuy > 0) {
            $entryPrice = $suggestedBuy;
        }

        $exitPrice = (float) ($monitor->exit_price ?? 0);

        return [
            'entry_time'         => $monitor->entry_time,
            'exit_time'          => $monitor->exit_time,
            'entry_price_actual' => $entryPrice > 0 ? $entryPrice : null,
            'exit_price_actual'  => $exitPrice > 0 ? $exitPrice : null,
            'entry_type'         => $monitor->entry_type,
            'monitor_status'     => $monitor->status,
            'valid_entry'        => in_array($monitor->status, [
                CandidateMonitor::STATUS_HOLDING,
                CandidateMonitor::STATUS_TARGET_HIT,
                CandidateMonitor::STATUS_STOP_HIT,
                CandidateMonitor::STATUS_TRAILING_STOP,
                CandidateMonitor::STATUS_CLOSED,
            ]),
        ];
    }
}

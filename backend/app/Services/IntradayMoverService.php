<?php

namespace App\Services;

use App\Helpers\PriceUtil;
use App\Models\Candidate;
use App\Models\CandidateMonitor;
use App\Models\DailyQuote;
use App\Models\Stock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IntradayMoverService
{
    /**
     * 選出待掃描池：4 條軸聯集 + 5 日內單日 ≥5% 條件，排除今日已在 candidates
     *
     * @return Collection<Stock>
     */
    public function selectScanPool(string $tradeDate, int $topN = 100): Collection
    {
        // 取前一交易日（不一定是 tradeDate - 1，可能跨週末）
        $prevDate = DailyQuote::where('date', '<', $tradeDate)
            ->orderByDesc('date')
            ->value('date');

        if (!$prevDate) {
            Log::warning("IntradayMoverService: 找不到 {$tradeDate} 之前的交易日");
            return collect();
        }

        // 軸 1：前一日漲幅 top N
        $axis1 = DailyQuote::where('date', $prevDate)
            ->orderByDesc('change_percent')
            ->limit($topN)
            ->pluck('stock_id');

        // 軸 2：前一日量比 (volume / avg5_vol) top N
        // 用子查詢算 5 日均量
        $axis2 = DB::table('daily_quotes as dq')
            ->select('dq.stock_id')
            ->where('dq.date', $prevDate)
            ->where('dq.volume', '>', 0)
            ->joinSub(
                DB::table('daily_quotes')
                    ->select('stock_id', DB::raw('AVG(volume) as avg5_vol'))
                    ->where('date', '<', $prevDate)
                    ->where('date', '>=', DB::raw("DATE_SUB('{$prevDate}', INTERVAL 10 DAY)"))
                    ->groupBy('stock_id'),
                'avg',
                'avg.stock_id', '=', 'dq.stock_id'
            )
            ->where('avg.avg5_vol', '>', 0)
            ->orderByDesc(DB::raw('dq.volume / avg.avg5_vol'))
            ->limit($topN)
            ->pluck('stock_id');

        // 軸 3：5 日均量 top N
        $axis3 = DB::table('daily_quotes')
            ->select('stock_id')
            ->where('date', '<=', $prevDate)
            ->where('date', '>=', DB::raw("DATE_SUB('{$prevDate}', INTERVAL 10 DAY)"))
            ->groupBy('stock_id')
            ->orderByDesc(DB::raw('AVG(volume)'))
            ->limit($topN)
            ->pluck('stock_id');

        // 軸 4：5 日累計漲幅 top N
        $fiveDaysAgo = DB::table('daily_quotes')
            ->where('date', '<=', $prevDate)
            ->orderByDesc('date')
            ->skip(4)
            ->value('date');

        $axis4 = collect();
        if ($fiveDaysAgo) {
            $axis4 = DB::table('daily_quotes')
                ->select('stock_id', DB::raw('SUM(change_percent) as cum_change'))
                ->where('date', '>', $fiveDaysAgo)
                ->where('date', '<=', $prevDate)
                ->groupBy('stock_id')
                ->orderByDesc('cum_change')
                ->limit($topN)
                ->pluck('stock_id');
        }

        // 軸 5：5 日內單日漲幅 ≥5% 的所有標的
        $axis5 = DailyQuote::where('date', '<=', $prevDate)
            ->where('date', '>=', DB::raw("DATE_SUB('{$prevDate}', INTERVAL 10 DAY)"))
            ->where('change_percent', '>=', 5.0)
            ->distinct()
            ->pluck('stock_id');

        // 聯集
        $allIds = $axis1->merge($axis2)->merge($axis3)->merge($axis4)->merge($axis5)->unique()->values();

        // 排除今日已在 candidates（所有 mode=intraday 的，不只 ai_selected）
        $existingIds = Candidate::where('trade_date', $tradeDate)
            ->where('mode', 'intraday')
            ->pluck('stock_id');

        $poolIds = $allIds->diff($existingIds);

        // 只取可當沖 + 有 industry 的標的
        return Stock::whereIn('id', $poolIds)
            ->where('is_day_trading', true)
            ->whereNotNull('industry')
            ->where('industry', '!=', '')
            ->get();
    }

    /**
     * 規則過濾：4 條（漲幅 / 量比 / 漲停距離 / 外盤比）
     *
     * @param Collection<Stock> $pool
     * @param array<string, array> $quotes keyed by symbol (from FugleRealtimeClient::fetchQuotes)
     * @param array $thresholds from FormulaSetting
     * @return Collection<Stock>
     */
    public function filterByLiveQuote(Collection $pool, array $quotes, array $thresholds): Collection
    {
        $minChangePct    = (float) ($thresholds['min_change_pct'] ?? 3.0);
        $minVolRatio     = (float) ($thresholds['min_vol_ratio'] ?? 1.5);
        $limitUpBuffer   = (float) ($thresholds['limit_up_buffer_pct'] ?? 1.5);
        $minExternal     = (float) ($thresholds['min_external_ratio'] ?? 55);

        return $pool->filter(function (Stock $stock) use ($quotes, $minChangePct, $minVolRatio, $limitUpBuffer, $minExternal) {
            $q = $quotes[$stock->symbol] ?? null;
            if (!$q || ($q['current_price'] ?? 0) <= 0) return false;

            $prevClose = (float) ($q['prev_close'] ?? 0);
            $current   = (float) $q['current_price'];
            if ($prevClose <= 0) return false;

            // a. 漲幅 ≥ min_change_pct
            $changePct = ($current - $prevClose) / $prevClose * 100;
            if ($changePct < $minChangePct) return false;

            // b. 量比 ≥ min_vol_ratio（盤中累計量 / 同時段 5 日均量估算）
            $accVol = (int) ($q['accumulated_volume'] ?? 0);
            $avg5Vol = $this->getAvg5DayVolume($stock->id);
            if ($avg5Vol > 0) {
                // 按時段比例推估：09:00-13:30 = 270 分鐘，盤中已過多少分鐘
                $elapsedMin = max(1, (now()->hour * 60 + now()->minute) - 540); // 540 = 09:00
                $dayRatio = $elapsedMin / 270;
                $expectedVol = $avg5Vol * $dayRatio;
                if ($expectedVol > 0 && ($accVol / $expectedVol) < $minVolRatio) return false;
            }

            // c. 不在漲停價 ±buffer
            $limitUpPrice = $prevClose * 1.10;
            if ($limitUpPrice > 0 && $current >= $limitUpPrice * (1 - $limitUpBuffer / 100)) return false;

            // d. external_ratio ≥ min_external_ratio
            $askVol = (int) ($q['trade_volume_at_ask'] ?? 0);
            $bidVol = (int) ($q['trade_volume_at_bid'] ?? 0);
            $totalTrade = $askVol + $bidVol;
            $extRatio = $totalTrade > 0 ? ($askVol / $totalTrade * 100) : 0;
            if ($extRatio < $minExternal) return false;

            return true;
        })->values();
    }

    /**
     * 計算進場價位
     */
    public function calcEntryPrices(array $quote, float $prevClose): array
    {
        $current = (float) $quote['current_price'];
        $limitUp = PriceUtil::limitUp($prevClose);
        $limitDown = PriceUtil::limitDown($prevClose);

        $suggestedBuy = PriceUtil::tickRound($current, $current);

        // target = max(current × 1.03, prevClose × 1.07)，clamp 到漲停
        $target = max($current * 1.03, $prevClose * 1.07);
        $target = PriceUtil::tickRound(min($target, $limitUp), $current, 'down');

        // stop = current × 0.97，clamp 到跌停
        $stop = $current * 0.97;
        $stop = PriceUtil::tickRound(max($stop, $limitDown), $current, 'up');

        $reward = $target - $suggestedBuy;
        $risk = $suggestedBuy - $stop;
        $rr = $risk > 0 ? round($reward / $risk, 2) : 0;

        return [
            'suggested_buy' => round($suggestedBuy, 2),
            'target_price'  => round($target, 2),
            'stop_loss'     => round($stop, 2),
            'risk_reward'   => $rr,
        ];
    }

    /**
     * 組裝 Candidate 並寫入
     */
    public function assembleCandidate(
        Stock $stock,
        array $entryPrices,
        array $haikuResult,
        string $tradeDate
    ): Candidate {
        return Candidate::create([
            'stock_id'          => $stock->id,
            'trade_date'        => $tradeDate,
            'mode'              => 'intraday',
            'source'            => 'intraday_mover',
            'intraday_added_at' => now(),
            'suggested_buy'     => $entryPrices['suggested_buy'],
            'target_price'      => $entryPrices['target_price'],
            'stop_loss'         => $entryPrices['stop_loss'],
            'risk_reward_ratio' => $entryPrices['risk_reward'],
            'score'             => $haikuResult['confidence'] ?? 60,
            'haiku_selected'    => true,
            'haiku_reasoning'   => $haikuResult['reason'] ?? '',
            'ai_selected'       => true,
            'morning_grade'     => 'B',
            'morning_confirmed' => true,
            'intraday_strategy' => $haikuResult['strategy'] ?? 'momentum',
            'reasons'           => ['盤中加入', $haikuResult['reason'] ?? ''],
        ]);
    }

    /**
     * 同步建立 CandidateMonitor（status=watching），避免 PENDING 陷阱
     */
    public function assembleMonitor(Candidate $candidate, array $entryPrices): CandidateMonitor
    {
        return CandidateMonitor::create([
            'candidate_id'  => $candidate->id,
            'status'        => CandidateMonitor::STATUS_WATCHING,
            'current_target' => $entryPrices['target_price'],
            'current_stop'  => $entryPrices['stop_loss'],
        ]);
    }

    /**
     * 取近 5 日均量（股數）
     */
    private function getAvg5DayVolume(int $stockId): float
    {
        return (float) DailyQuote::where('stock_id', $stockId)
            ->orderByDesc('date')
            ->limit(5)
            ->avg('volume');
    }
}

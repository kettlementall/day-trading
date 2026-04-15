<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\CandidateResult;
use App\Models\DailyQuote;
use App\Models\StrategyPerformanceStat;
use App\Models\UsMarketIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StrategyStatsService
{
    /**
     * 計算並儲存所有統計維度
     *
     * @param int[] $periods 滾動天數，預設 [30, 60]
     */
    public function compute(array $periods = [30, 60]): void
    {
        foreach (['intraday', 'overnight'] as $mode) {
            foreach ($periods as $days) {
                $since = now()->subDays($days)->toDateString();

                Log::info("StrategyStatsService: computing mode={$mode} period={$days}d since={$since}");

                $this->computeStrategyDimension($mode, $days, $since);
                $this->computeFeatureDimension($mode, $days, $since);
                $this->computeMarketConditionDimension($mode, $days, $since);
            }
        }

        Log::info('StrategyStatsService: 計算完成');
    }

    // -------------------------------------------------------------------------
    // 策略類型維度
    // -------------------------------------------------------------------------

    private function computeStrategyDimension(string $mode, int $days, string $since): void
    {
        $strategyColumn = $mode === 'overnight' ? 'overnight_strategy' : 'intraday_strategy';

        $rows = DB::table('candidates as c')
            ->join('candidate_results as r', 'r.candidate_id', '=', 'c.id')
            ->where('c.mode', $mode)
            ->where('c.trade_date', '>=', $since)
            ->whereNotNull("c.{$strategyColumn}")
            ->where('r.buy_reachable', true)
            ->select(
                "c.{$strategyColumn} as strategy",
                DB::raw('COUNT(*) as sample_count'),
                DB::raw('AVG(CASE WHEN r.target_reachable = 1 THEN 1.0 ELSE 0.0 END) * 100 as target_reach_rate'),
                DB::raw('AVG(CASE
                    WHEN r.target_reachable = 1 THEN (c.target_price - c.suggested_buy) / c.suggested_buy * 100
                    WHEN r.hit_stop_loss = 1 THEN (c.stop_loss - c.suggested_buy) / c.suggested_buy * 100
                    ELSE (r.actual_close - c.suggested_buy) / c.suggested_buy * 100
                END) as expected_value'),
                DB::raw('AVG(c.risk_reward_ratio) as avg_risk_reward')
            )
            ->groupBy("c.{$strategyColumn}")
            ->get();

        foreach ($rows as $row) {
            StrategyPerformanceStat::updateOrCreate(
                [
                    'mode'            => $mode,
                    'dimension_type'  => 'strategy',
                    'dimension_value' => $row->strategy,
                    'period_days'     => $days,
                ],
                [
                    'sample_count'      => $row->sample_count,
                    'target_reach_rate' => round($row->target_reach_rate, 2),
                    'expected_value'    => round($row->expected_value, 2),
                    'avg_risk_reward'   => round($row->avg_risk_reward ?? 0, 2),
                    'computed_at'       => now(),
                ]
            );
        }
    }

    // -------------------------------------------------------------------------
    // 特徵組合維度（從 reasons JSON 陣列解析）
    // -------------------------------------------------------------------------

    private function computeFeatureDimension(string $mode, int $days, string $since): void
    {
        // 定義要統計的特徵組合
        $featureSets = [
            ['爆量', '外資買超'],
            ['爆量', '投信買超'],
            ['爆量'],
            ['外資買超'],
            ['投信買超'],
            ['外資買超', '投信買超'],
            ['突破前高'],
            ['法人連買3日'],
            ['蓄勢整理'],
            ['強勢排列'],
        ];

        // 取出期間內有結果的候選
        $candidates = DB::table('candidates as c')
            ->join('candidate_results as r', 'r.candidate_id', '=', 'c.id')
            ->where('c.mode', $mode)
            ->where('c.trade_date', '>=', $since)
            ->where('r.buy_reachable', true)
            ->select(
                'c.id', 'c.reasons', 'c.target_price', 'c.suggested_buy',
                'c.stop_loss', 'r.target_reachable', 'r.hit_stop_loss', 'r.actual_close'
            )
            ->get();

        foreach ($featureSets as $features) {
            $label = implode('+', $features);

            $matched = $candidates->filter(function ($row) use ($features) {
                $reasons = json_decode($row->reasons, true) ?? [];
                foreach ($features as $f) {
                    if (!in_array($f, $reasons)) return false;
                }
                return true;
            });

            if ($matched->isEmpty()) continue;

            $sampleCount = $matched->count();
            $targetReachRate = $matched->where('target_reachable', 1)->count() / $sampleCount * 100;

            $expectedValue = $matched->avg(function ($row) {
                $buy  = (float) $row->suggested_buy;
                $tgt  = (float) $row->target_price;
                $stop = (float) $row->stop_loss;
                $close = (float) $row->actual_close;
                if ($buy <= 0) return 0;
                if ($row->target_reachable) return ($tgt - $buy) / $buy * 100;
                if ($row->hit_stop_loss)    return ($stop - $buy) / $buy * 100;
                return ($close - $buy) / $buy * 100;
            });

            StrategyPerformanceStat::updateOrCreate(
                [
                    'mode'            => $mode,
                    'dimension_type'  => 'feature',
                    'dimension_value' => $label,
                    'period_days'     => $days,
                ],
                [
                    'sample_count'      => $sampleCount,
                    'target_reach_rate' => round($targetReachRate, 2),
                    'expected_value'    => round($expectedValue ?? 0, 2),
                    'avg_risk_reward'   => 0,
                    'computed_at'       => now(),
                ]
            );
        }
    }

    // -------------------------------------------------------------------------
    // 大盤環境維度
    // -------------------------------------------------------------------------

    private function computeMarketConditionDimension(string $mode, int $days, string $since): void
    {
        // 取期間內所有有結果的候選
        $candidates = DB::table('candidates as c')
            ->join('candidate_results as r', 'r.candidate_id', '=', 'c.id')
            ->where('c.mode', $mode)
            ->where('c.trade_date', '>=', $since)
            ->where('r.buy_reachable', true)
            ->select(
                'c.trade_date', 'c.target_price', 'c.suggested_buy', 'c.stop_loss',
                'r.target_reachable', 'r.hit_stop_loss', 'r.actual_close'
            )
            ->get();

        // 取大盤漲跌（用台指期夜盤 change_percent 作為 proxy）
        $marketChanges = UsMarketIndex::where('date', '>=', $since)
            ->where('symbol', 'TX')
            ->pluck('change_percent', 'date')
            ->map(fn($v) => (float) $v);

        $buckets = [
            '大盤>+1%'   => fn($v) => $v > 1.0,
            '大盤-1~+1%' => fn($v) => $v >= -1.0 && $v <= 1.0,
            '大盤<-1%'   => fn($v) => $v < -1.0,
        ];

        foreach ($buckets as $label => $condition) {
            $matched = $candidates->filter(function ($row) use ($marketChanges, $condition) {
                $date = $row->trade_date;
                if (!isset($marketChanges[$date])) return false;
                return $condition((float) $marketChanges[$date]);
            });

            if ($matched->isEmpty()) continue;

            $sampleCount = $matched->count();
            $targetReachRate = $matched->where('target_reachable', 1)->count() / $sampleCount * 100;

            $expectedValue = $matched->avg(function ($row) {
                $buy  = (float) $row->suggested_buy;
                $tgt  = (float) $row->target_price;
                $stop = (float) $row->stop_loss;
                $close = (float) $row->actual_close;
                if ($buy <= 0) return 0;
                if ($row->target_reachable) return ($tgt - $buy) / $buy * 100;
                if ($row->hit_stop_loss)    return ($stop - $buy) / $buy * 100;
                return ($close - $buy) / $buy * 100;
            });

            StrategyPerformanceStat::updateOrCreate(
                [
                    'mode'            => $mode,
                    'dimension_type'  => 'market_condition',
                    'dimension_value' => $label,
                    'period_days'     => $days,
                ],
                [
                    'sample_count'      => $sampleCount,
                    'target_reach_rate' => round($targetReachRate, 2),
                    'expected_value'    => round($expectedValue ?? 0, 2),
                    'avg_risk_reward'   => 0,
                    'computed_at'       => now(),
                ]
            );
        }
    }
}

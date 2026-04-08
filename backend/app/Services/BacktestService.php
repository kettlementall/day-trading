<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\CandidateResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BacktestService
{
    /**
     * 計算回測指標
     */
    public function computeMetrics(string $from, string $to, ?string $strategyType = null): array
    {
        $query = Candidate::whereBetween('trade_date', [$from, $to])
            ->whereHas('result');

        if ($strategyType) {
            $query->where('strategy_type', $strategyType);
        }

        $candidates = $query->with('result')->get();
        $total = Candidate::whereBetween('trade_date', [$from, $to])
            ->when($strategyType, fn ($q) => $q->where('strategy_type', $strategyType))
            ->count();

        $metrics = $this->calcMetricsFromCollection($candidates, $total);
        $metrics['period'] = ['from' => $from, 'to' => $to];

        // 按策略分類（僅在非篩選模式下）
        if (!$strategyType) {
            $metrics['by_strategy'] = [];
            foreach (['bounce', 'breakout'] as $type) {
                $subset = $candidates->where('strategy_type', $type);
                $subsetTotal = Candidate::whereBetween('trade_date', [$from, $to])
                    ->where('strategy_type', $type)->count();
                if ($subset->isNotEmpty()) {
                    $metrics['by_strategy'][$type] = $this->calcMetricsFromCollection($subset, $subsetTotal);
                }
            }

            // 日別趨勢
            $metrics['daily'] = $this->calcDailyTrend($from, $to);
        }

        return $metrics;
    }

    /**
     * 從候選集合計算核心指標
     */
    private function calcMetricsFromCollection(Collection $candidates, int $totalCandidates): array
    {
        $evaluated = $candidates->count();

        if ($evaluated === 0) {
            return [
                'total_candidates' => $totalCandidates,
                'evaluated' => 0,
                'buy_reach_rate' => 0,
                'target_reach_rate' => 0,
                'dual_reach_rate' => 0,
                'expected_value' => 0,
                'hit_stop_loss_rate' => 0,
                'avg_buy_gap' => 0,
                'avg_target_gap' => 0,
                'avg_risk_reward' => 0,
            ];
        }

        $buyReachable = $candidates->filter(fn ($c) => $c->result->buy_reachable)->count();
        $targetReachable = $candidates->filter(fn ($c) => $c->result->target_reachable)->count();
        $dualReachable = $candidates->filter(
            fn ($c) => $c->result->buy_reachable && $c->result->target_reachable
        )->count();
        $hitStopLoss = $candidates->filter(fn ($c) => $c->result->hit_stop_loss)->count();

        // 期望值計算
        $profits = [];
        foreach ($candidates as $c) {
            $r = $c->result;
            $suggestedBuy = (float) $c->suggested_buy;
            if ($suggestedBuy <= 0 || !$r->buy_reachable) continue;

            if ($r->buy_reachable && $r->target_reachable) {
                // 買到且達標：以目標價計算獲利
                $profits[] = ((float) $c->target_price - $suggestedBuy) / $suggestedBuy * 100;
            } elseif ($r->buy_reachable && $r->hit_stop_loss) {
                // 買到但觸停損
                $profits[] = -($suggestedBuy - (float) $c->stop_loss) / $suggestedBuy * 100;
            } elseif ($r->buy_reachable) {
                // 買到但未達標也未停損：以收盤價計算
                $profits[] = ((float) $r->actual_close - $suggestedBuy) / $suggestedBuy * 100;
            }
        }

        $expectedValue = count($profits) > 0 ? round(array_sum($profits) / count($profits), 2) : 0;

        return [
            'total_candidates' => $totalCandidates,
            'evaluated' => $evaluated,
            'buy_reach_rate' => round($buyReachable / $evaluated * 100, 1),
            'target_reach_rate' => round($targetReachable / $evaluated * 100, 1),
            'dual_reach_rate' => round($dualReachable / $evaluated * 100, 1),
            'expected_value' => $expectedValue,
            'hit_stop_loss_rate' => round($hitStopLoss / $evaluated * 100, 1),
            'avg_buy_gap' => round($candidates->avg(fn ($c) => (float) $c->result->buy_gap_percent), 2),
            'avg_target_gap' => round($candidates->avg(fn ($c) => (float) $c->result->target_gap_percent), 2),
            'avg_risk_reward' => round($candidates->avg(fn ($c) => (float) $c->risk_reward_ratio), 2),
        ];
    }

    /**
     * 計算日別趨勢資料（供圖表使用）
     */
    private function calcDailyTrend(string $from, string $to): array
    {
        $rows = DB::table('candidates as c')
            ->join('candidate_results as cr', 'cr.candidate_id', '=', 'c.id')
            ->whereBetween('c.trade_date', [$from, $to])
            ->select(
                'c.trade_date as date',
                DB::raw('COUNT(*) as evaluated'),
                DB::raw('SUM(cr.buy_reachable) as buy_reach'),
                DB::raw('SUM(cr.target_reachable) as target_reach'),
                DB::raw('SUM(cr.buy_reachable AND cr.target_reachable) as dual_reach'),
            )
            ->groupBy('c.trade_date')
            ->orderBy('c.trade_date')
            ->get();

        return $rows->map(fn ($row) => [
            'date' => $row->date,
            'evaluated' => $row->evaluated,
            'buy_reach_rate' => $row->evaluated > 0 ? round($row->buy_reach / $row->evaluated * 100, 1) : 0,
            'target_reach_rate' => $row->evaluated > 0 ? round($row->target_reach / $row->evaluated * 100, 1) : 0,
            'dual_reach_rate' => $row->evaluated > 0 ? round($row->dual_reach / $row->evaluated * 100, 1) : 0,
        ])->toArray();
    }
}

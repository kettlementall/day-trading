<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\CandidateResult;
use App\Models\DailyQuote;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        $candidates = $query->with(['result', 'monitor'])->get();
        $total = Candidate::whereBetween('trade_date', [$from, $to])
            ->when($strategyType, fn ($q) => $q->where('strategy_type', $strategyType))
            ->count();

        $metrics = $this->calcMetricsFromCollection($candidates, $total);
        $metrics['period'] = ['from' => $from, 'to' => $to];

        // 選股品質指標
        $metrics['screening'] = $this->calcScreeningMetrics($candidates);

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

        $metrics = [
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

        // 監控系統指標（有 monitor 資料時才計算）
        $withMonitor = $candidates->filter(fn($c) => $c->result->monitor_status !== null);
        if ($withMonitor->isNotEmpty()) {
            $validEntries = $withMonitor->filter(fn($c) => $c->result->valid_entry);
            $aiSelected = $candidates->filter(fn($c) => $c->ai_selected);
            $withMfe = $withMonitor->filter(fn($c) => (float) $c->result->mfe_percent > 0 || (float) $c->result->mae_percent > 0);

            $metrics['valid_entry_rate'] = round($validEntries->count() / $withMonitor->count() * 100, 1);
            $metrics['ai_approval_rate'] = $candidates->count() > 0
                ? round($aiSelected->count() / $candidates->count() * 100, 1)
                : 0;
            $metrics['avg_mfe'] = $withMfe->isNotEmpty()
                ? round($withMfe->avg(fn($c) => (float) $c->result->mfe_percent), 2)
                : 0;
            $metrics['avg_mae'] = $withMfe->isNotEmpty()
                ? round($withMfe->avg(fn($c) => (float) $c->result->mae_percent), 2)
                : 0;

            // 走弱到價避開率
            $skippedWeak = $withMonitor->filter(fn($c) => $c->result->monitor_status === 'skipped');
            $metrics['weak_to_price_rate'] = $withMonitor->count() > 0
                ? round($skippedWeak->count() / $withMonitor->count() * 100, 1)
                : 0;

            // 只算有效進場的期望值
            if ($validEntries->isNotEmpty()) {
                $validProfits = [];
                foreach ($validEntries as $c) {
                    $r = $c->result;
                    $entry = (float) $r->entry_price_actual;
                    $exit = (float) $r->exit_price_actual;
                    if ($entry > 0 && $exit > 0) {
                        $validProfits[] = ($exit - $entry) / $entry * 100;
                    }
                }
                $metrics['profit_if_valid_entry'] = count($validProfits) > 0
                    ? round(array_sum($validProfits) / count($validProfits), 2)
                    : 0;
            }

            // 平均持有時間（分鐘）
            $withHolding = $validEntries->filter(fn($c) => $c->result->entry_time && $c->result->exit_time);
            if ($withHolding->isNotEmpty()) {
                $metrics['avg_holding_minutes'] = round($withHolding->avg(function ($c) {
                    return $c->result->entry_time->diffInMinutes($c->result->exit_time);
                }), 0);
            }

            // AI 介入準確率：AI 調整目標/停損後結果是否改善
            $aiOverrides = $withMonitor->filter(function ($c) {
                $monitor = $c->monitor;
                return $monitor && !empty($monitor->ai_advice_log)
                    && collect($monitor->ai_advice_log)->contains(fn($a) => ($a['action'] ?? '') !== 'hold');
            });
            if ($aiOverrides->isNotEmpty()) {
                $correctOverrides = $aiOverrides->filter(function ($c) {
                    $r = $c->result;
                    return in_array($r->monitor_status, ['target_hit', 'trailing_stop']);
                });
                $metrics['ai_override_accuracy'] = round($correctOverrides->count() / $aiOverrides->count() * 100, 1);
            }

            // 改良版風報比 effective_rr
            $targetHits = $validEntries->filter(fn($c) => $c->result->monitor_status === 'target_hit');
            $stopHits = $validEntries->filter(fn($c) => $c->result->monitor_status === 'stop_hit');
            if ($targetHits->isNotEmpty() && $stopHits->isNotEmpty()) {
                $targetHitRate = $targetHits->count() / $validEntries->count();
                $stopHitRate = $stopHits->count() / $validEntries->count();
                $avgProfitPerHit = $targetHits->avg(function ($c) {
                    $entry = (float) $c->result->entry_price_actual;
                    $exit = (float) $c->result->exit_price_actual;
                    return $entry > 0 ? ($exit - $entry) / $entry * 100 : 0;
                });
                $avgLossPerHit = $stopHits->avg(function ($c) {
                    $entry = (float) $c->result->entry_price_actual;
                    $exit = (float) $c->result->exit_price_actual;
                    return $entry > 0 ? ($entry - $exit) / $entry * 100 : 0;
                });
                if ($stopHitRate > 0 && $avgLossPerHit > 0) {
                    $metrics['effective_rr'] = round(
                        ($targetHitRate * $avgProfitPerHit) / ($stopHitRate * $avgLossPerHit), 2
                    );
                }
            }
        }

        return $metrics;
    }

    /**
     * 計算選股品質指標（供 AI 優化選股邏輯使用）
     */
    private function calcScreeningMetrics(Collection $candidates): array
    {
        if ($candidates->isEmpty()) {
            return [
                'avg_score' => 0,
                'candidates_per_day' => 0,
                'strategy_distribution' => [],
                'reason_frequency' => [],
                'avg_score_by_outcome' => ['win' => 0, 'loss' => 0, 'miss' => 0],
            ];
        }

        // 平均分數
        $avgScore = round($candidates->avg('score'), 1);

        // 每日平均候選數
        $tradeDays = $candidates->pluck('trade_date')->unique()->count();
        $candidatesPerDay = $tradeDays > 0 ? round($candidates->count() / $tradeDays, 1) : 0;

        // 策略分布
        $strategyDist = $candidates->groupBy('strategy_type')->map->count()->toArray();

        // 選股理由出現頻率（了解哪些評分因子在運作）
        $reasonCounts = [];
        foreach ($candidates as $c) {
            $reasons = is_array($c->reasons) ? $c->reasons : [];
            foreach ($reasons as $reason) {
                $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
            }
        }
        arsort($reasonCounts);
        // 轉為百分比
        $total = $candidates->count();
        $reasonFreq = [];
        foreach ($reasonCounts as $reason => $count) {
            $reasonFreq[$reason] = round($count / $total * 100, 1);
        }

        // 依結果分組的平均分數（判斷高分是否對應好結果）
        $wins = $candidates->filter(fn ($c) => $c->result->buy_reachable && $c->result->target_reachable);
        $losses = $candidates->filter(fn ($c) => $c->result->buy_reachable && $c->result->hit_stop_loss);
        $misses = $candidates->filter(fn ($c) => !$c->result->buy_reachable);
        $avgScoreByOutcome = [
            'win' => $wins->isNotEmpty() ? round($wins->avg('score'), 1) : 0,
            'loss' => $losses->isNotEmpty() ? round($losses->avg('score'), 1) : 0,
            'miss' => $misses->isNotEmpty() ? round($misses->avg('score'), 1) : 0,
        ];

        return [
            'avg_score' => $avgScore,
            'candidates_per_day' => $candidatesPerDay,
            'strategy_distribution' => $strategyDist,
            'reason_frequency' => $reasonFreq,
            'avg_score_by_outcome' => $avgScoreByOutcome,
        ];
    }

    /**
     * 重新篩選：清除候選資料，對指定期間每個交易日重跑選股 + 結果回填，回傳新指標
     */
    public function rescreen(string $from, string $to): array
    {
        // 清除期間內的候選資料
        $candidateIds = Candidate::whereBetween('trade_date', [$from, $to])->pluck('id');
        if ($candidateIds->isNotEmpty()) {
            CandidateResult::whereIn('candidate_id', $candidateIds)->delete();
            Candidate::whereIn('id', $candidateIds)->delete();
        }

        // 取得期間內的交易日
        $tradingDays = DailyQuote::whereBetween('date', [$from, $to])
            ->selectRaw('DATE(date) as d')
            ->distinct()
            ->orderBy('d')
            ->pluck('d');

        // 逐日重跑選股 + 結果回填
        foreach ($tradingDays as $date) {
            Artisan::call('stock:screen-candidates', ['date' => $date]);
            Artisan::call('stock:update-results', ['date' => $date]);
        }

        Log::info("BacktestService::rescreen completed: {$from} ~ {$to}, {$tradingDays->count()} trading days");

        return $this->computeMetrics($from, $to);
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

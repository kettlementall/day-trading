<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\CandidateResult;
use App\Models\DailyQuote;
use App\Models\SwingPosition;
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
        $query = Candidate::where('mode', 'intraday')
            ->whereBetween('trade_date', [$from, $to])
            ->whereHas('result');

        if ($strategyType) {
            $query->where('intraday_strategy', $strategyType);
        }

        $candidates = $query->with(['result', 'monitor'])->get();
        $total = Candidate::where('mode', 'intraday')
            ->whereBetween('trade_date', [$from, $to])
            ->when($strategyType, fn ($q) => $q->where('intraday_strategy', $strategyType))
            ->count();

        $metrics = $this->calcMetricsFromCollection($candidates, $total);
        $metrics['period'] = ['from' => $from, 'to' => $to];

        // 按 AI 精審策略分類（僅在非篩選模式下）
        if (!$strategyType) {
            $metrics['by_strategy'] = [];
            foreach (['bounce', 'breakout_fresh', 'breakout_retest', 'gap_pullback', 'momentum', 'gap_reversal'] as $type) {
                $subset = $candidates->where('intraday_strategy', $type);
                $subsetTotal = Candidate::where('mode', 'intraday')
                    ->whereBetween('trade_date', [$from, $to])
                    ->where('intraday_strategy', $type)->count();
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

            // 進場後勝率：有效進場中，結果為 target_hit 或 trailing_stop 的比率
            if ($validEntries->isNotEmpty()) {
                $winAfterEntry = $validEntries->filter(function ($c) {
                    return in_array($c->result->monitor_status, ['target_hit', 'trailing_stop']);
                })->count();
                $metrics['win_rate_after_entry'] = round($winAfterEntry / $validEntries->count() * 100, 1);

                // 只算有效進場的期望值
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
     * 計算隔日沖回測指標
     */
    public function computeOvernightMetrics(string $from, string $to): array
    {
        $candidates = Candidate::where('mode', 'overnight')
            ->whereBetween('trade_date', [$from, $to])
            ->whereHas('result')
            ->with('result')
            ->get();

        $total = Candidate::where('mode', 'overnight')
            ->whereBetween('trade_date', [$from, $to])
            ->count();

        $metrics = $this->calcOvernightMetricsFromCollection($candidates, $total);
        $metrics['period'] = ['from' => $from, 'to' => $to];
        $selectedTotal = Candidate::where('mode', 'overnight')
            ->whereBetween('trade_date', [$from, $to])
            ->where('ai_selected', true)
            ->count();
        $metrics['selected_metrics'] = self::calcOvernightSelectedMetrics($candidates, $selectedTotal);

        // 策略分類
        $metrics['by_strategy'] = [];
        foreach (['gap_up_open', 'pullback_entry', 'open_follow_through', 'limit_up_chase'] as $type) {
            $subset = $candidates->where('overnight_strategy', $type);
            $subsetTotal = Candidate::where('mode', 'overnight')
                ->whereBetween('trade_date', [$from, $to])
                ->where('overnight_strategy', $type)->count();
            if ($subset->isNotEmpty()) {
                $metrics['by_strategy'][$type] = $this->calcOvernightMetricsFromCollection($subset, $subsetTotal);
            }
        }

        // 日別趨勢
        $metrics['daily'] = $this->calcOvernightDailyTrend($from, $to);

        return $metrics;
    }

    private function calcOvernightMetricsFromCollection(Collection $candidates, int $totalCandidates): array
    {
        $evaluated = $candidates->count();

        if ($evaluated === 0) {
            return [
                'total_candidates' => $totalCandidates,
                'evaluated' => 0,
                'gap_accuracy_rate' => 0,
                'hit_target_rate' => 0,
                'win_rate' => 0,
                'hit_stop_rate' => 0,
                'avg_open_gap' => 0,
                'ai_approval_rate' => 0,
                'actual_exit_rate' => 0,
                'actual_win_rate' => 0,
                'actual_stop_rate' => 0,
                'avg_actual_return' => 0,
            ];
        }

        $wins = ['hit_target', 'gap_up_strong', 'gap_up', 'up'];
        $losses = ['hit_stop', 'gap_down', 'down'];

        $gapCorrect = $candidates->filter(fn ($c) => $c->result->gap_predicted_correctly)->count();
        $hitTarget = $candidates->filter(fn ($c) => $c->result->overnight_outcome === 'hit_target')->count();
        $winCount = $candidates->filter(fn ($c) => in_array($c->result->overnight_outcome, $wins))->count();
        $lossCount = $candidates->filter(fn ($c) => in_array($c->result->overnight_outcome, $losses))->count();

        $openGaps = $candidates
            ->filter(fn ($c) => $c->result->open_gap_percent !== null)
            ->map(fn ($c) => (float) $c->result->open_gap_percent);
        $avgOpenGap = $openGaps->isNotEmpty() ? round($openGaps->avg(), 2) : 0;

        $aiSelected = $candidates->filter(fn ($c) => $c->ai_selected)->count();
        $actualMetrics = self::calcOvernightActualMetrics($candidates, $evaluated);

        return [
            'total_candidates' => $totalCandidates,
            'evaluated' => $evaluated,
            'gap_accuracy_rate' => round($gapCorrect / $evaluated * 100, 1),
            'hit_target_rate' => round($hitTarget / $evaluated * 100, 1),
            'win_rate' => round($winCount / $evaluated * 100, 1),
            'hit_stop_rate' => round($lossCount / $evaluated * 100, 1),
            'avg_open_gap' => $avgOpenGap,
            'ai_approval_rate' => round($aiSelected / $evaluated * 100, 1),
            ...$actualMetrics,
        ];
    }

    public static function calcOvernightActualMetrics(Collection $candidates, int $evaluated): array
    {
        if ($evaluated === 0) {
            return [
                'actual_exit_rate' => 0,
                'actual_win_rate' => 0,
                'actual_stop_rate' => 0,
                'avg_actual_return' => 0,
            ];
        }

        $actualExits = $candidates->filter(function ($c) {
            $result = $c->result;
            if (!$result) {
                return false;
            }

            return (float) $result->entry_price_actual > 0
                && (float) $result->exit_price_actual > 0;
        });

        $actualCount = $actualExits->count();
        $actualUniverse = $candidates->filter(fn ($c) => (bool) ($c->ai_selected ?? false))->count();
        if ($actualUniverse === 0) {
            $actualUniverse = $evaluated;
        }
        $actualUniverse = max($actualUniverse, $actualCount);

        if ($actualCount === 0) {
            return [
                'actual_exit_rate' => 0,
                'actual_win_rate' => 0,
                'actual_stop_rate' => 0,
                'avg_actual_return' => 0,
            ];
        }

        $returns = $actualExits->map(function ($c) {
            $entry = (float) $c->result->entry_price_actual;
            $exit  = (float) $c->result->exit_price_actual;

            return round(($exit - $entry) / $entry * 100, 2);
        });

        $wins = $returns->filter(fn ($return) => $return > 0)->count();
        $stops = $actualExits->filter(fn ($c) => $c->result->monitor_status === 'stop_hit')->count();

        return [
            'actual_exit_rate' => round($actualCount / $actualUniverse * 100, 1),
            'actual_win_rate' => round($wins / $actualCount * 100, 1),
            'actual_stop_rate' => round($stops / $actualCount * 100, 1),
            'avg_actual_return' => round($returns->avg(), 2),
        ];
    }

    public static function calcOvernightSelectedMetrics(Collection $candidates, ?int $selectedTotal = null): array
    {
        $selected = $candidates->filter(fn ($c) => (bool) ($c->ai_selected ?? false))->values();
        $evaluated = $selected->count();
        $selectedCount = $selectedTotal ?? $evaluated;

        if ($evaluated === 0) {
            return [
                'selected_count' => $selectedCount,
                'total_candidates' => $selectedCount,
                'evaluated' => 0,
                'gap_accuracy_rate' => 0,
                'hit_target_rate' => 0,
                'win_rate' => 0,
                'hit_stop_rate' => 0,
                'avg_open_gap' => 0,
                'ai_approval_rate' => 0,
                'actual_exit_rate' => 0,
                'actual_win_rate' => 0,
                'actual_stop_rate' => 0,
                'avg_actual_return' => 0,
            ];
        }

        $wins = ['hit_target', 'gap_up_strong', 'gap_up', 'up'];
        $losses = ['hit_stop', 'gap_down', 'down'];
        $gapCorrect = $selected->filter(fn ($c) => $c->result->gap_predicted_correctly)->count();
        $hitTarget = $selected->filter(fn ($c) => $c->result->overnight_outcome === 'hit_target')->count();
        $winCount = $selected->filter(fn ($c) => in_array($c->result->overnight_outcome, $wins))->count();
        $lossCount = $selected->filter(fn ($c) => in_array($c->result->overnight_outcome, $losses))->count();
        $openGaps = $selected
            ->filter(fn ($c) => $c->result->open_gap_percent !== null)
            ->map(fn ($c) => (float) $c->result->open_gap_percent);

        return [
            'selected_count' => $selectedCount,
            'total_candidates' => $selectedCount,
            'evaluated' => $evaluated,
            'gap_accuracy_rate' => round($gapCorrect / $evaluated * 100, 1),
            'hit_target_rate' => round($hitTarget / $evaluated * 100, 1),
            'win_rate' => round($winCount / $evaluated * 100, 1),
            'hit_stop_rate' => round($lossCount / $evaluated * 100, 1),
            'avg_open_gap' => $openGaps->isNotEmpty() ? round($openGaps->avg(), 2) : 0,
            'ai_approval_rate' => 100.0,
            ...self::calcOvernightActualMetrics($selected, $evaluated),
        ];
    }

    private function calcOvernightDailyTrend(string $from, string $to): array
    {
        $rows = DB::table('candidates as c')
            ->join('candidate_results as cr', 'cr.candidate_id', '=', 'c.id')
            ->where('c.mode', 'overnight')
            ->whereBetween('c.trade_date', [$from, $to])
            ->select(
                'c.trade_date as date',
                DB::raw('COUNT(*) as evaluated'),
                DB::raw('SUM(cr.gap_predicted_correctly) as gap_correct'),
                DB::raw("SUM(CASE WHEN cr.overnight_outcome = 'hit_target' THEN 1 ELSE 0 END) as hit_target"),
                DB::raw("SUM(CASE WHEN cr.overnight_outcome IN ('hit_target','gap_up_strong','gap_up','up') THEN 1 ELSE 0 END) as wins"),
                DB::raw("SUM(CASE WHEN cr.entry_price_actual > 0 AND cr.exit_price_actual > 0 THEN 1 ELSE 0 END) as actual_exits"),
                DB::raw("SUM(CASE WHEN cr.entry_price_actual > 0 AND cr.exit_price_actual > cr.entry_price_actual THEN 1 ELSE 0 END) as actual_wins"),
                DB::raw("SUM(CASE WHEN cr.entry_price_actual > 0 AND cr.exit_price_actual > 0 AND cr.monitor_status = 'stop_hit' THEN 1 ELSE 0 END) as actual_stops"),
                DB::raw("AVG(CASE WHEN cr.entry_price_actual > 0 AND cr.exit_price_actual > 0 THEN (cr.exit_price_actual - cr.entry_price_actual) / cr.entry_price_actual * 100 ELSE NULL END) as avg_actual_return"),
                DB::raw("SUM(CASE WHEN c.ai_selected = 1 THEN 1 ELSE 0 END) as selected_evaluated"),
                DB::raw("SUM(CASE WHEN c.ai_selected = 1 AND cr.entry_price_actual > 0 AND cr.exit_price_actual > 0 THEN 1 ELSE 0 END) as selected_actual_exits"),
                DB::raw("SUM(CASE WHEN c.ai_selected = 1 AND cr.entry_price_actual > 0 AND cr.exit_price_actual > cr.entry_price_actual THEN 1 ELSE 0 END) as selected_actual_wins"),
                DB::raw("SUM(CASE WHEN c.ai_selected = 1 AND cr.entry_price_actual > 0 AND cr.exit_price_actual > 0 AND cr.monitor_status = 'stop_hit' THEN 1 ELSE 0 END) as selected_actual_stops"),
                DB::raw("AVG(CASE WHEN c.ai_selected = 1 AND cr.entry_price_actual > 0 AND cr.exit_price_actual > 0 THEN (cr.exit_price_actual - cr.entry_price_actual) / cr.entry_price_actual * 100 ELSE NULL END) as selected_avg_actual_return"),
            )
            ->groupBy('c.trade_date')
            ->orderBy('c.trade_date')
            ->get();

        return $rows->map(fn ($row) => [
            'date' => $row->date,
            'evaluated' => $row->evaluated,
            'gap_accuracy_rate' => $row->evaluated > 0 ? round($row->gap_correct / $row->evaluated * 100, 1) : 0,
            'hit_target_rate' => $row->evaluated > 0 ? round($row->hit_target / $row->evaluated * 100, 1) : 0,
            'win_rate' => $row->evaluated > 0 ? round($row->wins / $row->evaluated * 100, 1) : 0,
            'actual_exit_rate' => $row->evaluated > 0 ? round($row->actual_exits / $row->evaluated * 100, 1) : 0,
            'actual_win_rate' => $row->actual_exits > 0 ? round($row->actual_wins / $row->actual_exits * 100, 1) : 0,
            'actual_stop_rate' => $row->actual_exits > 0 ? round($row->actual_stops / $row->actual_exits * 100, 1) : 0,
            'avg_actual_return' => $row->avg_actual_return !== null ? round($row->avg_actual_return, 2) : 0,
            'selected_evaluated' => (int) $row->selected_evaluated,
            'selected_actual_exit_rate' => $row->selected_evaluated > 0 ? round($row->selected_actual_exits / $row->selected_evaluated * 100, 1) : 0,
            'selected_actual_win_rate' => $row->selected_actual_exits > 0 ? round($row->selected_actual_wins / $row->selected_actual_exits * 100, 1) : 0,
            'selected_actual_stop_rate' => $row->selected_actual_exits > 0 ? round($row->selected_actual_stops / $row->selected_actual_exits * 100, 1) : 0,
            'selected_avg_actual_return' => $row->selected_avg_actual_return !== null ? round($row->selected_avg_actual_return, 2) : 0,
        ])->toArray();
    }

    /**
     * 重新篩選：清除候選資料，對指定期間每個交易日重跑選股 + 結果回填，回傳新指標
     */
    public function rescreen(string $from, string $to): array
    {
        // 清除期間內的當沖候選資料（不影響隔日沖）
        $candidateIds = Candidate::whereBetween('trade_date', [$from, $to])
            ->where('mode', 'intraday')
            ->pluck('id');
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
     * 計算短線（swing）回測指標
     *
     * 短線跟當沖/隔日沖邏輯不同：沒有 CandidateResult，由兩個來源組合：
     * 1. 紙上績效：候選 trade_date 後 20 個交易日的 DailyQuote 模擬，看是否觸目標/停損
     * 2. 實現績效：使用者建倉後已平倉/停損結束的 SwingPosition
     */
    public function computeSwingMetrics(string $from, string $to): array
    {
        // 紙上績效只算「已走完 20 個交易日」的候選，避免結果偏樂觀
        $cutoff = now()->subDays(28)->toDateString(); // 自然日 28 ≈ 20 交易日

        $candidates = Candidate::where('mode', 'swing')
            ->whereBetween('trade_date', [$from, $to])
            ->where('trade_date', '<=', $cutoff)
            ->get();

        $totalAll = Candidate::where('mode', 'swing')
            ->whereBetween('trade_date', [$from, $to])
            ->count();
        $aiSelectedAll = Candidate::where('mode', 'swing')
            ->whereBetween('trade_date', [$from, $to])
            ->where('ai_selected', true)
            ->count();

        $paperOutcomes = $this->computeSwingPaperOutcomes($candidates);
        $metrics = $this->calcSwingMetricsFromCollection($candidates, $paperOutcomes, $totalAll, $aiSelectedAll);

        // 實現績效（SwingPosition exit_date 落在區間內）
        $closedPositions = SwingPosition::with('stock')
            ->whereIn('status', [SwingPosition::STATUS_CLOSED, SwingPosition::STATUS_STOPPED])
            ->whereBetween('exit_date', [$from, $to])
            ->get();
        $metrics['realized'] = $this->calcSwingRealizedMetrics($closedPositions);

        // by_strategy
        $metrics['by_strategy'] = [];
        foreach (['trend_pullback', 'trend_follow', 'base_breakout'] as $strategy) {
            $subset = $candidates->where('swing_strategy', $strategy);
            if ($subset->isEmpty()) continue;
            $subsetTotal = Candidate::where('mode', 'swing')
                ->whereBetween('trade_date', [$from, $to])
                ->where('swing_strategy', $strategy)
                ->count();
            $subsetSelected = Candidate::where('mode', 'swing')
                ->whereBetween('trade_date', [$from, $to])
                ->where('swing_strategy', $strategy)
                ->where('ai_selected', true)
                ->count();
            $metrics['by_strategy'][$strategy] = $this->calcSwingMetricsFromCollection(
                $subset, $paperOutcomes, $subsetTotal, $subsetSelected
            );
        }

        // by_thesis（看哪個論點命中率高）
        $metrics['by_thesis'] = $candidates
            ->groupBy(fn ($c) => $c->swing_thesis['title'] ?? '未連結論點')
            ->map(function (Collection $group, string $title) use ($paperOutcomes) {
                $outcomes = $group->map(fn ($c) => $paperOutcomes[$c->id] ?? null)->filter();
                $count = $group->count();
                $hit = $outcomes->where('outcome', 'target')->count();
                $stop = $outcomes->where('outcome', 'stop')->count();
                return [
                    'thesis' => $title,
                    'count' => $count,
                    'paper_target_reach_rate' => $count > 0 ? round($hit / $count * 100, 1) : 0,
                    'paper_stop_loss_rate' => $count > 0 ? round($stop / $count * 100, 1) : 0,
                    'paper_expected_value' => $outcomes->isNotEmpty() ? round($outcomes->avg('return_pct'), 2) : 0,
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->all();

        $metrics['daily'] = $this->calcSwingDailyTrend($from, $to, $cutoff);
        $metrics['period'] = ['from' => $from, 'to' => $to];

        return $metrics;
    }

    /**
     * 對每個 candidate 模擬「進場後 20 個交易日」的結果。
     * 回傳 [candidate_id => ['outcome' => target|stop|neither, 'return_pct' => float, 'days' => int]]
     */
    private function computeSwingPaperOutcomes(Collection $candidates): array
    {
        if ($candidates->isEmpty()) return [];

        $stockIds = $candidates->pluck('stock_id')->unique();
        $earliest = $candidates->min('trade_date');
        $forwardEnd = now()->toDateString();

        $quotesByStock = DailyQuote::whereIn('stock_id', $stockIds)
            ->where('date', '>=', $earliest)
            ->where('date', '<=', $forwardEnd)
            ->orderBy('date')
            ->get(['stock_id', 'date', 'high', 'low', 'close'])
            ->groupBy('stock_id');

        $outcomes = [];
        foreach ($candidates as $c) {
            $tradeDateStr = $c->trade_date instanceof \Carbon\Carbon
                ? $c->trade_date->format('Y-m-d')
                : (string) $c->trade_date;

            $forward = ($quotesByStock[$c->stock_id] ?? collect())
                ->filter(fn ($q) => $q->date->format('Y-m-d') > $tradeDateStr)
                ->take(20)
                ->values();

            if ($forward->isEmpty()) {
                continue;
            }

            $entry = (float) $c->suggested_buy;
            $target = (float) $c->target_price;
            $stop = (float) $c->stop_loss;
            if ($entry <= 0) continue;

            $outcome = 'neither';
            $exitPrice = (float) $forward->last()->close;
            $days = $forward->count();

            foreach ($forward as $idx => $q) {
                $high = (float) $q->high;
                $low = (float) $q->low;
                // 同 K 棒高低同時觸：保守視停損先觸發
                if ($stop > 0 && $low <= $stop) {
                    $outcome = 'stop';
                    $exitPrice = $stop;
                    $days = $idx + 1;
                    break;
                }
                if ($target > 0 && $high >= $target) {
                    $outcome = 'target';
                    $exitPrice = $target;
                    $days = $idx + 1;
                    break;
                }
            }

            $outcomes[$c->id] = [
                'outcome' => $outcome,
                'return_pct' => round(($exitPrice - $entry) / $entry * 100, 2),
                'days' => $days,
            ];
        }
        return $outcomes;
    }

    private function calcSwingMetricsFromCollection(
        Collection $candidates,
        array $paperOutcomes,
        int $totalCandidates,
        int $aiSelectedTotal,
    ): array {
        $evaluated = $candidates->count();
        if ($evaluated === 0) {
            return [
                'total_candidates' => $totalCandidates,
                'ai_selected' => $aiSelectedTotal,
                'evaluated' => 0,
                'paper_target_reach_rate' => 0,
                'paper_stop_loss_rate' => 0,
                'paper_expected_value' => 0,
                'paper_avg_holding_days' => 0,
                'avg_risk_reward' => 0,
            ];
        }

        $outcomes = $candidates->map(fn ($c) => $paperOutcomes[$c->id] ?? null)->filter();
        $hits = $outcomes->where('outcome', 'target')->count();
        $stops = $outcomes->where('outcome', 'stop')->count();
        $expectedValue = $outcomes->isNotEmpty() ? round($outcomes->avg('return_pct'), 2) : 0;
        $avgDays = $outcomes->isNotEmpty() ? round($outcomes->avg('days'), 1) : 0;

        return [
            'total_candidates' => $totalCandidates,
            'ai_selected' => $aiSelectedTotal,
            'evaluated' => $evaluated,
            'paper_target_reach_rate' => round($hits / $evaluated * 100, 1),
            'paper_stop_loss_rate' => round($stops / $evaluated * 100, 1),
            'paper_expected_value' => $expectedValue,
            'paper_avg_holding_days' => $avgDays,
            'avg_risk_reward' => round((float) $candidates->avg('risk_reward_ratio'), 2),
        ];
    }

    private function calcSwingRealizedMetrics(Collection $positions): array
    {
        $count = $positions->count();
        if ($count === 0) {
            return [
                'closed_positions' => 0,
                'win_rate' => 0,
                'avg_return' => 0,
                'hit_stop_rate' => 0,
                'avg_holding_days' => 0,
            ];
        }

        $returns = $positions->map(function (SwingPosition $p) {
            $entry = (float) $p->entry_price;
            $exit = (float) ($p->averageExitPrice() ?? 0);
            return $entry > 0 ? round(($exit - $entry) / $entry * 100, 2) : 0;
        });

        $wins = $returns->filter(fn ($r) => $r > 0)->count();
        $stopped = $positions->where('status', SwingPosition::STATUS_STOPPED)->count();

        $holdingDays = $positions->map(function (SwingPosition $p) {
            if (!$p->entry_date || !$p->exit_date) return null;
            return $p->entry_date->diffInDays($p->exit_date);
        })->filter();

        return [
            'closed_positions' => $count,
            'win_rate' => round($wins / $count * 100, 1),
            'avg_return' => round($returns->avg(), 2),
            'hit_stop_rate' => round($stopped / $count * 100, 1),
            'avg_holding_days' => $holdingDays->isNotEmpty() ? round($holdingDays->avg(), 1) : 0,
        ];
    }

    private function calcSwingDailyTrend(string $from, string $to, string $cutoff): array
    {
        $rows = DB::table('candidates')
            ->where('mode', 'swing')
            ->whereBetween('trade_date', [$from, $to])
            ->select(
                'trade_date as date',
                DB::raw('COUNT(*) as candidates'),
                DB::raw('SUM(CASE WHEN ai_selected = 1 THEN 1 ELSE 0 END) as ai_selected'),
                DB::raw("SUM(CASE WHEN trade_date <= '{$cutoff}' THEN 1 ELSE 0 END) as evaluated"),
            )
            ->groupBy('trade_date')
            ->orderBy('trade_date')
            ->get();

        return $rows->map(fn ($row) => [
            'date' => $row->date,
            'candidates' => (int) $row->candidates,
            'ai_selected' => (int) $row->ai_selected,
            'evaluated' => (int) $row->evaluated,
        ])->toArray();
    }

    /**
     * 計算日別趨勢資料（供圖表使用）
     */
    private function calcDailyTrend(string $from, string $to): array
    {
        $rows = DB::table('candidates as c')
            ->join('candidate_results as cr', 'cr.candidate_id', '=', 'c.id')
            ->where('c.mode', 'intraday')
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

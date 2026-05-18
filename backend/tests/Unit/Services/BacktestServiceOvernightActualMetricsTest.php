<?php

namespace Tests\Unit\Services;

use App\Services\BacktestService;
use PHPUnit\Framework\TestCase;

class BacktestServiceOvernightActualMetricsTest extends TestCase
{
    public function test_actual_metrics_return_zero_when_no_actual_exits(): void
    {
        $metrics = BacktestService::calcOvernightActualMetrics(collect([
            (object) ['result' => (object) [
                'entry_price_actual' => null,
                'exit_price_actual' => null,
                'monitor_status' => null,
            ]],
        ]), 1);

        $this->assertSame(0, $metrics['actual_exit_rate']);
        $this->assertSame(0, $metrics['actual_win_rate']);
        $this->assertSame(0, $metrics['actual_stop_rate']);
        $this->assertSame(0, $metrics['avg_actual_return']);
    }

    public function test_actual_metrics_use_only_rows_with_entry_and_exit_prices(): void
    {
        $metrics = BacktestService::calcOvernightActualMetrics(collect([
            (object) ['result' => (object) [
                'entry_price_actual' => 100,
                'exit_price_actual' => 105,
                'monitor_status' => 'target_hit',
            ]],
            (object) ['result' => (object) [
                'entry_price_actual' => 100,
                'exit_price_actual' => 97,
                'monitor_status' => 'stop_hit',
            ]],
            (object) ['result' => (object) [
                'entry_price_actual' => 100,
                'exit_price_actual' => null,
                'monitor_status' => 'closed',
            ]],
        ]), 3);

        $this->assertSame(66.7, $metrics['actual_exit_rate']);
        $this->assertSame(50.0, $metrics['actual_win_rate']);
        $this->assertSame(50.0, $metrics['actual_stop_rate']);
        $this->assertSame(1.0, $metrics['avg_actual_return']);
    }

    public function test_actual_exit_rate_uses_ai_selected_universe_when_available(): void
    {
        $metrics = BacktestService::calcOvernightActualMetrics(collect([
            (object) [
                'ai_selected' => true,
                'result' => (object) [
                    'entry_price_actual' => 100,
                    'exit_price_actual' => 105,
                    'monitor_status' => 'target_hit',
                ],
            ],
            (object) [
                'ai_selected' => true,
                'result' => (object) [
                    'entry_price_actual' => 100,
                    'exit_price_actual' => null,
                    'monitor_status' => 'closed',
                ],
            ],
            (object) [
                'ai_selected' => false,
                'result' => (object) [
                    'entry_price_actual' => null,
                    'exit_price_actual' => null,
                    'monitor_status' => null,
                ],
            ],
        ]), 3);

        $this->assertSame(50.0, $metrics['actual_exit_rate']);
    }

    public function test_actual_exit_rate_never_exceeds_one_hundred_when_actual_exits_outnumber_ai_selected(): void
    {
        $metrics = BacktestService::calcOvernightActualMetrics(collect([
            (object) [
                'ai_selected' => true,
                'result' => (object) [
                    'entry_price_actual' => 100,
                    'exit_price_actual' => 105,
                    'monitor_status' => 'target_hit',
                ],
            ],
            (object) [
                'ai_selected' => false,
                'result' => (object) [
                    'entry_price_actual' => 100,
                    'exit_price_actual' => 103,
                    'monitor_status' => 'closed',
                ],
            ],
        ]), 2);

        $this->assertSame(100.0, $metrics['actual_exit_rate']);
    }

    public function test_selected_metrics_ignore_rejected_candidates(): void
    {
        $metrics = BacktestService::calcOvernightSelectedMetrics(collect([
            (object) [
                'ai_selected' => true,
                'result' => (object) [
                    'gap_predicted_correctly' => true,
                    'overnight_outcome' => 'hit_target',
                    'open_gap_percent' => 2.0,
                    'entry_price_actual' => 100,
                    'exit_price_actual' => 104,
                    'monitor_status' => 'target_hit',
                ],
            ],
            (object) [
                'ai_selected' => false,
                'result' => (object) [
                    'gap_predicted_correctly' => false,
                    'overnight_outcome' => 'hit_stop',
                    'open_gap_percent' => -2.0,
                    'entry_price_actual' => 100,
                    'exit_price_actual' => 90,
                    'monitor_status' => 'stop_hit',
                ],
            ],
        ]), 3);

        $this->assertSame(3, $metrics['selected_count']);
        $this->assertSame(1, $metrics['evaluated']);
        $this->assertSame(100.0, $metrics['actual_exit_rate']);
        $this->assertSame(100.0, $metrics['actual_win_rate']);
        $this->assertSame(0.0, $metrics['actual_stop_rate']);
        $this->assertSame(4.0, $metrics['avg_actual_return']);
    }

    public function test_selected_metrics_return_zero_when_no_selected_candidates(): void
    {
        $metrics = BacktestService::calcOvernightSelectedMetrics(collect([
            (object) [
                'ai_selected' => false,
                'result' => (object) [
                    'gap_predicted_correctly' => false,
                    'overnight_outcome' => 'hit_stop',
                    'open_gap_percent' => -2.0,
                    'entry_price_actual' => 100,
                    'exit_price_actual' => 90,
                    'monitor_status' => 'stop_hit',
                ],
            ],
        ]), 0);

        $this->assertSame(0, $metrics['selected_count']);
        $this->assertSame(0, $metrics['evaluated']);
        $this->assertSame(0, $metrics['actual_exit_rate']);
        $this->assertSame(0, $metrics['actual_win_rate']);
        $this->assertSame(0, $metrics['actual_stop_rate']);
        $this->assertSame(0, $metrics['avg_actual_return']);
    }
}

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
}

<?php

namespace Tests\Unit\Console;

use App\Console\Commands\UpdateOvernightResults;
use App\Models\Candidate;
use App\Models\CandidateMonitor;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class UpdateOvernightResultsTest extends TestCase
{
    public function test_monitor_payload_uses_suggested_buy_when_monitor_has_no_entry_price(): void
    {
        $candidate = new Candidate();
        $monitor = new CandidateMonitor();
        $monitor->setRawAttributes([
            'status' => CandidateMonitor::STATUS_TARGET_HIT,
            'entry_price' => null,
            'exit_price' => 105,
            'current_target' => 105,
            'current_stop' => 96,
            'entry_time' => null,
            'exit_time' => Carbon::parse('2026-05-06 10:15:00'),
        ]);
        $candidate->setRelation('monitor', $monitor);

        $payload = $this->invokeMonitorPayload($candidate, 100);

        $this->assertSame('target_hit', $payload['monitor_status']);
        $this->assertSame(100.0, $payload['entry_price_actual']);
        $this->assertSame(105.0, $payload['exit_price_actual']);
        $this->assertTrue($payload['valid_entry']);
    }

    public function test_monitor_payload_allows_missing_exit_price_without_faking_return(): void
    {
        $candidate = new Candidate();
        $monitor = new CandidateMonitor();
        $monitor->setRawAttributes([
            'status' => CandidateMonitor::STATUS_CLOSED,
            'entry_price' => null,
            'exit_price' => null,
            'entry_time' => null,
            'exit_time' => Carbon::parse('2026-05-06 13:15:00'),
        ]);
        $candidate->setRelation('monitor', $monitor);

        $payload = $this->invokeMonitorPayload($candidate, 100);

        $this->assertSame('closed', $payload['monitor_status']);
        $this->assertSame(100.0, $payload['entry_price_actual']);
        $this->assertNull($payload['exit_price_actual']);
        $this->assertTrue($payload['valid_entry']);
    }

    private function invokeMonitorPayload(Candidate $candidate, float $suggestedBuy): array
    {
        $method = new ReflectionMethod(UpdateOvernightResults::class, 'getMonitorPayload');
        $method->setAccessible(true);

        return $method->invoke(new UpdateOvernightResults(), $candidate, $suggestedBuy);
    }
}

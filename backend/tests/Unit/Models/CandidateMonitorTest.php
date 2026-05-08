<?php

namespace Tests\Unit\Models;

use App\Models\CandidateMonitor;
use Tests\TestCase;

class CandidateMonitorTest extends TestCase
{
    public function test_logAiAdvice_preserves_entry_quality_context(): void
    {
        $monitor = new CandidateMonitor();

        $monitor->logAiAdvice('hold', '策略可切但等回測', null, [
            'strategy_state' => 'switched',
            'strategy_valid' => true,
            'strategy_issue' => '可切 momentum，但目前偏追高',
            'strategy' => 'momentum',
            'entry_timing' => 'late_chase',
            'entry_quality' => 35,
            'chase_risk' => 82,
        ]);

        $entry = $monitor->ai_advice_log[0];

        $this->assertSame('switched', $entry['strategy_state']);
        $this->assertSame('momentum', $entry['strategy']);
        $this->assertSame('late_chase', $entry['entry_timing']);
        $this->assertSame(35, $entry['entry_quality']);
        $this->assertSame(82, $entry['chase_risk']);
    }
}

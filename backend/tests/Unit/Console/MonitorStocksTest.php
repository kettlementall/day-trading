<?php

namespace Tests\Unit\Console;

use PHPUnit\Framework\TestCase;

class MonitorStocksTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(
            __DIR__ . '/../../../app/Console/Commands/MonitorStocks.php'
        );
    }

    public function test_monitor_command_injects_market_regime_service(): void
    {
        $this->assertStringContainsString('private IntradayMarketRegimeService $marketRegime', $this->source);
        $this->assertStringContainsString('use App\Services\IntradayMarketRegimeService;', $this->source);
    }

    public function test_rolling_detects_regime_once_and_reuses_it(): void
    {
        $this->assertStringContainsString('$marketRegime = $this->detectMarketRegime($date);', $this->source);
        $this->assertStringContainsString('$this->performRollingAdvice($date, $marketRegime);', $this->source);
        $this->assertStringContainsString('$marketRegime ??= $this->detectMarketRegime($date);', $this->source);
        $this->assertStringContainsString('$this->performEmergencyAdvice($date, $emergencyMonitors, $marketRegime);', $this->source);
    }

    public function test_regime_is_passed_to_ai_advice_calls(): void
    {
        $this->assertStringContainsString('rollingAdvice($date, $monitor, $allSnapshots, $marketRegime)', $this->source);
        $this->assertStringContainsString('emergencyAdvice($date, $monitor, $allSnapshots, $reason, $marketRegime)', $this->source);
    }
}

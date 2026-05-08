<?php

namespace Tests\Unit\Console;

use PHPUnit\Framework\TestCase;

class HealthCheckTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(
            __DIR__ . '/../../../app/Console/Commands/HealthCheck.php'
        );
    }

    public function test_health_check_includes_fugle_snapshot_api(): void
    {
        $this->assertStringContainsString('Fugle Snapshot', $this->source);
        $this->assertStringContainsString('/stock/snapshot/movers/TSE', $this->source);
        $this->assertStringContainsString('/stock/snapshot/actives/TSE', $this->source);
        $this->assertStringContainsString('snapshot 權限未開', $this->source);
        $this->assertStringContainsString('候選池環境僅用 selected universe', $this->source);
    }

    public function test_health_check_includes_intraday_market_regime_detection(): void
    {
        $this->assertStringContainsString('use App\Services\IntradayMarketRegimeService;', $this->source);
        $this->assertStringContainsString('app(IntradayMarketRegimeService::class)->detect($date)', $this->source);
        $this->assertStringContainsString('候選池盤中環境', $this->source);
        $this->assertStringContainsString('selected_universe+fugle_snapshot', $this->source);
        $this->assertStringContainsString('selected_universe', $this->source);
        $this->assertStringContainsString('候選池 %d 檔', $this->source);
    }
}

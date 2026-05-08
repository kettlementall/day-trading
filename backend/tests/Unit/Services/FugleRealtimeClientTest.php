<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

class FugleRealtimeClientTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(
            __DIR__ . '/../../../app/Services/FugleRealtimeClient.php'
        );
    }

    public function test_snapshot_movers_endpoint_is_supported(): void
    {
        $this->assertStringContainsString("private const MOVERS_PATH    = '/stock/snapshot/movers';", $this->source);
        $this->assertStringContainsString('public function fetchMovers(', $this->source);
        $this->assertStringContainsString("'direction' => \$direction", $this->source);
        $this->assertStringContainsString("'change' => \$change", $this->source);
        $this->assertStringContainsString("'type' => 'COMMONSTOCK'", $this->source);
    }

    public function test_snapshot_actives_endpoint_is_supported(): void
    {
        $this->assertStringContainsString("private const ACTIVES_PATH   = '/stock/snapshot/actives';", $this->source);
        $this->assertStringContainsString('public function fetchActives(', $this->source);
        $this->assertStringContainsString("'trade' => \$trade", $this->source);
        $this->assertStringContainsString('fetchActives(\'TSE\', \'value\')', file_get_contents(
            __DIR__ . '/../../../app/Services/IntradayMarketRegimeService.php'
        ));
    }
}

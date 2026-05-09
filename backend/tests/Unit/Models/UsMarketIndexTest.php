<?php

namespace Tests\Unit\Models;

use PHPUnit\Framework\TestCase;

class UsMarketIndexTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(
            __DIR__ . '/../../../app/Models/UsMarketIndex.php'
        );
    }

    public function test_summary_formats_vix_as_volatility_context_without_thresholds(): void
    {
        $this->assertStringContainsString('$i->symbol === \'^VIX\'', $this->source);
        $this->assertStringContainsString('波動風險參考', $this->source);
        $this->assertStringContainsString('市場避險需求升溫', $this->source);
        $this->assertStringNotContainsString('VIX >=', $this->source);
        $this->assertStringNotContainsString('VIX >', $this->source);
    }
}

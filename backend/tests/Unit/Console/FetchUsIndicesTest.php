<?php

namespace Tests\Unit\Console;

use PHPUnit\Framework\TestCase;

class FetchUsIndicesTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(
            __DIR__ . '/../../../app/Console/Commands/FetchUsIndices.php'
        );
    }

    public function test_fetch_us_indices_includes_vix(): void
    {
        $this->assertStringContainsString("'^VIX'     => 'VIX'", $this->source);
    }
}

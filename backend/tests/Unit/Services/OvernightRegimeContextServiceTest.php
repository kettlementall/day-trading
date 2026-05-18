<?php

namespace Tests\Unit\Services;

use App\Services\OvernightRegimeContextService;
use PHPUnit\Framework\TestCase;

class OvernightRegimeContextServiceTest extends TestCase
{
    public function test_parse_index_quote_uses_change_percent_when_available(): void
    {
        $row = OvernightRegimeContextService::parseIndexQuote('IX0001', [
            'name' => '加權指數',
            'group' => 'market',
        ], [
            'name' => '發行量加權股價指數',
            'closePrice' => 25000,
            'referencePrice' => 25100,
            'changePercent' => -0.4,
        ]);

        $this->assertSame('IX0001', $row['symbol']);
        $this->assertSame('發行量加權股價指數', $row['name']);
        $this->assertSame('market', $row['group']);
        $this->assertSame(25000.0, $row['price']);
        $this->assertSame(-0.4, $row['change_percent']);
    }

    public function test_parse_index_quote_calculates_change_percent_when_missing(): void
    {
        $row = OvernightRegimeContextService::parseIndexQuote('IX0028', [
            'name' => '半導體業',
            'group' => 'sector',
        ], [
            'closePrice' => 99,
            'referencePrice' => 100,
        ]);

        $this->assertSame(-1.0, $row['change_percent']);
    }

    public function test_prompt_states_regime_is_not_a_hard_rule(): void
    {
        $prompt = OvernightRegimeContextService::buildPrompt([
            ['symbol' => 'IX0001', 'name' => '加權指數', 'group' => 'market', 'change_percent' => -0.5],
            ['symbol' => 'IX0028', 'name' => '半導體業', 'group' => 'sector', 'change_percent' => 1.2],
        ]);

        $this->assertStringContainsString('不是硬規則', $prompt);
        $this->assertStringContainsString('半導體業', $prompt);
    }
}

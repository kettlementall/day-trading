<?php

namespace Tests\Unit\Services;

use App\Services\AiScreenerService;
use PHPUnit\Framework\TestCase;

class AiScreenerServiceFinalRankingTest extends TestCase
{
    public function test_parse_final_ranking_response_accepts_rankings_wrapper(): void
    {
        $items = AiScreenerService::parseFinalRankingResponse(json_encode([
            'rankings' => [
                [
                    'symbol' => '2330',
                    'rank_tier' => 'primary',
                    'regime_fit' => 'strong',
                    'reasoning' => '主流且抗跌',
                ],
            ],
        ], JSON_UNESCAPED_UNICODE));

        $this->assertSame('primary', $items['2330']['rank_tier']);
        $this->assertSame('strong', $items['2330']['regime_fit']);
        $this->assertSame('主流且抗跌', $items['2330']['reasoning']);
    }

    public function test_normalize_final_ranking_item_falls_back_to_avoid_for_invalid_tier(): void
    {
        $item = AiScreenerService::normalizeFinalRankingItem([
            'rank_tier' => 'buy_now',
            'regime_fit' => 'excellent',
            'reasoning' => 'invalid',
        ]);

        $this->assertSame('avoid', $item['rank_tier']);
        $this->assertNull($item['regime_fit']);
    }
}

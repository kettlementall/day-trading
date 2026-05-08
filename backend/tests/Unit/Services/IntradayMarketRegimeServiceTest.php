<?php

namespace Tests\Unit\Services;

use App\Services\FugleRealtimeClient;
use App\Services\IntradayMarketRegimeService;
use PHPUnit\Framework\TestCase;

class IntradayMarketRegimeServiceTest extends TestCase
{
    private IntradayMarketRegimeService $service;

    protected function setUp(): void
    {
        $this->service = new IntradayMarketRegimeService(
            $this->createMock(FugleRealtimeClient::class)
        );
    }

    public function test_classify_detects_trend_day(): void
    {
        $result = $this->service->classify([
            'sample_size' => 20,
            'gap_fade_pct' => 15,
            'breakout_follow_pct' => 62,
            'below_opening_low_pct' => 10,
            'volume_confirm_pct' => 75,
            'external_support_pct' => 68,
            'market_up_avg_pct' => 6.2,
            'market_down_avg_pct' => 2.1,
            'active_positive_pct' => 70,
        ]);

        $this->assertSame('trend_day', $result['regime']);
        $this->assertSame('allow_momentum', $result['entry_bias']);
        $this->assertSame('normal', $result['risk_mode']);
    }

    public function test_classify_detects_trend_day_from_candidate_pool_without_snapshot(): void
    {
        $result = $this->service->classify([
            'sample_size' => 20,
            'gap_fade_pct' => 10,
            'breakout_follow_pct' => 60,
            'below_opening_low_pct' => 8,
            'volume_confirm_pct' => 70,
            'external_support_pct' => 65,
            'market_up_avg_pct' => 0,
            'market_down_avg_pct' => 0,
            'active_positive_pct' => 0,
            'snapshot_enhanced' => false,
        ]);

        $this->assertSame('trend_day', $result['regime']);
        $this->assertSame('selected_universe', $result['source']);
        $this->assertStringContainsString('不代表全市場大盤', $result['summary']);
    }

    public function test_classify_detects_gap_fade_day(): void
    {
        $result = $this->service->classify([
            'sample_size' => 25,
            'gap_fade_pct' => 68,
            'breakout_follow_pct' => 18,
            'below_opening_low_pct' => 40,
            'volume_confirm_pct' => 55,
            'external_support_pct' => 35,
            'market_up_avg_pct' => 4.8,
            'market_down_avg_pct' => 4.2,
            'active_positive_pct' => 45,
        ]);

        $this->assertSame('gap_fade_day', $result['regime']);
        $this->assertSame('avoid_chase', $result['entry_bias']);
        $this->assertSame('defensive', $result['risk_mode']);
    }

    public function test_classify_detects_selloff_day(): void
    {
        $result = $this->service->classify([
            'sample_size' => 18,
            'gap_fade_pct' => 50,
            'breakout_follow_pct' => 12,
            'below_opening_low_pct' => 42,
            'volume_confirm_pct' => 58,
            'external_support_pct' => 25,
            'market_up_avg_pct' => 2.6,
            'market_down_avg_pct' => 6.8,
            'active_positive_pct' => 20,
        ]);

        $this->assertSame('selloff_day', $result['regime']);
        $this->assertSame('stop_new_entries', $result['entry_bias']);
        $this->assertSame('defensive', $result['risk_mode']);
    }

    public function test_classify_detects_thin_day(): void
    {
        $result = $this->service->classify([
            'sample_size' => 16,
            'gap_fade_pct' => 25,
            'breakout_follow_pct' => 25,
            'below_opening_low_pct' => 20,
            'volume_confirm_pct' => 22,
            'external_support_pct' => 45,
            'market_up_avg_pct' => 3.1,
            'market_down_avg_pct' => 2.8,
            'active_positive_pct' => 48,
        ]);

        $this->assertSame('thin_day', $result['regime']);
        $this->assertSame('wait_confirmation', $result['entry_bias']);
        $this->assertSame('defensive', $result['risk_mode']);
    }

    public function test_classify_detects_unknown_when_no_data(): void
    {
        $result = $this->service->classify([
            'sample_size' => 0,
            'market_up_avg_pct' => 0,
            'market_down_avg_pct' => 0,
        ]);

        $this->assertSame('unknown', $result['regime']);
        $this->assertSame('wait_confirmation', $result['entry_bias']);
        $this->assertSame('cautious', $result['risk_mode']);
    }
}

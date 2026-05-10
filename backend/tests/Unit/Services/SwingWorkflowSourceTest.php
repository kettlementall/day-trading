<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

class SwingWorkflowSourceTest extends TestCase
{
    public function test_swing_research_and_screening_are_date_bounded(): void
    {
        $research = file_get_contents(__DIR__ . '/../../../app/Services/InvestmentThesisResearchService.php');
        $screener = file_get_contents(__DIR__ . '/../../../app/Services/SwingScreenerService.php');

        $this->assertStringContainsString("whereBetween('fetched_date', [\$from, \$date])", $research);
        $this->assertStringContainsString("whereBetween('date', [\$from, \$date])", $research);
        $this->assertStringContainsString("'research_date' => \$date", $research);
        $this->assertStringContainsString("orWhere('research_date', '<=', \$date)", $screener);
    }

    public function test_swing_position_advice_contains_daily_tracking_fields_and_stop_guard(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Services/SwingPositionUpdateService.php');

        $this->assertStringContainsString('chip_health', $source);
        $this->assertStringContainsString('technical_health', $source);
        $this->assertStringContainsString('thesis_health', $source);
        $this->assertStringContainsString('target_eta_days', $source);
        $this->assertStringContainsString('target_price_reasoning', $source);
        $this->assertStringContainsString('eta_reasoning', $source);
        $this->assertStringContainsString('previous_stop', $source);
        $this->assertStringContainsString('max($previousStop, $nextStop)', $source);
    }

    public function test_swing_screening_requires_complete_ai_response(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Services/SwingScreenerService.php');

        $this->assertStringContainsString('禁止退回規則分數產生候選', $source);
        $this->assertStringContainsString('askAiWithRetry', $source);
        $this->assertStringContainsString('maxAiAttempts = 3', $source);
        $this->assertStringContainsString('assertValidAiSelections', $source);
        $this->assertStringContainsString('短線 AI 選股回覆缺少候選', $source);
        $this->assertStringContainsString('短線 AI 選股重試', $source);
        $this->assertStringContainsString('缺少目標價或 ETA 數字理由', $source);
    }

    public function test_swing_screening_backfills_current_day_kline_from_fugle(): void
    {
        $screener = file_get_contents(__DIR__ . '/../../../app/Services/SwingScreenerService.php');
        $universe = file_get_contents(__DIR__ . '/../../../app/Console/Commands/RefreshSwingUniverse.php');

        $this->assertStringContainsString('FugleRealtimeClient $fugle', $screener);
        $this->assertStringContainsString('backfillDailyQuotesFromFugle', $screener);
        $this->assertStringContainsString('insufficient_kline_after_fugle', $screener);
        $this->assertStringNotContainsString('MIN_QUOTE_DAYS', $universe);
    }
}

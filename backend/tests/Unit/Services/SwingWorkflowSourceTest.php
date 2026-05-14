<?php

namespace Tests\Unit\Services;

use App\Models\SwingPosition;
use App\Services\SwingPositionUpdateService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class SwingWorkflowSourceTest extends TestCase
{
    public function test_swing_advice_normalization_preserves_stop_review_fields_and_blocks_lower_stop(): void
    {
        $position = new SwingPosition();
        $position->setRawAttributes([
            'current_stop' => 100,
            'current_target' => 120,
            'max_holding_days' => 20,
            'advice_log' => null,
        ], true);
        $position->setRelation('candidate', null);

        $advice = $this->invokeNormalizeAdvice($position, [
            'action' => 'hold',
            'current_stop' => 95,
            'current_target' => 118,
            'stop_breached' => true,
            'risk_zone_touched' => false,
            'stop_review_state' => 'thesis_intact',
            'stop_review_reasoning' => '大盤同步回檔，原始論點仍成立。',
            'repair_condition' => '下次檢討需收回短均並量縮止跌',
            'failure_condition' => '續弱且無法收回短均',
            'market_vs_stock_issue' => 'market_drag',
            'decision_summary' => '跌破停損但 thesis 仍成立，續抱觀察。',
            'why_not_exit' => '市場同步回檔，個股沒有失控。',
            'why_not_hold' => null,
        ]);

        $this->assertSame('hold', $advice['action']);
        $this->assertSame(100.0, $advice['current_stop']);
        $this->assertTrue($advice['stop_breached']);
        $this->assertFalse($advice['risk_zone_touched']);
        $this->assertSame('thesis_intact', $advice['stop_review_state']);
        $this->assertSame('下次檢討需收回短均並量縮止跌', $advice['repair_condition']);
        $this->assertSame('market_drag', $advice['market_vs_stock_issue']);
        $this->assertSame('跌破停損但 thesis 仍成立，續抱觀察。', $advice['decision_summary']);
        $this->assertSame('市場同步回檔，個股沒有失控。', $advice['why_not_exit']);
        $this->assertNull($advice['why_not_hold']);
    }

    public function test_swing_advice_normalization_separates_risk_zone_from_stop_review(): void
    {
        $position = new SwingPosition();
        $position->setRawAttributes([
            'current_stop' => 100,
            'current_target' => 120,
            'max_holding_days' => 20,
            'advice_log' => null,
        ], true);
        $position->setRelation('candidate', null);

        $advice = $this->invokeNormalizeAdvice($position, [
            'action' => 'trim',
            'current_stop' => 101,
            'current_target' => 118,
            'stop_breached' => false,
            'risk_zone_touched' => true,
            'stop_review_state' => 'damaged',
            'stop_review_reasoning' => '近期碰過停損區。',
            'repair_condition' => '量能回補且收回短均',
            'failure_condition' => '再度跌破停損',
            'why_not_exit' => '沒有真的跌破今日停損，thesis 尚未失效。',
            'why_not_hold' => '近期風險區受壓，先減碼。',
        ]);

        $this->assertSame('trim', $advice['action']);
        $this->assertFalse($advice['stop_breached']);
        $this->assertTrue($advice['risk_zone_touched']);
        $this->assertNull($advice['stop_review_state']);
        $this->assertNull($advice['stop_review_reasoning']);
        $this->assertSame('量能回補且收回短均', $advice['repair_condition']);
        $this->assertSame('沒有真的跌破今日停損，thesis 尚未失效。', $advice['why_not_exit']);
        $this->assertSame('近期風險區受壓，先減碼。', $advice['why_not_hold']);
    }

    public function test_swing_advice_normalization_tracks_previous_stop_review(): void
    {
        $position = new SwingPosition();
        $position->setRawAttributes([
            'current_stop' => 100,
            'current_target' => 120,
            'max_holding_days' => 20,
            'advice_log' => json_encode([
                [
                    'time' => '2026-05-13 18:50:00',
                    'action' => 'hold',
                    'stop_breached' => true,
                    'stop_review_state' => 'damaged',
                    'repair_condition' => '下次檢討需收回前低',
                    'failure_condition' => '未收回前低',
                    'reasoning' => '第一次停損審查給一天觀察。',
                ],
            ], JSON_UNESCAPED_UNICODE),
        ], true);
        $position->setRelation('candidate', null);

        $advice = $this->invokeNormalizeAdvice($position, [
            'action' => 'trim',
            'current_stop' => 101,
            'current_target' => 118,
        ]);

        $this->assertSame('damaged', $advice['previous_stop_review']['stop_review_state']);
        $this->assertSame('下次檢討需收回前低', $advice['previous_stop_review']['repair_condition']);
    }

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
        $this->assertStringContainsString('停損審查模式', $source);
        $this->assertStringContainsString('stop_review_state', $source);
        $this->assertStringContainsString('risk_zone_touched', $source);
        $this->assertStringContainsString('風險區觀察模式', $source);
        $this->assertStringContainsString('decision_summary', $source);
        $this->assertStringContainsString('why_not_exit', $source);
        $this->assertStringContainsString('why_not_hold', $source);
        $this->assertStringContainsString('repair_condition', $source);
        $this->assertStringContainsString('failure_condition', $source);
        $this->assertStringContainsString('market_vs_stock_issue', $source);
        $this->assertStringContainsString('lastStopReviewAdvice', $source);
        $this->assertStringContainsString('AI 停損審查失敗', $source);
        $this->assertStringNotContainsString('action **必須**輸出 exit', $source);
        $this->assertStringNotContainsString('禁止改為 hold/trim/adjust', $source);
        $this->assertStringNotContainsString('系統會強制 override 為 exit', $source);
        $this->assertStringNotContainsString('禁止簡單 hold', $source);
        $this->assertStringNotContainsString("\$ai['action'] = 'exit';", $source);
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

    public function test_investment_theses_support_related_stock_mapping_for_swing(): void
    {
        $research = file_get_contents(__DIR__ . '/../../../app/Services/InvestmentThesisResearchService.php');
        $screener = file_get_contents(__DIR__ . '/../../../app/Services/SwingScreenerService.php');
        $position = file_get_contents(__DIR__ . '/../../../app/Services/SwingPositionUpdateService.php');
        $model = file_get_contents(__DIR__ . '/../../../app/Models/InvestmentThesis.php');

        $this->assertStringContainsString('related_stocks', $model);
        $this->assertStringContainsString('normalizeRelatedStocks', $research);
        $this->assertStringContainsString('benefit_level', $research);
        $this->assertStringContainsString('findRelatedStock', $screener);
        $this->assertStringContainsString("'source' => 'related_stock'", $screener);
        $this->assertStringContainsString("min(60, \$score)", $screener);
        $this->assertStringContainsString('related_stock_context', $position);
    }

    private function invokeNormalizeAdvice(SwingPosition $position, array $advice): array
    {
        $reflector = new ReflectionClass(SwingPositionUpdateService::class);
        $service = $reflector->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(SwingPositionUpdateService::class, 'normalizeAdvice');
        $method->setAccessible(true);

        return $method->invoke($service, $position, $advice, null);
    }
}

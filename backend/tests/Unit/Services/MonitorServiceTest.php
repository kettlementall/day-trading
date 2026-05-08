<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * MonitorService source-level anchor 測試。
 *
 * 同 IntradayAiAdvisorTest 模式：用 file_get_contents 對 source 做字串檢查，
 * 避免為了測試重構 evaluateWatching（這方法依賴 DB 與大量 service collaborator）。
 *
 * 重點：規則層進場前的 AI 即時確認握手機制。
 */
class MonitorServiceTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(
            __DIR__ . '/../../../app/Services/MonitorService.php'
        );
    }

    public function test_constructor_injects_intradayAiAdvisor(): void
    {
        // DI 必須包含 aiAdvisor，否則 confirmRuleEntry 無法呼叫
        $this->assertStringContainsString('private IntradayAiAdvisor $aiAdvisor', $this->source);
    }

    public function test_evaluateWatching_calls_confirmRuleEntry_before_transition(): void
    {
        // 呼叫 confirmRuleEntry
        $this->assertStringContainsString('$this->aiAdvisor->confirmRuleEntry(', $this->source);
        // 寫入 entry_confirm_log（獨立於 ai_advice_log，避免影響下游）
        $this->assertStringContainsString('logEntryConfirm(', $this->source);
    }

    public function test_evaluateWatching_handles_three_confirm_actions(): void
    {
        // wait → return（不進場、下輪重評）
        $this->assertStringContainsString("\$confirm['action'] === 'wait'", $this->source);
        $this->assertStringContainsString('AI 即時確認 wait', $this->source);

        // skip → 走 skipByAiAdvice
        $this->assertStringContainsString("\$confirm['action'] === 'skip'", $this->source);
        $this->assertStringContainsString('AI 即時確認 skip', $this->source);

        // go 路徑（隱式：通過前兩個 if 後進入共用進場 executor）
        $this->assertStringContainsString("'go' → 進入共用進場 executor", $this->source);
    }

    public function test_confirmRuleEntry_call_uses_strategy_and_entryType(): void
    {
        // 呼叫時應傳入 strategy 與 entryType 作為 trigger reason，便於事後分析
        $this->assertStringContainsString('{$strategy} 觸發（{$entryType}）', $this->source);
    }

    public function test_rolling_ai_entry_uses_ai_led_entry_flow(): void
    {
        // rolling advice action=entry 必須能主動嘗試進場，而不是只升格等待規則層下輪觸發
        $this->assertStringContainsString('tryEnterFromRollingAdvice($monitor, $advice)', $this->source);
        $this->assertStringContainsString('AI rolling entry override', $this->source);
        $this->assertStringContainsString("'entry_type' => \$entryType", $this->source);
    }

    public function test_rolling_ai_entry_keeps_hard_risk_checks(): void
    {
        // AI 主導不代表無條件追價；硬風控仍保留在進場前
        $this->assertStringContainsString('hardEntryBlockReason(', $this->source);
        $this->assertStringContainsString('接近漲停', $this->source);
        $this->assertStringContainsString('跌破停損', $this->source);
    }

    public function test_threshold_exits_record_threshold_price_not_snapshot_price(): void
    {
        // 停損/達標觸發時，前端與 DailyReview 應呈現實際委託/策略價位，
        // 不應把低於停損或高於目標的快照現價寫成成交出場價。
        $this->assertStringContainsString("\$this->exitPosition(\$monitor, \$target, 'target_hit'", $this->source);
        $this->assertStringContainsString("\$this->exitPosition(\$monitor, \$stop, 'stop_hit'", $this->source);
        $this->assertStringNotContainsString("\$this->exitPosition(\$monitor, \$price, 'target_hit'", $this->source);
        $this->assertStringNotContainsString("\$this->exitPosition(\$monitor, \$price, 'stop_hit'", $this->source);
    }

    public function test_c_grade_entry_is_not_timeboxed_to_pre_11_upgrade_only(): void
    {
        // 舊版 C 級 action=entry 在 11:00 前只升格並 return，導致 AI entry 不會真的進場
        $this->assertStringNotContainsString("now()->hour < 11", $this->source);
        $this->assertStringContainsString('[當沖AI升格 C→B]', $this->source);
    }
}

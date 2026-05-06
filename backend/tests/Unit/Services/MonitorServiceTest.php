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

        // go 路徑（隱式：通過前兩個 if 後繼續執行 transition）
        $this->assertStringContainsString("'go' → 繼續執行原本的 transition", $this->source);
    }

    public function test_confirmRuleEntry_call_uses_strategy_and_entryType(): void
    {
        // 呼叫時應傳入 strategy 與 entryType 作為 trigger reason，便於事後分析
        $this->assertStringContainsString('{$strategy} 觸發（{$entryType}）', $this->source);
    }
}

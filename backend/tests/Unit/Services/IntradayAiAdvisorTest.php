<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * 盤中 AI 滾動建議 prompt 構築錨點測試。
 *
 * 用 file_get_contents 對 source 做 anchor 字串檢查（buildRollingSystemPrompt
 * 與 buildRollingUserMessage 是 private，且為大型 heredoc，抽 method 會造成
 * 大段移位）。這類測試的價值是「防誤刪」：日後重構不慎拿掉關鍵段落，
 * 測試會立刻失敗。
 *
 * 執行方式：
 *   docker compose exec php ./vendor/bin/phpunit --testsuite=Unit
 */
class IntradayAiAdvisorTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(
            __DIR__ . '/../../../app/Services/IntradayAiAdvisor.php'
        );
    }

    public function test_systemPrompt_contains_strategy_state_skip_framework(): void
    {
        $this->assertStringContainsString('策略狀態與 skip 原則', $this->source);
        $this->assertStringContainsString('valid：原策略仍有效', $this->source);
        $this->assertStringContainsString('switched：原策略已不適合', $this->source);
        $this->assertStringContainsString('uncertain：訊號不足或矛盾', $this->source);
        $this->assertStringContainsString('failed：結構明確失敗', $this->source);
        $this->assertStringContainsString('skip 只用在 strategy_state=failed', $this->source);
    }

    public function test_systemPrompt_softens_limit_up_skip_directive(): void
    {
        // 舊措辭應被移除（壓力位 = 漲停價代表上方無獲利空間，應建議 skip）
        $this->assertStringNotContainsString('壓力位 = 漲停價代表上方無獲利空間，應建議 skip', $this->source);
        // 新措辭：先評估階段性壓力或切策略
        $this->assertStringContainsString('先評估是否能設更近的階段性壓力', $this->source);
        $this->assertStringContainsString('沒有合理目標或流動性不足時才 skip', $this->source);
    }

    public function test_userMessage_watching_task_uses_priority_decision_tree(): void
    {
        // 新任務段標題（依優先順序判斷）
        $this->assertStringContainsString('## 任務（觀望中）— 依優先順序判斷', $this->source);
        // 4 個明確順序步驟
        $this->assertStringContainsString('### 1. 策略適配檢查', $this->source);
        $this->assertStringContainsString('### 2. 進場品質評估', $this->source);
        $this->assertStringContainsString('### 3. 是否該 skip', $this->source);
        $this->assertStringContainsString('只有 strategy_state=failed 才 skip', $this->source);
        $this->assertStringContainsString('都不是 failed；優先 hold 或 switched', $this->source);
    }

    public function test_userMessage_strategy_switch_table_only_lists_switch_scenarios(): void
    {
        // 4 個切換場景 + 1 個 skip 場景（共 5 列）
        $this->assertStringContainsString('開盤跳空已超壓力位且不回頭', $this->source);
        $this->assertStringContainsString('突破壓力後回測支撐附近止穩', $this->source);
        $this->assertStringContainsString('突破失敗破支撐後在更低支撐反彈', $this->source);
        $this->assertStringContainsString('超跌反彈日跳空缺口不回補 + 量能放大', $this->source);
        $this->assertStringContainsString('連續跌破多個支撐 + 量縮無撐', $this->source);

        // 觸發場景應已從對照表移除（避免 AI 在原策略仍生效時誤切換）
        $this->assertStringNotContainsString('強勢站穩前高且量能維持', $this->source);
        $this->assertStringNotContainsString('跳空高開後拉回到支撐附近', $this->source);
    }

    public function test_prompts_require_strategy_validity_before_skipping(): void
    {
        $this->assertStringContainsString('先做策略狀態判斷', $this->source);
        $this->assertStringContainsString('strategy_state', $this->source);
        $this->assertStringContainsString('沒等到拉回，不等於 failed', $this->source);
        $this->assertStringContainsString('不可只因「沒到原買點」就 skip', $this->source);
    }

    public function test_old_conflicting_skip_wording_is_removed(): void
    {
        $this->assertStringNotContainsString('無條件 skip 的判斷原則', $this->source);
        $this->assertStringNotContainsString('凌駕於「skip 是最後選項」', $this->source);
        $this->assertStringNotContainsString('典型潛在報酬 +3~10%', $this->source);
        $this->assertStringNotContainsString('當日跌幅 ≥ -2%', $this->source);
    }

    public function test_userMessage_entryTrigger_softens_limit_up_skip_message(): void
    {
        // 舊訊息應移除
        $this->assertStringNotContainsString('壓力位即漲停價，上方無獲利空間，建議 skip', $this->source);
        // 新訊息：先設階段性壓力或切策略
        $this->assertStringContainsString('建議 adjustments.target 設階段性壓力', $this->source);
        $this->assertStringContainsString('考慮 strategy 切換', $this->source);
    }

    public function test_confirmRuleEntry_method_exists_with_correct_signature(): void
    {
        // confirmRuleEntry 方法存在
        $this->assertStringContainsString('public function confirmRuleEntry(', $this->source);
        // 用獨立的 entryConfirmModel（Haiku）
        $this->assertStringContainsString('entryConfirmModel', $this->source);
        // 失敗 fallback 是 wait（保守不進場）
        $this->assertStringContainsString("'action' => 'wait'", $this->source);
        $this->assertStringContainsString("'fallback' => true", $this->source);
    }

    public function test_entryConfirm_systemPrompt_contains_decision_principles(): void
    {
        // 核心角色描述
        $this->assertStringContainsString('規則層進場前的最終確認 AI', $this->source);
        // 三個 action 的判定原則
        $this->assertStringContainsString('**go**', $this->source);
        $this->assertStringContainsString('**wait**', $this->source);
        $this->assertStringContainsString('**skip**', $this->source);
        // 對稱成本提示（誤進場 > 誤延後）
        $this->assertStringContainsString('誤進場成本', $this->source);
        $this->assertStringContainsString('疑似訊號優先 wait', $this->source);
        // 警示型措辭範例
        $this->assertStringContainsString('「謹慎」', $this->source);
        $this->assertStringContainsString('「不宜」', $this->source);
        $this->assertStringContainsString('「等止穩」', $this->source);
    }

    public function test_entryConfirm_userMessage_includes_required_context(): void
    {
        // 規則觸發理由
        $this->assertStringContainsString('規則觸發', $this->source);
        // 最近 rolling advice 段落（最關鍵 context）
        $this->assertStringContainsString('最近 rolling advice', $this->source);
        // K 線結構
        $this->assertStringContainsString('最近 3 根 5 分 K', $this->source);
        // 任務指引：警示型措辭優先 wait
        $this->assertStringContainsString('優先 wait', $this->source);
    }

    public function test_rolling_prompt_separates_strategy_state_from_entry_quality(): void
    {
        $this->assertStringContainsString('策略狀態 ≠ 進場品質', $this->source);
        $this->assertStringContainsString('switched 只代表原策略不適合但可改用新策略，不等於 entry', $this->source);
        $this->assertStringContainsString('不可直接把「錯過」合理化成追高', $this->source);
        $this->assertStringContainsString('entry_timing：good / early / late_chase / wait_pullback / no_trade', $this->source);
        $this->assertStringContainsString('entry_quality：0-100', $this->source);
        $this->assertStringContainsString('chase_risk：0-100', $this->source);
    }

    public function test_rolling_response_format_requires_entry_quality_fields(): void
    {
        $this->assertStringContainsString('"entry_timing": "wait_pullback"', $this->source);
        $this->assertStringContainsString('"entry_quality": 45', $this->source);
        $this->assertStringContainsString('"chase_risk": 70', $this->source);
        $this->assertStringContainsString('entry_timing 必填 good/early/late_chase/wait_pullback/no_trade', $this->source);
    }

    public function test_entryConfirm_uses_rolling_entry_quality_context(): void
    {
        $this->assertStringContainsString('entry_timing 是 late_chase / wait_pullback', $this->source);
        $this->assertStringContainsString('entry_quality 低、chase_risk 高', $this->source);
        $this->assertStringContainsString('timing=%s quality=%s chase=%s', $this->source);
        $this->assertStringContainsString('若最近 advice 顯示 entry_timing=late_chase/wait_pullback', $this->source);
    }

    public function test_rolling_prompt_includes_selected_universe_regime_context(): void
    {
        $this->assertStringContainsString('候選池盤中環境使用原則', $this->source);
        $this->assertStringContainsString('不等於全市場大盤', $this->source);
        $this->assertStringContainsString('regime=gap_fade_day', $this->source);
        $this->assertStringContainsString('regime=trend_day', $this->source);
        $this->assertStringContainsString('regime=selloff_day', $this->source);
        $this->assertStringContainsString('## 今日候選池盤中環境', $this->source);
        $this->assertStringContainsString('source:', $this->source);
        $this->assertStringContainsString('entry_bias:', $this->source);
        $this->assertStringContainsString('risk_mode:', $this->source);
    }

    public function test_rolling_and_emergency_advice_accept_market_regime(): void
    {
        $this->assertStringContainsString('public function rollingAdvice(string $date, CandidateMonitor $monitor, Collection $allSnapshots, ?array $marketRegime = null)', $this->source);
        $this->assertStringContainsString('public function emergencyAdvice(string $date, CandidateMonitor $monitor, Collection $allSnapshots, string $reason, ?array $marketRegime = null)', $this->source);
        $this->assertStringContainsString('formatSelectedUniverseRegimeSection(?array $marketRegime)', $this->source);
    }
}

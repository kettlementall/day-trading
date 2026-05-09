<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * 單日 AI 檢討 prompt 錨點測試。
 *
 * DailyReviewService 的報告 prompt 是大型 private heredoc；這裡直接掃描
 * source，確保關鍵敘述不會在重構時被誤刪。
 */
class DailyReviewServiceTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(
            __DIR__ . '/../../../app/Services/DailyReviewService.php'
        );
    }

    public function test_overnight_review_prompt_separates_theoretical_and_monitor_results(): void
    {
        $this->assertStringContainsString('理論盤後結果與監控結果必須分開解讀', $this->source);
        $this->assertStringContainsString('theoretical_outcome/theoretical_profit%', $this->source);
        $this->assertStringContainsString('monitor_status/monitor_exit/monitor_profit%', $this->source);
        $this->assertStringContainsString('理論盤後結果', $this->source);
        $this->assertStringContainsString('監控執行結果', $this->source);
        $this->assertStringContainsString('請勿描述為真實成交報酬', $this->source);
    }

    public function test_overnight_review_prompt_distinguishes_planned_and_final_levels(): void
    {
        $this->assertStringContainsString('plan_target', $this->source);
        $this->assertStringContainsString('plan_stop', $this->source);
        $this->assertStringContainsString('final_target/final_stop=監控最後使用目標/停損', $this->source);
        $this->assertStringContainsString('planned_target/planned_stop=原始計畫目標/停損', $this->source);
        $this->assertStringContainsString('monitor_status=target_hit 不代表原始 planned_target 達標', $this->source);
        $this->assertStringContainsString('exit_basis=monitor_exit 的價格依據', $this->source);
    }
}

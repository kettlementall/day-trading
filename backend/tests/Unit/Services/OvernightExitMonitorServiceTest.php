<?php

namespace Tests\Unit\Services;

use App\Services\OvernightExitMonitorService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 隔日沖出場監控 Sonnet prompt 構築相關的純函數測試。
 *
 * 不需要 Laravel container，直接走 PHPUnit\Framework\TestCase。
 *
 * 執行方式：
 *   docker compose exec php php artisan test --filter=OvernightExitMonitorServiceTest
 *   或
 *   docker compose exec php ./vendor/bin/phpunit --testsuite=Unit
 */
class OvernightExitMonitorServiceTest extends TestCase
{
    #[DataProvider('gapDiffProvider')]
    public function test_classifyGapDiff_returns_correct_label(float $gapDiff, string $expected): void
    {
        $this->assertSame($expected, OvernightExitMonitorService::classifyGapDiff($gapDiff));
    }

    public static function gapDiffProvider(): array
    {
        return [
            '顯著超預期上界'        => [3.0, '顯著超預期'],
            '顯著超預期 1.51'       => [1.51, '顯著超預期'],
            '小幅超預期 1.5（邊界）' => [1.5, '小幅超預期'],
            '小幅超預期 0.6'        => [0.6, '小幅超預期'],
            '符合預期 0.5（邊界）'   => [0.5, '符合預期'],
            '符合預期 0'            => [0.0, '符合預期'],
            '符合預期 -0.5（邊界）'  => [-0.5, '符合預期'],
            // 2303 聯電案例：實際 -0.49% vs 預期 +1% → diff = -1.49 → 應落在「小幅偏弱（雜訊範圍）」
            '小幅偏弱 -1.49（聯電案例）' => [-1.49, '小幅偏弱（雜訊範圍）'],
            '小幅偏弱 -0.6'             => [-0.6, '小幅偏弱（雜訊範圍）'],
            '小幅偏弱 -1.5（邊界）'      => [-1.5, '小幅偏弱（雜訊範圍）'],
            '顯著不及預期 -1.51'        => [-1.51, '顯著不及預期'],
            '顯著不及預期 -5'           => [-5.0, '顯著不及預期'],
        ];
    }

    /**
     * 驗證 systemPrompt 內含關鍵錨點字串。
     *
     * 用「讀檔案 grep」而非 reflection — Sonnet prompt 是 heredoc 字面值，
     * 直接掃描原始碼可避免抽 method 造成大段 heredoc 移位。
     *
     * 這類測試的價值是「防誤刪」：若日後有人重構不慎拿掉容忍度框架，
     * 這些 assertion 會立刻失敗。
     */
    public function test_systemPrompt_contains_strategy_tolerance_anchors(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../../app/Services/OvernightExitMonitorService.php'
        );

        // 容忍度框架四種策略
        $this->assertStringContainsString('gap_up_open（跳空高開）', $source);
        $this->assertStringContainsString('pullback_entry（拉回建倉）', $source);
        $this->assertStringContainsString('open_follow_through（延續開盤）', $source);
        $this->assertStringContainsString('limit_up_chase（漲停追強）', $source);

        // 策略狀態框架
        $this->assertStringContainsString('策略狀態與出場框架', $source);
        $this->assertStringContainsString('strategy_state：valid / adjusted / uncertain / failed', $source);
        $this->assertStringContainsString('exit 只用在 strategy_state=failed', $source);

        // 早盤紀律
        $this->assertStringContainsString('早盤觀察期紀律', $source);
        $this->assertStringContainsString('優先 uncertain/hold 或 adjusted', $source);

        // 舊的硬失效邊界與優先級拉扯應移除
        $this->assertStringNotContainsString('跳空 < -2% 直接視為策略失效', $source);
        $this->assertStringNotContainsString('現價對昨收跌幅 > 3%', $source);
        $this->assertStringNotContainsString('早盤紀律不覆蓋', $source);

        // 跳空驗證的雜訊緩衝
        $this->assertStringContainsString('跳空不如預期不是單獨 exit 理由', $source);

        // reasoning 三段格式要求
        $this->assertStringContainsString('reasoning 必須包含三段', $source);

        // 跳空定義
        $this->assertStringContainsString('(T+1 開盤 − T+0 收盤) / T+0 收盤', $source);
    }
}

<?php

namespace App\Console\Commands;

use App\Services\BacktestOptimizer;
use App\Services\BacktestService;
use Illuminate\Console\Command;

class RunBacktest extends Command
{
    protected $signature = 'stock:backtest
        {--from= : 分析起始日（預設60天前）}
        {--to= : 分析結束日（預設今天）}
        {--optimize : 執行 AI 優化分析（單次）}
        {--apply : 自動套用 AI 建議（搭配 --optimize）}
        {--validated : 帶驗證的優化循環（AI 建議 → 重跑 → 比較 → 回滾重試，最多10次）}
        {--max-attempts=10 : 最大嘗試次數（搭配 --validated）}';

    protected $description = '執行回測分析，檢視交易建議的實戰表現';

    public function handle(): int
    {
        $from = $this->option('from') ?? now()->subDays(60)->format('Y-m-d');
        $to = $this->option('to') ?? now()->format('Y-m-d');

        $this->info("回測期間：{$from} ~ {$to}");
        $this->newLine();

        // 帶驗證的優化循環
        if ($this->option('validated')) {
            return $this->runValidatedOptimization($from, $to);
        }

        // 顯示回測指標
        $service = new BacktestService();
        $metrics = $service->computeMetrics($from, $to);

        $this->displayMetrics($metrics);

        // 顯示選股品質指標
        if (!empty($metrics['screening'])) {
            $screening = $metrics['screening'];
            $this->newLine();
            $this->info('▎ 選股品質');

            $screeningRows = [
                ['平均評分', $screening['avg_score']],
                ['每日候選數', $screening['candidates_per_day']],
            ];

            if (!empty($screening['strategy_distribution'])) {
                foreach ($screening['strategy_distribution'] as $type => $count) {
                    $label = $type === 'bounce' ? '跌深反彈' : ($type === 'breakout' ? '突破追多' : $type);
                    $screeningRows[] = ["策略分布 - {$label}", $count . ' 檔'];
                }
            }

            $outcome = $screening['avg_score_by_outcome'] ?? [];
            if (!empty($outcome)) {
                $screeningRows[] = ['獲利組平均分數', $outcome['win'] ?? 0];
                $screeningRows[] = ['虧損組平均分數', $outcome['loss'] ?? 0];
                $screeningRows[] = ['未買到組平均分數', $outcome['miss'] ?? 0];
            }

            $this->table(['指標', '數值'], $screeningRows);

            if (!empty($screening['reason_frequency'])) {
                $this->info('  選股理由觸發頻率（前10）：');
                $i = 0;
                foreach ($screening['reason_frequency'] as $reason => $pct) {
                    if (++$i > 10) break;
                    $this->line("    {$reason}: {$pct}%");
                }
            }
        }

        // 顯示策略分類指標
        if (!empty($metrics['by_strategy'])) {
            foreach ($metrics['by_strategy'] as $type => $stratMetrics) {
                $this->newLine();
                $label = $type === 'bounce' ? '跌深反彈' : '突破追多';
                $this->info("▎ {$label} ({$type})");
                $this->displayMetrics($stratMetrics, '  ');
            }
        }

        // AI 優化（單次）
        if ($this->option('optimize')) {
            $this->newLine();
            $this->info('正在執行 AI 優化分析...');

            $optimizer = new BacktestOptimizer();
            $round = $optimizer->analyze($from, $to);

            $suggestions = $round->suggestions;
            $this->newLine();
            $this->info('▎ AI 分析結果');
            $this->line("  分析：{$suggestions['analysis']}");

            if (!empty($suggestions['adjustments'])) {
                $this->newLine();
                $this->info('  建議調整：');
                foreach ($suggestions['adjustments'] as $type => $changes) {
                    $this->line("  [{$type}]");
                    foreach ($changes as $key => $value) {
                        $this->line("    {$key}: {$value}");
                    }
                    if (isset($suggestions['reasoning'][$type])) {
                        $this->line("    原因：{$suggestions['reasoning'][$type]}");
                    }
                }

                if ($this->option('apply')) {
                    $optimizer->applyRound($round);
                    $this->newLine();
                    $this->info('已套用 AI 建議到公式設定');
                } else {
                    $this->newLine();
                    $this->comment('使用 --apply 選項可自動套用建議');
                }
            } else {
                $this->line('  無需調整');
            }

            $this->line("  回測紀錄 ID：{$round->id}");
        }

        return self::SUCCESS;
    }

    private function runValidatedOptimization(string $from, string $to): int
    {
        $maxAttempts = (int) $this->option('max-attempts');
        $optimizer = new BacktestOptimizer();

        $this->info("▎ 啟動帶驗證的優化循環（最多 {$maxAttempts} 次嘗試）");
        $this->newLine();

        $result = $optimizer->optimizeWithValidation(
            $from,
            $to,
            $maxAttempts,
            fn (string $msg) => $this->line($msg)
        );

        $this->newLine();
        $this->info('═══════════════════════════════════');

        if ($result['improved']) {
            $this->info("✓ 優化成功（第 {$result['attempts']} 輪找到改善）");
        } else {
            $this->warn("✗ {$result['attempts']} 輪嘗試皆未改善，維持原參數");
        }

        $this->info('═══════════════════════════════════');

        return self::SUCCESS;
    }

    private function displayMetrics(array $metrics, string $indent = ''): void
    {
        $this->table(
            ['指標', '數值'],
            [
                ['候選標的數', $metrics['total_candidates']],
                ['已驗證', $metrics['evaluated']],
                ['買入可達率', $metrics['buy_reach_rate'] . '%'],
                ['目標可達率', $metrics['target_reach_rate'] . '%'],
                ['雙達率', $metrics['dual_reach_rate'] . '%'],
                ['期望值', $metrics['expected_value'] . '%'],
                ['停損觸及率', $metrics['hit_stop_loss_rate'] . '%'],
                ['平均買入間距', $metrics['avg_buy_gap'] . '%'],
                ['平均目標間距', $metrics['avg_target_gap'] . '%'],
                ['平均風報比', $metrics['avg_risk_reward']],
            ]
        );
    }
}

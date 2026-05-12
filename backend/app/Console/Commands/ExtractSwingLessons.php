<?php

namespace App\Console\Commands;

use App\Services\SwingLessonExtractor;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ExtractSwingLessons extends Command
{
    protected $signature = 'stock:extract-swing-lessons
        {--week-end= : 指定週日（YYYY-MM-DD），預設本週日}
        {--weeks=1 : 一次跑 N 週（往回推）}
        {--dry-run : 只印 prompt 與 LLM 回覆，不寫 DB}';

    protected $description = '從本週使用者關閉的短線持倉 + AI 建議軌跡 + 出場後 5 日股價，萃取教訓寫入 ai_lessons (mode=swing)';

    public function handle(SwingLessonExtractor $extractor): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $weeks  = max(1, (int) $this->option('weeks'));
        $end    = $this->option('week-end')
            ? Carbon::parse($this->option('week-end'))
            : Carbon::now()->endOfWeek(Carbon::SUNDAY);

        $totalWritten = 0;
        $totalCases = 0;

        for ($i = 0; $i < $weeks; $i++) {
            $weekEnd = $end->copy()->subWeeks($i)->toDateString();
            $this->info("\n=== 週末錨點：{$weekEnd}" . ($dryRun ? '（dry-run）' : '') . " ===");

            $result = $extractor->extract($weekEnd, $dryRun, fn (string $m) => $this->line($m));

            $totalWritten += $result['written'] ?? 0;
            $totalCases   += $result['cases'] ?? 0;

            if (!empty($result['skipped_reason'])) {
                $this->warn("跳過原因：{$result['skipped_reason']}");
            }
        }

        $this->newLine();
        $this->info("總計：{$totalWritten} 條教訓寫入，掃描 {$totalCases} 筆持倉");

        if (!$dryRun && $totalWritten > 0) {
            try {
                app(TelegramService::class)->broadcast(
                    "📚 *短線教訓萃取完成*\n寫入 {$totalWritten} 條 / 掃描 {$totalCases} 筆持倉",
                    'system'
                );
            } catch (\Throwable $e) {
                // Telegram 失敗不影響主流程
            }
        }

        return self::SUCCESS;
    }
}

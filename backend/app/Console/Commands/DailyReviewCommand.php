<?php

namespace App\Console\Commands;

use App\Models\DailyReview;
use App\Services\DailyReviewService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DailyReviewCommand extends Command
{
    protected $signature = 'stock:daily-review {date?} {--mode=intraday} {--force : 強制重新產出報告（覆蓋既有）} {--extract-only : 僅從既有報告重新萃取教訓}';
    protected $description = '產出單日 AI 檢討報告並萃取教訓';

    public function handle(): int
    {
        $mode = $this->option('mode');
        $date = $this->argument('date') ?? now()->subDay()->format('Y-m-d');

        if (!in_array($mode, ['intraday', 'overnight'])) {
            $this->error("--mode 必須為 intraday 或 overnight");
            return 1;
        }

        // --extract-only: 從既有報告重新萃取教訓
        if ($this->option('extract-only')) {
            return $this->reExtractLessons($date, $mode);
        }

        // 已有報告就跳過（除非 --force）
        if (!$this->option('force') && DailyReview::where('trade_date', $date)->where('mode', $mode)->exists()) {
            $this->info("{$date} [{$mode}] 報告已存在，跳過（可用 --force 強制重跑）");
            return 0;
        }

        $this->info("開始產出 {$date} [{$mode}] AI 檢討報告...");

        $service = new DailyReviewService();
        $result = $service->review($date, function (string $msg) {
            $this->line($msg);
        }, null, $mode);

        if (isset($result['error'])) {
            $this->warn($result['error']);
            return 1;
        }

        $this->info("報告已存入 DB（{$result['candidates_count']} 檔候選標的）");
        return 0;
    }

    /**
     * 從既有報告重新萃取教訓（不重跑 AI 分析）
     */
    private function reExtractLessons(string $date, string $mode): int
    {
        $review = DailyReview::where('trade_date', $date)->where('mode', $mode)->first();

        if (!$review) {
            $this->error("{$date} [{$mode}] 尚無檢討報告，請先執行完整檢討");
            return 1;
        }

        $this->info("從既有報告重新萃取教訓（{$date} [{$mode}]，{$review->candidates_count} 檔）...");

        $service = new DailyReviewService();

        try {
            $method = $mode === 'overnight' ? 'extractOvernightLessons' : 'extractLessons';
            // 用 ReflectionMethod 呼叫 private 方法
            $ref = new \ReflectionMethod($service, $method);
            $ref->setAccessible(true);
            $count = $ref->invoke($service, $date, $review->report);

            $this->info("教訓萃取完成（新增 {$count} 條）");
            return 0;
        } catch (\Exception $e) {
            $this->error("教訓萃取失敗：{$e->getMessage()}");
            Log::error("DailyReviewCommand reExtractLessons: " . $e->getMessage());
            return 1;
        }
    }
}

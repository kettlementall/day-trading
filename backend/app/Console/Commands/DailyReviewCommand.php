<?php

namespace App\Console\Commands;

use App\Models\DailyReview;
use App\Models\MarketHoliday;
use App\Services\DailyReviewService;
use Illuminate\Console\Command;

class DailyReviewCommand extends Command
{
    protected $signature = 'stock:daily-review {date?} {--mode=intraday} {--force : 強制重新產出報告（覆蓋既有）}';
    protected $description = '產出單日 AI 檢討報告';

    public function handle(): int
    {
        $mode = $this->option('mode');
        $date = $this->argument('date') ?? now()->format('Y-m-d');

        if (!in_array($mode, ['intraday', 'overnight'])) {
            $this->error("--mode 必須為 intraday 或 overnight");
            return 1;
        }

        // 排程執行時跳過休市日（手動傳入 date 時不檢查）
        if (!$this->argument('date') && MarketHoliday::isHoliday($date)) {
            $this->info("{$date} 為休市日，跳過");
            return 0;
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

}

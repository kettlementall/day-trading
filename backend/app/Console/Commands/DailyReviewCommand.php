<?php

namespace App\Console\Commands;

use App\Models\DailyReview;
use App\Services\DailyReviewService;
use Illuminate\Console\Command;

class DailyReviewCommand extends Command
{
    protected $signature = 'stock:daily-review {date?} {--mode=intraday}';
    protected $description = '產出單日 AI 檢討報告並萃取教訓';

    public function handle(): int
    {
        $mode = $this->option('mode');
        $date = $this->argument('date') ?? now()->subDay()->format('Y-m-d');

        if (!in_array($mode, ['intraday', 'overnight'])) {
            $this->error("--mode 必須為 intraday 或 overnight");
            return 1;
        }

        // 已有報告就跳過
        if (DailyReview::where('trade_date', $date)->where('mode', $mode)->exists()) {
            $this->info("{$date} [{$mode}] 報告已存在，跳過");
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

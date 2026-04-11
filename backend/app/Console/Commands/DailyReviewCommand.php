<?php

namespace App\Console\Commands;

use App\Models\DailyReview;
use App\Services\DailyReviewService;
use Illuminate\Console\Command;

class DailyReviewCommand extends Command
{
    protected $signature = 'stock:daily-review {date?}';
    protected $description = '產出單日 AI 檢討報告並萃取教訓';

    public function handle(): int
    {
        $date = $this->argument('date') ?? now()->subDay()->format('Y-m-d');

        // 已有報告就跳過
        if (DailyReview::where('trade_date', $date)->exists()) {
            $this->info("{$date} 報告已存在，跳過");
            return 0;
        }

        $this->info("開始產出 {$date} AI 檢討報告...");

        $service = new DailyReviewService();
        $result = $service->review($date, function (string $msg) {
            $this->line($msg);
        });

        if (isset($result['error'])) {
            $this->warn($result['error']);
            return 1;
        }

        $this->info("報告已存入 DB（{$result['candidates_count']} 檔候選標的）");
        return 0;
    }
}

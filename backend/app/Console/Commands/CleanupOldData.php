<?php

namespace App\Console\Commands;

use App\Models\AiLesson;
use App\Models\IntradaySnapshot;
use Illuminate\Console\Command;

class CleanupOldData extends Command
{
    protected $signature = 'stock:cleanup {--days=30 : 保留天數}';
    protected $description = '清理過期的盤中快照與 AI 教訓資料';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days)->toDateString();

        // 1. 清理舊快照
        $snapshotCount = IntradaySnapshot::where('trade_date', '<', $cutoff)->count();
        if ($snapshotCount > 0) {
            IntradaySnapshot::where('trade_date', '<', $cutoff)->delete();
            $this->info("已刪除 {$snapshotCount} 筆超過 {$days} 天的盤中快照");
        } else {
            $this->info("無需清理的盤中快照");
        }

        // 2. 清理過期 AI 教訓
        $lessonCount = AiLesson::where('expires_at', '<', now()->toDateString())->count();
        if ($lessonCount > 0) {
            AiLesson::where('expires_at', '<', now()->toDateString())->delete();
            $this->info("已刪除 {$lessonCount} 筆過期 AI 教訓");
        } else {
            $this->info("無需清理的 AI 教訓");
        }

        return self::SUCCESS;
    }
}

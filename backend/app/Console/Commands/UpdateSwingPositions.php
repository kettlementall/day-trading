<?php

namespace App\Console\Commands;

use App\Models\MarketHoliday;
use App\Services\SwingPositionUpdateService;
use App\Services\TelegramService;
use Illuminate\Console\Command;

class UpdateSwingPositions extends Command
{
    protected $signature = 'stock:update-swing-positions {date?}';
    protected $description = '每日盤後更新短線持倉';

    public function handle(SwingPositionUpdateService $service): int
    {
        $date = $this->argument('date') ?? now()->toDateString();
        if (!$this->argument('date') && MarketHoliday::isHoliday($date)) {
            $this->info("{$date} 休市，跳過短線持倉更新");
            return self::SUCCESS;
        }

        $summary = $service->update($date);

        app(TelegramService::class)->broadcast(sprintf(
            "✅ *短線持倉更新* 完成\n📅 %s | 檢查 %d | 續抱 %d | 調整 %d | 出場建議 %d",
            $date,
            $summary['checked'],
            $summary['hold'],
            $summary['adjust'],
            $summary['exit']
        ), 'system');

        $this->info('短線持倉更新完成');
        return self::SUCCESS;
    }
}

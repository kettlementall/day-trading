<?php

namespace App\Console\Commands;

use App\Models\MarketHoliday;
use App\Services\SwingScreenerService;
use App\Services\TelegramService;
use Illuminate\Console\Command;

class AiScreenSwingCandidates extends Command
{
    protected $signature = 'stock:ai-screen-swing {date?}';
    protected $description = 'AI 短線候選選股';

    public function handle(SwingScreenerService $service): int
    {
        $date = $this->argument('date') ?? now()->toDateString();
        if (!$this->argument('date') && MarketHoliday::isHoliday($date)) {
            $this->info("{$date} 休市，跳過短線選股");
            return self::SUCCESS;
        }

        $candidates = $service->screen($date);
        $selected = $candidates->where('ai_selected', true)->count();

        app(TelegramService::class)->broadcast(
            "✅ *短線 AI 選股* 完成\n📅 {$date} | 候選 {$candidates->count()} 檔 | 選入 {$selected} 檔",
            'system'
        );

        $this->info("短線 AI 選股完成：{$candidates->count()} 檔");
        return self::SUCCESS;
    }
}

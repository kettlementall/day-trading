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
        if (MarketHoliday::isHoliday($date)) {
            $previous = MarketHoliday::previousTradingDay($date);
            $this->warn("{$date} 休市，不產生短線候選。請改跑最近交易日 {$previous}。");
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

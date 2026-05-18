<?php

namespace App\Console\Commands;

use App\Models\MarketHoliday;
use App\Services\SwingScreenerService;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AiScreenSwingCandidates extends Command
{
    protected $signature = 'stock:ai-screen-swing {date?}';
    protected $description = 'AI 短線候選選股';

    public function handle(SwingScreenerService $service): int
    {
        $today = $this->argument('date') ?? now()->toDateString();
        $tomorrow = Carbon::parse($today)->addDay()->toDateString();

        $todayIsHoliday = MarketHoliday::isHoliday($today);
        $tomorrowIsHoliday = MarketHoliday::isHoliday($tomorrow);

        if ($todayIsHoliday && $tomorrowIsHoliday) {
            $this->warn("{$today} 與隔日 {$tomorrow} 皆休市，不產生短線候選。");
            return self::SUCCESS;
        }

        $screenDate = $today;
        $tradeDate = $todayIsHoliday ? MarketHoliday::previousTradingDay($today) : $today;

        $candidates = $service->screen($screenDate, $tradeDate);
        $selected = $candidates->where('ai_selected', true)->count();

        $tag = $todayIsHoliday ? "（{$today} 休市 → 覆蓋 {$tradeDate} 候選）" : '';
        app(TelegramService::class)->broadcast(
            "✅ *短線 AI 選股* 完成{$tag}\n📅 trade_date {$tradeDate} | 候選 {$candidates->count()} 檔 | 選入 {$selected} 檔",
            'system'
        );

        $this->info("短線 AI 選股完成（screen={$screenDate}, trade_date={$tradeDate}）：{$candidates->count()} 檔");
        return self::SUCCESS;
    }
}

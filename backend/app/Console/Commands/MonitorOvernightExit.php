<?php

namespace App\Console\Commands;

use App\Models\MarketHoliday;
use App\Services\OvernightExitMonitorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MonitorOvernightExit extends Command
{
    protected $signature   = 'stock:monitor-overnight-exit {--slot=930 : 時段代碼（930/1000/1030/1100）} {date?}';
    protected $description = '隔日沖出場監控（09:30/10:00/10:30/11:00）：檢查目標/停損觸發，並由 Haiku AI 滾動調整策略';

    public function handle(OvernightExitMonitorService $service): int
    {
        $slot      = $this->option('slot');
        $tradeDate = $this->argument('date') ?? now()->format('Y-m-d');

        if (MarketHoliday::isHoliday($tradeDate)) {
            $this->line("今日（{$tradeDate}）休市，跳過");
            return self::SUCCESS;
        }

        $this->info("隔日沖出場監控 [{$slot}]，出場日 {$tradeDate}");

        $summary = $service->checkTimeSlot($tradeDate, $slot);

        $this->line("檢查：{$summary['checked']} 檔");
        $this->line("  目標達成：{$summary['target_hit']}　停損觸發：{$summary['stop_hit']}");
        $this->line("  AI 調整：{$summary['adjusted']}　維持：{$summary['held']}　建議出場：{$summary['exited']}");

        Log::info("MonitorOvernightExit [{$slot}] {$tradeDate}：" . json_encode($summary));

        return self::SUCCESS;
    }
}

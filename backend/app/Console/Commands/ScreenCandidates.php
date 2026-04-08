<?php

namespace App\Console\Commands;

use App\Services\StockScreener;
use Illuminate\Console\Command;

class ScreenCandidates extends Command
{
    protected $signature = 'stock:screen-candidates {date?}';
    protected $description = '執行選股篩選，產出隔日候選標的清單';

    public function handle(StockScreener $screener): int
    {
        // 預設產出當日候選清單（08:00 開盤前執行）
        $date = $this->argument('date') ?? now()->format('Y-m-d');

        $this->info("執行選股篩選，交易日: {$date}");

        $candidates = $screener->screen($date);

        $this->info("篩選完成，共 {$candidates->count()} 檔候選標的：");

        foreach ($candidates as $c) {
            $stock = \App\Models\Stock::find($c['stock_id']);
            $this->line(sprintf(
                "  %s %s | 買入: %.2f | 目標: %.2f | 停損: %.2f | 風報比: %.2f | 分數: %d | %s",
                $stock->symbol,
                $stock->name,
                $c['suggested_buy'],
                $c['target_price'],
                $c['stop_loss'],
                $c['risk_reward_ratio'],
                $c['score'],
                implode(', ', $c['reasons'])
            ));
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\MorningScreener;
use Illuminate\Console\Command;

class ScreenMorningSignals extends Command
{
    protected $signature = 'stock:screen-morning {date?}';
    protected $description = '開盤30分鐘後執行盤前確認篩選（預估量、開盤位階、5分K、內外盤比）';

    public function handle(MorningScreener $screener): int
    {
        $date = $this->argument('date') ?? now()->format('Y-m-d');

        $this->info("執行盤前確認篩選，交易日: {$date}");

        $results = $screener->screen($date);

        if ($results->isEmpty()) {
            $this->warn('無候選標的或盤中資料不足');
            return self::SUCCESS;
        }

        $confirmed = $results->where('morning_confirmed', true);
        $this->info("篩選完成：{$results->count()} 檔候選 → {$confirmed->count()} 檔通過盤前確認");
        $this->newLine();

        foreach ($results as $r) {
            $status = $r['morning_confirmed'] ? '✓ 確認' : '✗ 未通過';
            $this->line(sprintf(
                "  %s %s %s | 盤前分數: %d",
                $status,
                $r['stock_symbol'],
                $r['stock_name'],
                $r['morning_score']
            ));

            foreach ($r['signals'] as $signal) {
                $icon = $signal['passed'] ? '  ✓' : '  ✗';
                $this->line("    {$icon} {$signal['rule']}: {$signal['detail']}");
            }
        }

        return self::SUCCESS;
    }
}

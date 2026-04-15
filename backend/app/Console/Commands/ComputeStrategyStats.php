<?php

namespace App\Console\Commands;

use App\Services\StrategyStatsService;
use Illuminate\Console\Command;

class ComputeStrategyStats extends Command
{
    protected $signature = 'stock:compute-strategy-stats';
    protected $description = '計算隔日沖/當沖策略量化績效統計（每週日 22:00 自動執行）';

    public function handle(StrategyStatsService $service): int
    {
        $this->info('開始計算策略績效統計...');

        $service->compute([30, 60]);

        $this->info('策略績效統計計算完成。');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\MarketHoliday;
use Illuminate\Console\Command;

class ImportMarketHolidays extends Command
{
    protected $signature = 'stock:import-holidays {year?}';
    protected $description = '匯入台股休市日（TWSE 公告）';

    /**
     * TWSE 公告休市日（不含週末）
     * 來源：https://www.twse.com.tw/zh/trading/holiday.html
     */
    private array $holidays = [
        2025 => [
            '01-01' => '元旦',
            '01-27' => '除夕前一日（調整放假）',
            '01-28' => '除夕',
            '01-29' => '春節',
            '01-30' => '春節',
            '01-31' => '春節',
            '02-28' => '和平紀念日',
            '04-03' => '兒童節（調整放假）',
            '04-04' => '清明節',
            '05-01' => '勞動節',
            '05-30' => '端午節（調整放假）',
            '06-02' => '端午節（補假）',
            '10-06' => '中秋節',
            '10-10' => '國慶日',
        ],
        2026 => [
            '01-01' => '元旦',
            '01-02' => '元旦（調整放假）',
            '02-16' => '除夕前一日（調整放假）',
            '02-17' => '除夕',
            '02-18' => '春節',
            '02-19' => '春節',
            '02-20' => '春節',
            '02-27' => '和平紀念日（調整放假）',
            '04-03' => '兒童節',
            '04-06' => '清明節（補假）',
            '05-01' => '勞動節',
            '06-19' => '端午節',
            '09-25' => '中秋節',
            '10-09' => '國慶日（調整放假）',
        ],
    ];

    public function handle(): int
    {
        $year = (int) ($this->argument('year') ?? now()->year);

        if (!isset($this->holidays[$year])) {
            $this->error("尚未建立 {$year} 年的休市日資料，請手動更新 ImportMarketHolidays.php");
            return self::FAILURE;
        }

        $count = 0;
        foreach ($this->holidays[$year] as $monthDay => $name) {
            $date = "{$year}-{$monthDay}";
            MarketHoliday::updateOrCreate(
                ['date' => $date],
                ['name' => $name]
            );
            $count++;
        }

        $this->info("已匯入 {$year} 年 {$count} 筆休市日");
        return self::SUCCESS;
    }
}

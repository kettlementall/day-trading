<?php

namespace App\Console\Commands;

use App\Models\MarginTrade;
use App\Models\MarketHoliday;
use App\Models\Stock;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchMarginTrades extends Command
{
    protected $signature = 'stock:fetch-margin {date?}';
    protected $description = '抓取融資融券資料';

    private const OPENDATA_URL = 'https://openapi.twse.com.tw/v1/exchangeReport/MI_MARGN';

    public function handle(): int
    {
        $date = $this->argument('date') ?? now()->format('Y-m-d');

        // 統一轉為 SQL 格式
        if (strlen($date) === 8 && ctype_digit($date)) {
            $date = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
        }

        $this->info("抓取融資融券: {$date}");

        if (MarketHoliday::isHoliday($date)) {
            $this->info("休市日，跳過");
            return self::SUCCESS;
        }

        try {
            $response = Http::timeout(30)->get(self::OPENDATA_URL);

            if (!$response->successful()) {
                $this->warn("HTTP {$response->status()}");
                return self::SUCCESS;
            }

            $rows = $response->json();
            if (!is_array($rows) || empty($rows)) {
                $this->warn("回傳空資料");
                return self::SUCCESS;
            }

            $count = 0;
            $parse = fn ($v) => (int) str_replace([',', ' '], '', $v ?: '0');

            foreach ($rows as $row) {
                $symbol = trim($row['股票代號'] ?? '');
                if (!preg_match('/^\d{4}$/', $symbol)) {
                    continue;
                }

                $stock = Stock::where('symbol', $symbol)->first();
                if (!$stock) continue;

                $marginBuy     = $parse($row['融資買進'] ?? '0');
                $marginSell    = $parse($row['融資賣出'] ?? '0');
                $marginBalance = $parse($row['融資今日餘額'] ?? '0');
                $marginPrev    = $parse($row['融資前日餘額'] ?? '0');
                $shortBuy      = $parse($row['融券買進'] ?? '0');
                $shortSell     = $parse($row['融券賣出'] ?? '0');
                $shortBalance  = $parse($row['融券今日餘額'] ?? '0');
                $shortPrev     = $parse($row['融券前日餘額'] ?? '0');

                MarginTrade::updateOrCreate(
                    ['stock_id' => $stock->id, 'date' => $date],
                    [
                        'margin_buy'     => $marginBuy,
                        'margin_sell'    => $marginSell,
                        'margin_balance' => $marginBalance,
                        'margin_change'  => $marginBalance - $marginPrev,
                        'short_buy'      => $shortBuy,
                        'short_sell'     => $shortSell,
                        'short_balance'  => $shortBalance,
                        'short_change'   => $shortBalance - $shortPrev,
                    ]
                );
                $count++;
            }

            app(TelegramService::class)->broadcast("✅ *融資融券抓取* 完成\n📅 {$date} | 共 {$count} 筆", 'system');
            $this->info("融資融券: 匯入 {$count} 筆");
        } catch (\Exception $e) {
            Log::error("Margin fetch error: " . $e->getMessage());
            $this->error("抓取失敗: " . $e->getMessage());
        }

        return self::SUCCESS;
    }
}

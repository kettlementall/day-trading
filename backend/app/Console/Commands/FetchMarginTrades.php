<?php

namespace App\Console\Commands;

use App\Models\MarginTrade;
use App\Models\Stock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchMarginTrades extends Command
{
    protected $signature = 'stock:fetch-margin {date?}';
    protected $description = '抓取融資融券資料';

    public function handle(): int
    {
        $date = $this->argument('date') ?? now()->format('Ymd');
        $this->info("抓取融資融券: {$date}");

        $url = "https://www.twse.com.tw/exchangeReport/MI_MARGN?response=json&date={$date}&selectType=STOCK";

        try {
            $response = Http::timeout(30)->get($url);
            $json = $response->json();

            if (($json['stat'] ?? '') !== 'OK') {
                $this->warn("回傳非 OK");
                return self::SUCCESS;
            }

            $rows = $json['data'] ?? [];
            $count = 0;
            $sqlDate = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);

            foreach ($rows as $row) {
                $symbol = trim($row[0]);

                if (!preg_match('/^\d{4}$/', $symbol)) {
                    continue;
                }

                $stock = Stock::where('symbol', $symbol)->first();
                if (!$stock) continue;

                $parse = fn ($v) => (int) str_replace([',', ' '], '', $v);

                MarginTrade::updateOrCreate(
                    ['stock_id' => $stock->id, 'date' => $sqlDate],
                    [
                        'margin_buy' => $parse($row[2]),
                        'margin_sell' => $parse($row[3]),
                        'margin_balance' => $parse($row[6]),
                        'margin_change' => $parse($row[2]) - $parse($row[3]),
                        'short_buy' => $parse($row[8] ?? '0'),
                        'short_sell' => $parse($row[7] ?? '0'),
                        'short_balance' => $parse($row[11] ?? '0'),
                        'short_change' => $parse($row[7] ?? '0') - $parse($row[8] ?? '0'),
                    ]
                );
                $count++;
            }

            $this->info("融資融券: 匯入 {$count} 筆");
        } catch (\Exception $e) {
            Log::error("Margin fetch error: " . $e->getMessage());
            $this->error("抓取失敗: " . $e->getMessage());
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\InstitutionalTrade;
use App\Models\Stock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchInstitutionalTrades extends Command
{
    protected $signature = 'stock:fetch-institutional {date?}';
    protected $description = '抓取三大法人買賣超';

    public function handle(): int
    {
        $date = $this->argument('date') ?? now()->format('Ymd');
        $this->info("抓取三大法人: {$date}");

        $this->fetchTwse($date);

        $this->info('三大法人抓取完成');
        return self::SUCCESS;
    }

    private function fetchTwse(string $date): void
    {
        $url = "https://www.twse.com.tw/fund/T86?response=json&date={$date}&selectType=ALLBUT0999";

        try {
            $response = Http::timeout(30)->get($url);
            $json = $response->json();

            if (($json['stat'] ?? '') !== 'OK') {
                $this->warn("回傳非 OK");
                return;
            }

            // 驗證回傳日期是否與請求日期一致
            $actualDate = $json['date'] ?? $date;
            $sqlDate = substr($actualDate, 0, 4) . '-' . substr($actualDate, 4, 2) . '-' . substr($actualDate, 6, 2);
            $requestedSqlDate = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);

            if ($sqlDate !== $requestedSqlDate) {
                $this->warn("回傳日期 {$sqlDate} 與請求日期 {$requestedSqlDate} 不符（非交易日），跳過");
                return;
            }

            $rows = $json['data'] ?? [];
            $count = 0;

            foreach ($rows as $row) {
                $symbol = trim($row[0]);

                if (!preg_match('/^\d{4}$/', $symbol)) {
                    continue;
                }

                $stock = Stock::where('symbol', $symbol)->first();
                if (!$stock) continue;

                $parse = fn ($v) => (int) str_replace([',', ' '], '', $v);

                InstitutionalTrade::updateOrCreate(
                    ['stock_id' => $stock->id, 'date' => $sqlDate],
                    [
                        'foreign_buy' => $parse($row[2]),
                        'foreign_sell' => $parse($row[3]),
                        'foreign_net' => $parse($row[4]),
                        'trust_buy' => $parse($row[5]),
                        'trust_sell' => $parse($row[6]),
                        'trust_net' => $parse($row[7]),
                        'dealer_buy' => $parse($row[8] ?? '0') + $parse($row[11] ?? '0'),
                        'dealer_sell' => $parse($row[9] ?? '0') + $parse($row[12] ?? '0'),
                        'dealer_net' => $parse($row[10] ?? '0') + $parse($row[13] ?? '0'),
                        'total_net' => $parse($row[4]) + $parse($row[7]) + $parse($row[10] ?? '0') + $parse($row[13] ?? '0'),
                    ]
                );
                $count++;
            }

            $this->info("三大法人: 匯入 {$count} 筆");
        } catch (\Exception $e) {
            Log::error("Institutional fetch error: " . $e->getMessage());
            $this->error("抓取失敗: " . $e->getMessage());
        }
    }
}

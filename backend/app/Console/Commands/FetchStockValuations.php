<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Models\StockValuation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 從 TWSE open data 抓取上市股票本益比/殖利率/股價淨值比
 * API: https://opendata.twse.com.tw/v1/exchangeReport/BWIBBU_ALL
 * 每個交易日收盤後更新（約 17:00）
 */
class FetchStockValuations extends Command
{
    protected $signature   = 'stock:fetch-valuations {date?}';
    protected $description = '從 TWSE 抓取本益比/殖利率/股價淨值比（每日收盤後）';

    private const TWSE_URL = 'https://opendata.twse.com.tw/v1/exchangeReport/BWIBBU_ALL';

    public function handle(): int
    {
        $date = $this->argument('date') ?? now()->format('Y-m-d');

        $this->info("抓取 TWSE 估值資料（{$date}）...");

        try {
            $response = Http::timeout(30)->get(self::TWSE_URL);

            if (!$response->successful()) {
                $this->error("TWSE API 錯誤 HTTP {$response->status()}");
                return self::FAILURE;
            }

            $rows = $response->json();
        } catch (\Exception $e) {
            $this->error('TWSE 連線失敗：' . $e->getMessage());
            Log::error('FetchStockValuations: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (empty($rows)) {
            $this->warn('TWSE 回傳空資料，可能非交易日');
            return self::SUCCESS;
        }

        // TWSE 欄位：Code, Name, PEratio, DividendYield, PBratio
        $symbols = Stock::pluck('id', 'symbol'); // symbol => id
        $upserted = 0;

        foreach ($rows as $row) {
            $symbol = trim($row['Code'] ?? $row['股票代號'] ?? '');
            if (!isset($symbols[$symbol])) {
                continue;
            }

            $pe    = $this->parseNumber($row['PEratio']      ?? $row['本益比'] ?? null);
            $yield = $this->parseNumber($row['DividendYield'] ?? $row['殖利率(%)'] ?? null);
            $pb    = $this->parseNumber($row['PBratio']       ?? $row['股價淨值比'] ?? null);

            if ($pe === null && $yield === null && $pb === null) {
                continue;
            }

            StockValuation::updateOrCreate(
                ['stock_id' => $symbols[$symbol], 'date' => $date],
                [
                    'pe_ratio'       => $pe,
                    'pb_ratio'       => $pb,
                    'dividend_yield' => $yield,
                ]
            );

            $upserted++;
        }

        $this->info("完成：更新 {$upserted} 檔估值資料");
        Log::info("FetchStockValuations {$date}：更新 {$upserted} 檔");

        return self::SUCCESS;
    }

    private function parseNumber(mixed $val): ?float
    {
        if ($val === null || $val === '' || $val === '-' || $val === 'N/A') {
            return null;
        }
        $num = (float) str_replace(',', '', (string) $val);
        return $num > 0 ? $num : null;
    }
}

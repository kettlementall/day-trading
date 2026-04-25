<?php

namespace App\Console\Commands;

use App\Models\DailyQuote;
use App\Models\Stock;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchDailyQuotes extends Command
{
    protected $signature = 'stock:fetch-daily {date?}';
    protected $description = '抓取台股每日收盤行情（TWSE + TPEX）';

    private int $twseCount = 0;
    private int $tpexCount = 0;

    public function handle(): int
    {
        $date = $this->argument('date') ?? now()->format('Ymd');
        $rocDate = (intval(substr($date, 0, 4)) - 1911) . '/' . substr($date, 4, 2) . '/' . substr($date, 6, 2);
        $sqlDate = $this->toSqlDate($date);

        $this->info("抓取日期: {$date}");

        $this->fetchTwse($date);
        $this->fetchTpex($rocDate);

        $total = $this->twseCount + $this->tpexCount;
        app(TelegramService::class)->broadcast(
            "✅ *每日行情抓取* 完成\n📅 {$sqlDate} | 上市 {$this->twseCount} 筆 + 上櫃 {$this->tpexCount} 筆 = 共 {$total} 筆",
            'system'
        );

        $this->info('每日行情抓取完成');
        return self::SUCCESS;
    }

    private function fetchTwse(string $date): void
    {
        $url = "https://www.twse.com.tw/exchangeReport/MI_INDEX?response=json&date={$date}&type=ALLBUT0999";

        try {
            $response = Http::timeout(30)
                ->withHeaders(['Accept-Language' => 'zh-TW'])
                ->get($url);

            $json = $response->json();

            if (($json['stat'] ?? '') !== 'OK') {
                $this->warn("TWSE 回傳非 OK: " . ($json['stat'] ?? 'unknown'));
                return;
            }

            // 從回傳的 date 欄位取得實際交易日，避免假日存錯日期
            $actualDate = $json['date'] ?? $date;
            $actualSqlDate = $this->toSqlDate($actualDate);
            $requestedSqlDate = $this->toSqlDate($date);

            if ($actualSqlDate !== $requestedSqlDate) {
                $this->warn("TWSE 回傳日期 {$actualSqlDate} 與請求日期 {$requestedSqlDate} 不符（非交易日），跳過");
                return;
            }

            // 支援新版 tables 結構與舊版 data9/data8
            $rows = $json['data9'] ?? $json['data8'] ?? [];
            if (empty($rows) && !empty($json['tables'])) {
                foreach ($json['tables'] as $table) {
                    $title = $table['title'] ?? '';
                    if (str_contains($title, '每日收盤行情')) {
                        $rows = $table['data'] ?? [];
                        break;
                    }
                }
            }
            $count = 0;

            foreach ($rows as $row) {
                $symbol = trim($row[0]);
                $name = trim($row[1]);

                // 跳過非一般股票
                if (!preg_match('/^\d{4}$/', $symbol)) {
                    continue;
                }

                $stock = Stock::firstOrCreate(
                    ['symbol' => $symbol],
                    ['name' => $name, 'market' => 'twse', 'is_day_trading' => true]
                );

                $close = $this->parseNumber($row[8]);
                $open = $this->parseNumber($row[5]);
                $high = $this->parseNumber($row[6]);
                $low = $this->parseNumber($row[7]);
                $volume = $this->parseNumber($row[2]);

                if ($close <= 0) continue;

                // 支援新格式：row[9]=漲跌方向(HTML), row[10]=漲跌價差
                // 舊格式：row[9]=含正負號的漲跌值
                $changeCol = $row[10] ?? $row[9] ?? '0';
                $directionCol = $row[9] ?? '';
                $change = $this->parseNumber($changeCol);
                if (str_contains($directionCol, 'green') || str_contains($directionCol, '-')) {
                    $change = -abs($change);
                }
                $prevClose = $close - $change;
                $changePercent = $prevClose > 0 ? round($change / $prevClose * 100, 2) : 0;
                $amplitude = $prevClose > 0 ? round(($high - $low) / $prevClose * 100, 2) : 0;

                DailyQuote::updateOrCreate(
                    ['stock_id' => $stock->id, 'date' => $actualSqlDate],
                    [
                        'open' => $open,
                        'high' => $high,
                        'low' => $low,
                        'close' => $close,
                        'volume' => $volume,
                        'trade_value' => $this->parseNumber($row[4] ?? '0'),
                        'trade_count' => (int) str_replace(',', '', $row[3] ?? '0'),
                        'change' => $change,
                        'change_percent' => $changePercent,
                        'amplitude' => $amplitude,
                    ]
                );
                $count++;
            }

            $this->twseCount = $count;
            $this->info("TWSE: 匯入 {$count} 筆 (交易日: {$actualSqlDate})");
        } catch (\Exception $e) {
            Log::error("TWSE fetch error: " . $e->getMessage());
            $this->error("TWSE 抓取失敗: " . $e->getMessage());
        }
    }

    private function fetchTpex(string $rocDate): void
    {
        $url = "https://www.tpex.org.tw/web/stock/aftertrading/otc_quotes_no1430/stk_wn1430_result.php?l=zh-tw&d={$rocDate}&se=EW";

        try {
            $response = Http::timeout(30)->get($url);
            $json = $response->json();

            // 從回傳的 reportDate 取得實際交易日
            $actualRocDate = $json['reportDate'] ?? $rocDate;
            $actualSqlDate = $this->rocToSqlDate($actualRocDate);
            $requestedSqlDate = $this->rocToSqlDate($rocDate);

            if ($actualSqlDate !== $requestedSqlDate) {
                $this->warn("TPEX 回傳日期 {$actualSqlDate} 與請求日期 {$requestedSqlDate} 不符（非交易日），跳過");
                return;
            }

            $rows = $json['aaData'] ?? [];
            if (empty($rows)) {
                $this->warn("TPEX: 無資料");
                return;
            }
            $count = 0;

            foreach ($rows as $row) {
                $symbol = trim($row[0]);
                $name = trim($row[1]);

                if (!preg_match('/^\d{4}$/', $symbol)) {
                    continue;
                }

                $stock = Stock::firstOrCreate(
                    ['symbol' => $symbol],
                    ['name' => $name, 'market' => 'tpex', 'is_day_trading' => true]
                );

                $close = $this->parseNumber($row[2]);
                $change = $this->parseNumber($row[3]);
                $open = $this->parseNumber($row[4]);
                $high = $this->parseNumber($row[5]);
                $low = $this->parseNumber($row[6]);
                $volume = $this->parseNumber($row[7]) * 1000; // 上櫃單位千股

                if ($close <= 0) continue;

                $prevClose = $close - $change;
                $changePercent = $prevClose > 0 ? round($change / $prevClose * 100, 2) : 0;
                $amplitude = $prevClose > 0 ? round(($high - $low) / $prevClose * 100, 2) : 0;

                DailyQuote::updateOrCreate(
                    ['stock_id' => $stock->id, 'date' => $actualSqlDate],
                    [
                        'open' => $open,
                        'high' => $high,
                        'low' => $low,
                        'close' => $close,
                        'volume' => $volume,
                        'trade_value' => $this->parseNumber($row[8] ?? '0') * 1000,
                        'trade_count' => (int) str_replace(',', '', $row[9] ?? '0'),
                        'change' => $change,
                        'change_percent' => $changePercent,
                        'amplitude' => $amplitude,
                    ]
                );
                $count++;
            }

            $this->tpexCount = $count;
            $this->info("TPEX: 匯入 {$count} 筆 (交易日: {$actualSqlDate})");
        } catch (\Exception $e) {
            Log::error("TPEX fetch error: " . $e->getMessage());
            $this->error("TPEX 抓取失敗: " . $e->getMessage());
        }
    }

    private function parseNumber(string $value): float
    {
        return (float) str_replace([',', ' ', 'X'], '', $value);
    }

    private function toSqlDate(string $date): string
    {
        return substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
    }

    private function rocToSqlDate(string $rocDate): string
    {
        $parts = explode('/', $rocDate);
        $year = (int) $parts[0] + 1911;
        return sprintf('%04d-%02d-%02d', $year, $parts[1], $parts[2]);
    }
}

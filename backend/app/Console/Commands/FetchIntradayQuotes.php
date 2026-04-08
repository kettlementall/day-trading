<?php

namespace App\Console\Commands;

use App\Models\Candidate;
use App\Models\DailyQuote;
use App\Models\IntradayQuote;
use App\Models\Stock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchIntradayQuotes extends Command
{
    protected $signature = 'stock:fetch-intraday {date?}';
    protected $description = '抓取盤中即時行情（針對當日候選標的）';

    public function handle(): int
    {
        $date = $this->argument('date') ?? now()->format('Y-m-d');

        // 取得當日候選標的
        $candidates = Candidate::with('stock')
            ->where('trade_date', $date)
            ->get();

        if ($candidates->isEmpty()) {
            $this->warn("無 {$date} 的候選標的，跳過盤中抓取");
            return self::SUCCESS;
        }

        $this->info("抓取 {$candidates->count()} 檔候選標的的盤中行情...");

        // 依市場分組
        $twseStocks = [];
        $tpexStocks = [];

        foreach ($candidates as $candidate) {
            $stock = $candidate->stock;
            if ($stock->market === 'twse') {
                $twseStocks[] = $stock;
            } else {
                $tpexStocks[] = $stock;
            }
        }

        $this->fetchTwseRealtime($twseStocks, $date);
        $this->fetchTpexRealtime($tpexStocks, $date);

        $this->info('盤中行情抓取完成');
        return self::SUCCESS;
    }

    /**
     * 透過 TWSE mis API 抓取上市股即時行情
     */
    private function fetchTwseRealtime(array $stocks, string $date): void
    {
        if (empty($stocks)) return;

        // mis API 一次最多查詢約 20 檔，分批處理
        $chunks = array_chunk($stocks, 20);

        foreach ($chunks as $chunk) {
            $exCh = implode('|', array_map(
                fn (Stock $s) => "tse_{$s->symbol}.tw",
                $chunk
            ));

            try {
                $response = Http::timeout(15)
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'Referer' => 'https://mis.twse.com.tw/',
                    ])
                    ->get("https://mis.twse.com.tw/stock/api/getStockInfo.jsp", [
                        'ex_ch' => $exCh,
                    ]);

                $json = $response->json();
                $items = $json['msgArray'] ?? [];

                foreach ($items as $item) {
                    $this->processRealtimeItem($item, $date);
                }

                $this->info("TWSE 即時: 處理 " . count($items) . " 筆");
            } catch (\Exception $e) {
                Log::error("TWSE realtime fetch error: " . $e->getMessage());
                $this->error("TWSE 即時抓取失敗: " . $e->getMessage());
            }

            // 避免請求過快
            usleep(500000);
        }
    }

    /**
     * 透過 TPEX mis API 抓取上櫃股即時行情
     */
    private function fetchTpexRealtime(array $stocks, string $date): void
    {
        if (empty($stocks)) return;

        $chunks = array_chunk($stocks, 20);

        foreach ($chunks as $chunk) {
            $exCh = implode('|', array_map(
                fn (Stock $s) => "otc_{$s->symbol}.tw",
                $chunk
            ));

            try {
                $response = Http::timeout(15)
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'Referer' => 'https://mis.twse.com.tw/',
                    ])
                    ->get("https://mis.twse.com.tw/stock/api/getStockInfo.jsp", [
                        'ex_ch' => $exCh,
                    ]);

                $json = $response->json();
                $items = $json['msgArray'] ?? [];

                foreach ($items as $item) {
                    $this->processRealtimeItem($item, $date);
                }

                $this->info("TPEX 即時: 處理 " . count($items) . " 筆");
            } catch (\Exception $e) {
                Log::error("TPEX realtime fetch error: " . $e->getMessage());
                $this->error("TPEX 即時抓取失敗: " . $e->getMessage());
            }

            usleep(500000);
        }
    }

    /**
     * 處理單一即時行情項目
     *
     * mis API 回傳欄位：
     *   c = 股票代號, n = 名稱
     *   o = 開盤價, h = 最高, l = 最低, z = 最新成交價
     *   v = 累積成交量（張）, y = 昨收
     *   a = 最佳五檔賣價, b = 最佳五檔買價
     *   tlong = 時間戳記（ms）
     */
    private function processRealtimeItem(array $item, string $date): void
    {
        $symbol = $item['c'] ?? '';
        if (empty($symbol) || $symbol === '-') return;

        $stock = Stock::where('symbol', $symbol)->first();
        if (!$stock) return;

        $open = $this->parsePrice($item['o'] ?? '');
        $high = $this->parsePrice($item['h'] ?? '');
        $low = $this->parsePrice($item['l'] ?? '');
        $current = $this->parsePrice($item['z'] ?? '');
        $prevClose = $this->parsePrice($item['y'] ?? '');
        $accVolume = (int) ($item['v'] ?? 0) * 1000; // API 回傳張數，轉為股數

        if ($open <= 0 || $prevClose <= 0) return;

        // 查昨日成交量
        $yesterdayQuote = DailyQuote::where('stock_id', $stock->id)
            ->where('date', '<', $date)
            ->orderByDesc('date')
            ->first();
        $yesterdayVolume = $yesterdayQuote?->volume ?? 0;

        // 預估全日成交量：根據已過時間比例推算
        $now = now();
        $marketOpen = now()->copy()->setTime(9, 0, 0);
        $marketClose = now()->copy()->setTime(13, 30, 0);
        $totalMinutes = $marketOpen->diffInMinutes($marketClose); // 270 分鐘
        $elapsedMinutes = max(1, $marketOpen->diffInMinutes($now));

        $estimatedDailyVolume = ($accVolume / $elapsedMinutes) * $totalMinutes;
        $estimatedRatio = $yesterdayVolume > 0
            ? round($estimatedDailyVolume / $yesterdayVolume, 2)
            : 0;

        // 開盤漲幅
        $openChangePercent = round(($open - $prevClose) / $prevClose * 100, 2);

        // 第一根5分K（09:00~09:05）的高低點
        // 在 09:05 之前，用已知的 high/low 作為近似值
        // 09:05 之後，若已有紀錄則保留不覆蓋
        $existing = IntradayQuote::where('stock_id', $stock->id)->where('date', $date)->first();
        $first5minHigh = $existing?->first_5min_high;
        $first5minLow = $existing?->first_5min_low;

        if (!$first5minHigh || $now->format('H:i') <= '09:05') {
            $first5minHigh = $high;
            $first5minLow = $low;
        }

        // 內外盤量推算
        // mis API 不直接提供內外盤，用 tick 方向推估：
        // 成交價 >= 賣價 → 外盤（買方主動追價）
        // 成交價 <= 買價 → 內盤
        // 這裡用累積資料的近似比例
        $bestAsk = $this->parsePrice(explode('_', $item['a'] ?? '')[0] ?? '');
        $bestBid = $this->parsePrice(explode('_', $item['b'] ?? '')[0] ?? '');

        $buyVolume = $existing?->buy_volume ?? 0;
        $sellVolume = $existing?->sell_volume ?? 0;

        if ($current > 0 && $bestAsk > 0 && $bestBid > 0) {
            // 根據現價位置推估本次成交方向
            $midPrice = ($bestAsk + $bestBid) / 2;
            $prevAccVolume = ($existing?->accumulated_volume ?? 0);
            $deltaVolume = max(0, $accVolume - $prevAccVolume);

            if ($current >= $midPrice) {
                $buyVolume += $deltaVolume;
            } else {
                $sellVolume += $deltaVolume;
            }
        }

        $totalBuySell = $buyVolume + $sellVolume;
        $externalRatio = $totalBuySell > 0
            ? round($buyVolume / $totalBuySell * 100, 2)
            : 50;

        IntradayQuote::updateOrCreate(
            ['stock_id' => $stock->id, 'date' => $date],
            [
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'current_price' => $current ?: $open,
                'prev_close' => $prevClose,
                'accumulated_volume' => $accVolume,
                'yesterday_volume' => $yesterdayVolume,
                'estimated_volume_ratio' => $estimatedRatio,
                'open_change_percent' => $openChangePercent,
                'first_5min_high' => $first5minHigh,
                'first_5min_low' => $first5minLow,
                'buy_volume' => $buyVolume,
                'sell_volume' => $sellVolume,
                'external_ratio' => $externalRatio,
                'snapshot_at' => now(),
            ]
        );
    }

    private function parsePrice(string $value): float
    {
        $value = trim(str_replace([',', ' '], '', $value));
        return is_numeric($value) ? (float) $value : 0;
    }
}

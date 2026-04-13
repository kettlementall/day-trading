<?php

namespace App\Services;

use App\Models\Stock;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TwseRealtimeClient
{
    private const API_URL = 'https://mis.twse.com.tw/stock/api/getStockInfo.jsp';
    private const BATCH_SIZE = 20;
    private const BATCH_DELAY_US = 500000; // 500ms

    /**
     * 批次抓取即時報價
     *
     * @param  Stock[]  $stocks
     * @return array<string, array>  keyed by symbol
     */
    public function fetchQuotes(array $stocks): array
    {
        if (empty($stocks)) {
            return [];
        }

        // 依市場分組
        $twseStocks = [];
        $tpexStocks = [];

        foreach ($stocks as $stock) {
            if ($stock->market === 'twse') {
                $twseStocks[] = $stock;
            } else {
                $tpexStocks[] = $stock;
            }
        }

        $results = [];

        foreach ($this->fetchByMarket($twseStocks, 'tse') as $symbol => $data) {
            $results[$symbol] = $data;
        }

        foreach ($this->fetchByMarket($tpexStocks, 'otc') as $symbol => $data) {
            $results[$symbol] = $data;
        }

        return $results;
    }

    /**
     * 依市場別分批呼叫 MIS API
     *
     * @param  Stock[]  $stocks
     * @return array<string, array>
     */
    private function fetchByMarket(array $stocks, string $prefix): array
    {
        if (empty($stocks)) {
            return [];
        }

        $results = [];
        $chunks = array_chunk($stocks, self::BATCH_SIZE);

        foreach ($chunks as $index => $chunk) {
            $exCh = implode('|', array_map(
                fn(Stock $s) => "{$prefix}_{$s->symbol}.tw",
                $chunk
            ));

            try {
                $response = Http::timeout(15)
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'Referer' => 'https://mis.twse.com.tw/',
                    ])
                    ->get(self::API_URL, ['ex_ch' => $exCh]);

                $items = $response->json('msgArray', []);

                foreach ($items as $item) {
                    $parsed = $this->parseRealtimeItem($item);
                    if ($parsed) {
                        $results[$parsed['symbol']] = $parsed;
                    }
                }
            } catch (\Exception $e) {
                Log::error("MIS API ({$prefix}) fetch error: " . $e->getMessage());
            }

            // 避免請求過快（非最後一批才 sleep）
            if ($index < count($chunks) - 1) {
                usleep(self::BATCH_DELAY_US);
            }
        }

        return $results;
    }

    /**
     * 解析單一即時報價項目
     *
     * MIS API 欄位：
     *   c = 股票代號, n = 名稱
     *   o = 開盤價, h = 最高, l = 最低, z = 最新成交價
     *   v = 累積成交量（張）, y = 昨收
     *   a = 最佳五檔賣價（_分隔）, b = 最佳五檔買價（_分隔）
     *   tlong = 時間戳記（ms）
     */
    public function parseRealtimeItem(array $item): ?array
    {
        $symbol = $item['c'] ?? '';
        if (empty($symbol) || $symbol === '-') {
            return null;
        }

        $open = self::parsePrice($item['o'] ?? '');
        $high = self::parsePrice($item['h'] ?? '');
        $low = self::parsePrice($item['l'] ?? '');
        $current = self::parsePrice($item['z'] ?? '');
        $prevClose = self::parsePrice($item['y'] ?? '');
        $accVolume = (int) ($item['v'] ?? 0) * 1000; // 張數 → 股數

        if ($open <= 0 || $prevClose <= 0) {
            return null;
        }

        $bestAsk = self::parsePrice(explode('_', $item['a'] ?? '')[0] ?? '');
        $bestBid = self::parsePrice(explode('_', $item['b'] ?? '')[0] ?? '');

        // 漲停/跌停偵測
        // 情境 1: z="-" + 單邊有掛單（bid>0 ask=0 或反之）
        // 情境 2: z 有值但過時 + 雙邊都沒掛單（ask=0 bid=0）+ high 接近漲停價
        $limitUp = false;
        $limitDown = false;

        if ($bestAsk <= 0 && $bestBid > 0 && $current <= 0) {
            // 情境 1: z 無值，買方排隊
            $limitUp = true;
            $current = $bestBid;
        } elseif ($bestBid <= 0 && $bestAsk > 0 && $current <= 0) {
            // 情境 1: z 無值，賣方排隊
            $limitDown = true;
            $current = $bestAsk;
        } elseif ($bestAsk <= 0 && $bestBid <= 0 && $prevClose > 0 && $high > 0) {
            // 情境 2: 雙邊都沒掛單，用 high/low 判斷是否鎖漲停/跌停
            $highPct = ($high - $prevClose) / $prevClose;
            $lowPct = ($prevClose - ($low > 0 ? $low : $prevClose)) / $prevClose;

            if ($highPct >= 0.09) {
                $limitUp = true;
                $current = $high;   // 漲停價 = 今日最高
            } elseif ($lowPct >= 0.09) {
                $limitDown = true;
                $current = $low;    // 跌停價 = 今日最低
            }
        }

        if ($limitUp) {
            $high = max($high, $current);
        } elseif ($limitDown && $low > 0) {
            $low = min($low, $current);
        }

        return [
            'symbol' => $symbol,
            'name' => $item['n'] ?? '',
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'current_price' => $current ?: $open,
            'prev_close' => $prevClose,
            'accumulated_volume' => $accVolume,
            'best_ask' => $bestAsk,
            'best_bid' => $bestBid,
            'limit_up' => $limitUp,
            'limit_down' => $limitDown,
            'timestamp_ms' => (int) ($item['tlong'] ?? 0),
        ];
    }

    /**
     * 解析價格字串
     */
    public static function parsePrice(string $value): float
    {
        $value = trim(str_replace([',', ' '], '', $value));

        return is_numeric($value) ? (float) $value : 0;
    }
}

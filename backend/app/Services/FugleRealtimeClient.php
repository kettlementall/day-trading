<?php

namespace App\Services;

use App\Models\Stock;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FugleRealtimeClient
{
    private const API_BASE       = 'https://api.fugle.tw/marketdata/v1.0';
    private const QUOTE_PATH     = '/stock/intraday/quote';
    private const CANDLES_PATH   = '/stock/intraday/candles';
    private const REQUEST_DELAY_US = 150000;  // 150ms，避免超過 rate limit
    private const MAX_429_RETRIES  = 3;       // 429 backoff 最大重試次數
    private const BACKOFF_BASE_US  = 500000;  // 500ms，每次 ×2（500ms → 1s → 2s）

    /** @var string[] 可用的 API key 清單 */
    private array $apiKeys = [];

    /** @var int 目前使用的 key index */
    private int $currentKeyIndex = 0;

    public function __construct()
    {
        $primary = config('services.fugle.api_key', '');
        $backup  = config('services.fugle.api_key_backup', '');

        if ($primary) $this->apiKeys[] = $primary;
        if ($backup)  $this->apiKeys[] = $backup;
    }

    private function currentKey(): string
    {
        return $this->apiKeys[$this->currentKeyIndex] ?? '';
    }

    private function rotateKey(): bool
    {
        $nextIndex = $this->currentKeyIndex + 1;
        if ($nextIndex < count($this->apiKeys)) {
            $this->currentKeyIndex = $nextIndex;
            Log::info("Fugle API key rotated to key #" . ($nextIndex + 1));
            return true;
        }
        return false;
    }

    /**
     * 批次抓取即時報價（每檔獨立呼叫 Fugle API）
     *
     * @param  Stock[]  $stocks
     * @return array<string, array>  keyed by symbol
     */
    public function fetchQuotes(array $stocks): array
    {
        if (empty($stocks) || empty($this->apiKeys)) {
            return [];
        }

        $results = [];
        $total = count($stocks);

        foreach ($stocks as $index => $stock) {
            try {
                $parsed = $this->fetchSingleQuote($stock->symbol);
                if ($parsed) {
                    $results[$parsed['symbol']] = $parsed;
                }
            } catch (\Exception $e) {
                Log::error("Fugle [{$stock->symbol}]: " . $e->getMessage());
            }

            if ($index < $total - 1) {
                usleep(self::REQUEST_DELAY_US);
            }
        }

        return $results;
    }

    /**
     * 帶 429 backoff retry 的 HTTP GET（所有 Fugle 請求的統一入口）
     *
     * 策略：429 → 先嘗試 rotate key 重試 → 仍 429 則 exponential backoff（最多 MAX_429_RETRIES 次）
     */
    private function requestWithRetry(string $url, array $query = [], string $label = ''): ?\Illuminate\Http\Client\Response
    {
        if (empty($this->apiKeys)) {
            return null;
        }

        $response = Http::timeout(10)
            ->withHeaders(['X-API-KEY' => $this->currentKey()])
            ->get($url, $query);

        if ($response->status() !== 429) {
            return $response;
        }

        // 第一次 429：嘗試換 key
        if ($this->rotateKey()) {
            usleep(self::REQUEST_DELAY_US);
            $response = Http::timeout(10)
                ->withHeaders(['X-API-KEY' => $this->currentKey()])
                ->get($url, $query);

            if ($response->status() !== 429) {
                return $response;
            }
        }

        // 兩把 key 都 429：exponential backoff
        for ($i = 0; $i < self::MAX_429_RETRIES; $i++) {
            $sleepUs = self::BACKOFF_BASE_US * (2 ** $i); // 500ms, 1s, 2s
            Log::info("Fugle [{$label}] 429 backoff retry #{$i}, sleep " . ($sleepUs / 1_000_000) . 's');
            usleep($sleepUs);

            // 交替嘗試兩把 key
            $this->currentKeyIndex = $i % count($this->apiKeys);

            $response = Http::timeout(10)
                ->withHeaders(['X-API-KEY' => $this->currentKey()])
                ->get($url, $query);

            if ($response->status() !== 429) {
                return $response;
            }
        }

        return $response; // 最後一次的 response（仍是 429）
    }

    /**
     * 單檔報價抓取（parsed）
     */
    private function fetchSingleQuote(string $symbol): ?array
    {
        $response = $this->requestWithRetry(
            self::API_BASE . self::QUOTE_PATH . '/' . $symbol,
            label: $symbol,
        );

        if (!$response || !$response->successful()) {
            Log::warning("Fugle [{$symbol}] HTTP " . ($response?->status() ?? 'null') . ': ' . ($response?->body() ?? ''));
            return null;
        }

        return $this->parseQuote($response->json());
    }

    /**
     * 單檔報價抓取（原始 Fugle JSON，含 bids/asks/transaction 等完整欄位）
     */
    public function fetchRawQuote(string $symbol): ?array
    {
        $response = $this->requestWithRetry(
            self::API_BASE . self::QUOTE_PATH . '/' . $symbol,
            label: $symbol,
        );

        if (!$response || !$response->successful()) {
            Log::warning("Fugle [{$symbol}] HTTP " . ($response?->status() ?? 'null') . ': ' . ($response?->body() ?? ''));
            return null;
        }

        return $response->json();
    }

    /**
     * 抓取單檔 5 分 K 線（parsed）
     *
     * @return array[] 每筆含 time, open, high, low, close, volume
     */
    public function fetchCandles(string $symbol, int $timeframe = 5): array
    {
        try {
            $response = $this->requestWithRetry(
                self::API_BASE . self::CANDLES_PATH . '/' . $symbol,
                ['timeframe' => $timeframe],
                label: "{$symbol}/candles",
            );

            if (!$response || !$response->successful()) {
                Log::warning("Fugle candles [{$symbol}] HTTP " . ($response?->status() ?? 'null'));
                return [];
            }

            $data = $response->json()['data'] ?? [];

            return array_map(fn($c) => [
                'time'   => substr($c['date'] ?? '', 11, 5),
                'open'   => (float) ($c['open'] ?? 0),
                'high'   => (float) ($c['high'] ?? 0),
                'low'    => (float) ($c['low'] ?? 0),
                'close'  => (float) ($c['close'] ?? 0),
                'volume' => (int) ($c['volume'] ?? 0),
            ], $data);
        } catch (\Exception $e) {
            Log::error("Fugle candles [{$symbol}]: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 抓取歷史日 K 線（Fugle Historical Candles API）
     *
     * @return array [['date'=>'2026-04-24','open'=>...,'high'=>...,'low'=>...,'close'=>...,'volume'=>...], ...]
     */
    public function fetchDailyCandles(string $symbol, int $days = 5): array
    {
        try {
            $from = now()->subDays($days * 2)->format('Y-m-d'); // 多抓以應對假日
            $response = $this->requestWithRetry(
                self::API_BASE . '/stock/historical/candles/' . $symbol,
                ['from' => $from, 'fields' => 'open,high,low,close,volume,change'],
                label: "{$symbol}/historical",
            );

            if (!$response || !$response->successful()) {
                Log::warning("Fugle historical [{$symbol}] HTTP " . ($response?->status() ?? 'null'));
                return [];
            }

            $data = $response->json()['data'] ?? [];

            // API 回傳由新到舊，取最近 N 筆
            return array_slice(array_map(fn($c) => [
                'date'           => $c['date'] ?? '',
                'open'           => (float) ($c['open'] ?? 0),
                'high'           => (float) ($c['high'] ?? 0),
                'low'            => (float) ($c['low'] ?? 0),
                'close'          => (float) ($c['close'] ?? 0),
                'volume'         => (int) ($c['volume'] ?? 0),
                'change_percent' => isset($c['change']) && isset($c['close']) && $c['close'] > 0
                    ? round(($c['change']) / ($c['close'] - $c['change']) * 100, 2)
                    : 0,
            ], $data), 0, $days);
        } catch (\Exception $e) {
            Log::error("Fugle historical [{$symbol}]: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 抓取單檔 5 分 K 線（原始 Fugle JSON）
     */
    public function fetchRawCandles(string $symbol, int $timeframe = 5): ?array
    {
        try {
            $response = $this->requestWithRetry(
                self::API_BASE . self::CANDLES_PATH . '/' . $symbol,
                ['timeframe' => $timeframe],
                label: "{$symbol}/candles",
            );

            if (!$response || !$response->successful()) {
                return null;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error("Fugle raw candles [{$symbol}]: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 解析 Fugle intraday/quote 回應
     *
     * 主要欄位對照（Fugle MarketData v1.0）：
     *   openPrice              → open
     *   highPrice              → high
     *   lowPrice               → low
     *   closePrice             → current_price（盤中最後成交價）
     *   referencePrice         → prev_close（昨收，漲跌停基準）
     *   total.tradeVolume      → accumulated_volume（API 回傳張，轉為股 shares）
     *   total.tradeVolumeAtAsk → trade_volume_at_ask（外盤量，轉為股）
     *   total.tradeVolumeAtBid → trade_volume_at_bid（內盤量，轉為股）
     *   limitUpPrice           → 由 Fugle 直接計算，無需自行推算
     *   limitDownPrice         → 同上
     *   isLimitUpPrice         → limit_up
     *   isLimitDownPrice       → limit_down
     *   lastUpdated            → timestamp_ms（微秒 timestamp → ms）
     */
    private function parseQuote(array $data): ?array
    {
        $symbol = $data['symbol'] ?? '';
        if (empty($symbol)) {
            return null;
        }

        $prevClose = (float) ($data['referencePrice'] ?? 0);
        if ($prevClose <= 0) {
            return null;
        }

        $open    = (float) ($data['openPrice']  ?? 0);
        $high    = (float) ($data['highPrice']  ?? 0);
        $low     = (float) ($data['lowPrice']   ?? 0);
        $current = (float) ($data['closePrice'] ?? 0);

        // Fugle 漲跌停旗標：isLimitUpPrice / isLimitDownPrice
        $limitUp   = (bool) ($data['isLimitUpPrice']  ?? $data['isLimitUp']  ?? false);
        $limitDown = (bool) ($data['isLimitDownPrice'] ?? $data['isLimitDown'] ?? false);

        $bestBid = (float) ($data['bids'][0]['price'] ?? 0);
        $bestAsk = (float) ($data['asks'][0]['price'] ?? 0);

        // 漲跌停鎖定時 closePrice 可能為 null
        if ($current <= 0) {
            if ($limitUp) {
                $current = (float) ($data['limitUpPrice'] ?? $bestBid);
            } elseif ($limitDown) {
                $current = (float) ($data['limitDownPrice'] ?? $bestAsk);
            } elseif ($bestBid > 0 && $bestAsk > 0) {
                $current = round(($bestBid + $bestAsk) / 2, 2);
            } elseif ($bestBid > 0) {
                $current = $bestBid;
            } elseif ($bestAsk > 0) {
                $current = $bestAsk;
            }
        }

        // 成交量在 total 物件下，Fugle 單位為「張」(lots)，轉為「股」(shares) 以匹配 DailyQuote
        $total = $data['total'] ?? [];
        $accVolume        = (int) ($total['tradeVolume']      ?? 0) * 1000;
        $volumeAtAsk      = (int) ($total['tradeVolumeAtAsk'] ?? 0) * 1000; // 外盤
        $volumeAtBid      = (int) ($total['tradeVolumeAtBid'] ?? 0) * 1000; // 內盤

        // lastUpdated: 微秒 timestamp（如 1776733207942780）→ 毫秒
        $ts = $data['lastUpdated'] ?? 0;
        if (is_string($ts) && !is_numeric($ts)) {
            $timestampMs = (int) (strtotime($ts) * 1000);
        } else {
            // 微秒 → 毫秒
            $timestampMs = (int) ($ts / 1000);
        }

        return [
            'symbol'              => $symbol,
            'name'                => $data['name'] ?? '',
            'open'                => $open,
            'high'                => $high,
            'low'                 => $low,
            'current_price'       => $current ?: $open,
            'prev_close'          => $prevClose,
            'accumulated_volume'  => $accVolume,
            'trade_volume_at_ask' => $volumeAtAsk,
            'trade_volume_at_bid' => $volumeAtBid,
            'best_ask'            => $bestAsk,
            'best_bid'            => $bestBid,
            'limit_up'            => $limitUp,
            'limit_down'          => $limitDown,
            'timestamp_ms'        => $timestampMs,
        ];
    }
}

<?php

namespace App\Services;

use App\Models\Stock;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FugleRealtimeClient
{
    private const API_BASE    = 'https://api.fugle.tw/marketdata/v1.0/stock/intraday/quote';
    private const REQUEST_DELAY_US = 150000; // 150ms，避免超過 rate limit

    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.fugle.api_key', '');
    }

    /**
     * 批次抓取即時報價（每檔獨立呼叫 Fugle API）
     *
     * @param  Stock[]  $stocks
     * @return array<string, array>  keyed by symbol
     */
    public function fetchQuotes(array $stocks): array
    {
        if (empty($stocks)) {
            return [];
        }

        $results = [];
        $total = count($stocks);

        foreach ($stocks as $index => $stock) {
            try {
                $response = Http::timeout(10)
                    ->withHeaders(['X-API-KEY' => $this->apiKey])
                    ->get(self::API_BASE . '/' . $stock->symbol);

                if (!$response->successful()) {
                    Log::warning("Fugle [{$stock->symbol}] HTTP {$response->status()}: " . $response->body());
                    continue;
                }

                $parsed = $this->parseQuote($response->json());
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
     * 解析 Fugle intraday/quote 回應
     *
     * 主要欄位對照：
     *   openPrice      → open
     *   highPrice      → high
     *   lowPrice       → low
     *   closePrice     → current_price（盤中最後成交價）
     *   referencePrice → prev_close（昨收，漲跌停基準）
     *   totalVolume    → accumulated_volume（單位：股 shares，非張）
     *   limitUpPrice   → 由 Fugle 直接計算，無需自行推算
     *   limitDownPrice → 同上
     *   isLimitUp      → limit_up
     *   isLimitDown    → limit_down
     *   lastUpdated    → timestamp_ms（ISO-8601 字串 → ms）
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

        // Fugle 提供現成的漲跌停旗標與價格，不需自行推算
        $limitUp   = (bool) ($data['isLimitUp']   ?? false);
        $limitDown = (bool) ($data['isLimitDown'] ?? false);

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

        // totalVolume 單位為「股」(shares)，與舊版 TwseRealtimeClient 轉換後一致
        $accVolume = (int) ($data['totalVolume'] ?? 0);

        // lastUpdated: ISO-8601 字串（"2026-04-15T09:30:00.000+08:00"）→ ms
        $ts = $data['lastUpdated'] ?? 0;
        $timestampMs = is_string($ts)
            ? (int) (strtotime($ts) * 1000)
            : (int) $ts;

        return [
            'symbol'             => $symbol,
            'name'               => $data['name'] ?? '',
            'open'               => $open,
            'high'               => $high,
            'low'                => $low,
            'current_price'      => $current ?: $open,
            'prev_close'         => $prevClose,
            'accumulated_volume' => $accVolume,
            'best_ask'           => $bestAsk,
            'best_bid'           => $bestBid,
            'limit_up'           => $limitUp,
            'limit_down'         => $limitDown,
            'timestamp_ms'       => $timestampMs,
        ];
    }
}

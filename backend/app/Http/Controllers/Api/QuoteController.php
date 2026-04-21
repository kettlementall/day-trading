<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class QuoteController extends Controller
{
    private string $apiKey;
    private const API_BASE = 'https://api.fugle.tw/marketdata/v1.0';

    public function __construct()
    {
        $this->apiKey = config('services.fugle.api_key', '');
    }

    public function show(string $symbol): JsonResponse
    {
        if (!preg_match('/^\d{4,6}$/', $symbol)) {
            return response()->json(['error' => '無效代號'], 422);
        }

        $headers = ['X-API-KEY' => $this->apiKey];

        // 同時抓 quote 和 candles
        $quoteResp = Http::timeout(10)->withHeaders($headers)
            ->get(self::API_BASE . "/stock/intraday/quote/{$symbol}");

        if (!$quoteResp->successful()) {
            return response()->json(['error' => '無法取得報價'], $quoteResp->status());
        }

        $quote = $quoteResp->json();
        if (empty($quote['symbol'])) {
            return response()->json(['error' => '查無此代號'], 404);
        }

        usleep(200000); // 200ms delay for rate limit

        $candleResp = Http::timeout(10)->withHeaders($headers)
            ->get(self::API_BASE . "/stock/intraday/candles/{$symbol}", ['timeframe' => '5']);

        $candles = $candleResp->successful() ? ($candleResp->json()['data'] ?? []) : [];

        // 整理回傳
        $total     = $quote['total'] ?? [];
        $prevClose = (float) ($quote['referencePrice'] ?? 0);
        $close     = (float) ($quote['closePrice'] ?? 0);
        $volAtAsk  = (int) ($total['tradeVolumeAtAsk'] ?? 0);
        $volAtBid  = (int) ($total['tradeVolumeAtBid'] ?? 0);

        return response()->json([
            'symbol'       => $quote['symbol'],
            'name'         => $quote['name'] ?? '',
            'prev_close'   => $prevClose,
            'open'         => (float) ($quote['openPrice'] ?? 0),
            'high'         => (float) ($quote['highPrice'] ?? 0),
            'low'          => (float) ($quote['lowPrice'] ?? 0),
            'close'        => $close,
            'change_pct'   => $prevClose > 0 ? round(($close - $prevClose) / $prevClose * 100, 2) : 0,
            'volume'       => (int) ($total['tradeVolume'] ?? 0),
            'transaction'  => (int) ($total['transaction'] ?? 0),
            'external_ratio' => ($volAtAsk + $volAtBid) > 0
                ? round($volAtAsk / ($volAtAsk + $volAtBid) * 100, 1) : 50,
            'bids'         => array_slice($quote['bids'] ?? [], 0, 5),
            'asks'         => array_slice($quote['asks'] ?? [], 0, 5),
            'is_close'     => $quote['isClose'] ?? false,
            'candles'      => array_map(fn($c) => [
                'time'   => substr($c['date'] ?? '', 11, 5),
                'open'   => (float) $c['open'],
                'high'   => (float) $c['high'],
                'low'    => (float) $c['low'],
                'close'  => (float) $c['close'],
                'volume' => (int) $c['volume'],
            ], $candles),
        ]);
    }
}

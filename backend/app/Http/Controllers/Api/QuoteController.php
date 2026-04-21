<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class QuoteController extends Controller
{
    private string $apiKey;
    private const API_BASE = 'https://api.fugle.tw/marketdata/v1.0';
    private const ANTHROPIC_API = 'https://api.anthropic.com/v1/messages';

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

    /**
     * POST /api/quote/{symbol}/analyze
     * AI 持倉分析：根據即時報價 + 成本價，給出續抱/止損/觀望建議
     */
    public function analyze(string $symbol): JsonResponse
    {
        $cost = (float) request('cost');
        if ($cost <= 0) {
            return response()->json(['error' => '請輸入有效的成本價'], 422);
        }

        if (!preg_match('/^\d{4,6}$/', $symbol)) {
            return response()->json(['error' => '無效代號'], 422);
        }

        $headers = ['X-API-KEY' => $this->apiKey];

        // 抓 quote
        $quoteResp = Http::timeout(10)->withHeaders($headers)
            ->get(self::API_BASE . "/stock/intraday/quote/{$symbol}");
        if (!$quoteResp->successful() || empty($quoteResp->json('symbol'))) {
            return response()->json(['error' => '無法取得報價'], 502);
        }
        $quote = $quoteResp->json();

        usleep(200000);

        // 抓 5 分 K
        $candleResp = Http::timeout(10)->withHeaders($headers)
            ->get(self::API_BASE . "/stock/intraday/candles/{$symbol}", ['timeframe' => '5']);
        $candles = $candleResp->successful() ? ($candleResp->json()['data'] ?? []) : [];

        // 組裝數據摘要
        $total     = $quote['total'] ?? [];
        $prevClose = (float) ($quote['referencePrice'] ?? 0);
        $close     = (float) ($quote['closePrice'] ?? 0);
        $open      = (float) ($quote['openPrice'] ?? 0);
        $high      = (float) ($quote['highPrice'] ?? 0);
        $low       = (float) ($quote['lowPrice'] ?? 0);
        $volume    = (int) ($total['tradeVolume'] ?? 0);
        $volAtAsk  = (int) ($total['tradeVolumeAtAsk'] ?? 0);
        $volAtBid  = (int) ($total['tradeVolumeAtBid'] ?? 0);
        $extRatio  = ($volAtAsk + $volAtBid) > 0
            ? round($volAtAsk / ($volAtAsk + $volAtBid) * 100, 1) : 50;
        $changePct = $prevClose > 0 ? round(($close - $prevClose) / $prevClose * 100, 2) : 0;
        $pnlPct    = $cost > 0 ? round(($close - $cost) / $cost * 100, 2) : 0;

        $name = $quote['name'] ?? '';

        // 5分K摘要（最近12根）
        $recentCandles = array_slice($candles, -12);
        $candleLines = array_map(function ($c) use ($prevClose) {
            $t = substr($c['date'] ?? '', 11, 5);
            $pct = $prevClose > 0 ? round(((float)$c['close'] - $prevClose) / $prevClose * 100, 2) : 0;
            return "{$t} O:{$c['open']} H:{$c['high']} L:{$c['low']} C:{$c['close']} V:{$c['volume']} ({$pct}%)";
        }, $recentCandles);

        // 五檔
        $bids = array_slice($quote['bids'] ?? [], 0, 5);
        $asks = array_slice($quote['asks'] ?? [], 0, 5);
        $bidLines = array_map(fn($b) => "買: {$b['price']} x {$b['size']}", $bids);
        $askLines = array_map(fn($a) => "賣: {$a['price']} x {$a['size']}", $asks);

        $dataBlock = implode("\n", [
            "股票：{$symbol} {$name}",
            "昨收：{$prevClose}　開：{$open}　高：{$high}　低：{$low}　現價：{$close}",
            "漲跌：{$changePct}%　成交量：{$volume}張　外盤比：{$extRatio}%",
            "成本價：{$cost}　帳面損益：{$pnlPct}%",
            "",
            "五檔：",
            implode("\n", $askLines),
            "---",
            implode("\n", $bidLines),
            "",
            "近期5分K：",
            implode("\n", $candleLines),
        ]);

        $systemPrompt = <<<'PROMPT'
你是一位台股短線交易顧問。根據用戶提供的即時報價數據，分析以下面向後給出持倉建議：

1. 開盤型態：開高/開低/平開，跳空幅度
2. 量價關係：量能集中時段、價漲量增或價跌量縮
3. 趨勢方向：從5分K判斷上升/下降/震盪
4. 關鍵價位：日高/日低/開盤價作為支撐壓力
5. 外盤比：>55%偏多、<45%偏空

最後根據成本價，明確給出以下其中一個建議：
- 續抱：說明理由，給出建議停利價和停損價
- 止損：說明理由，給出建議出場價
- 觀望：說明需要觀察的條件

回覆格式（嚴格遵守）：
第一行：建議|續抱 或 建議|止損 或 建議|觀望
第二行起：分析內容（簡潔扼要，150字內）
最後一行（如適用）：停利:{價格} 停損:{價格}
PROMPT;

        $anthropicKey = config('services.anthropic.api_key', '');
        $model = config('services.anthropic.sentiment_model', 'claude-haiku-4-5-20251001');

        $aiResp = Http::timeout(30)
            ->withHeaders([
                'x-api-key'         => $anthropicKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])
            ->post(self::ANTHROPIC_API, [
                'model'      => $model,
                'max_tokens' => 512,
                'messages'   => [
                    ['role' => 'user', 'content' => $dataBlock],
                ],
                'system' => $systemPrompt,
            ]);

        if (!$aiResp->successful()) {
            return response()->json(['error' => 'AI 分析失敗，請稍後再試'], 502);
        }

        $text = $aiResp->json('content.0.text') ?? '';

        // 解析建議類型
        $action = '觀望';
        if (preg_match('/建議\|(\S+)/', $text, $m)) {
            $action = $m[1];
        }

        // 解析停利停損
        $stopProfit = null;
        $stopLoss   = null;
        if (preg_match('/停利[:：]?\s*([\d.]+)/', $text, $m)) {
            $stopProfit = (float) $m[1];
        }
        if (preg_match('/停損[:：]?\s*([\d.]+)/', $text, $m)) {
            $stopLoss = (float) $m[1];
        }

        return response()->json([
            'action'      => $action,
            'analysis'    => $text,
            'pnl_pct'     => $pnlPct,
            'stop_profit' => $stopProfit,
            'stop_loss'   => $stopLoss,
            'current'     => $close,
            'cost'        => $cost,
        ]);
    }
}

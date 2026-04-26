<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyQuote;
use App\Models\IntradaySnapshot;
use App\Models\Stock;
use App\Services\FugleRealtimeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class QuoteController extends Controller
{
    private const ANTHROPIC_API = 'https://api.anthropic.com/v1/messages';

    public function __construct(private FugleRealtimeClient $fugle)
    {
    }

    /**
     * GET /api/quote/search?q=台積
     * 股票名稱/代號模糊搜尋（供前端 autocomplete）
     */
    public function search(): JsonResponse
    {
        $q = trim(request('q', ''));
        if (mb_strlen($q) < 1) {
            return response()->json([]);
        }

        $stocks = Stock::where(function ($query) use ($q) {
                $query->where('symbol', 'like', "{$q}%")
                      ->orWhere('name', 'like', "%{$q}%");
            })
            ->orderByRaw("CASE WHEN symbol = ? THEN 0 WHEN symbol LIKE ? THEN 1 ELSE 2 END", [$q, "{$q}%"])
            ->limit(10)
            ->get(['symbol', 'name']);

        return response()->json($stocks);
    }

    public function show(string $symbol): JsonResponse
    {
        if (!preg_match('/^\d{4,6}$/', $symbol)) {
            return response()->json(['error' => '無效代號'], 422);
        }

        // 先查 DB：今日最新 IntradaySnapshot
        $dbQuote = $this->getFromDb($symbol);

        if ($dbQuote) {
            // DB 有資料，嘗試補抓 candles（graceful failure）
            $dbQuote['candles'] = $this->fetchCandlesForSymbol($symbol);
            $dbQuote['daily_candles'] = $this->getDailyCandles($symbol);

            return response()->json($dbQuote);
        }

        // DB 沒有 → 呼叫 Fugle API
        return $this->fetchFromApi($symbol);
    }

    /**
     * 從 DB 取得今日最新快照資料
     */
    private function getFromDb(string $symbol): ?array
    {
        $stock = Stock::where('symbol', $symbol)->first();
        if (!$stock) {
            return null;
        }

        $snapshot = IntradaySnapshot::where('stock_id', $stock->id)
            ->where('trade_date', now()->format('Y-m-d'))
            ->orderByDesc('snapshot_time')
            ->first();

        if (!$snapshot) {
            return null;
        }

        $prevClose = (float) $snapshot->prev_close;
        $close     = (float) $snapshot->current_price;
        $volume    = (int) $snapshot->accumulated_volume;

        return [
            'symbol'         => $symbol,
            'name'           => $stock->name ?? '',
            'prev_close'     => $prevClose,
            'open'           => (float) $snapshot->open,
            'high'           => (float) $snapshot->high,
            'low'            => (float) $snapshot->low,
            'close'          => $close,
            'change_pct'     => $prevClose > 0 ? round(($close - $prevClose) / $prevClose * 100, 2) : 0,
            'volume'         => (int) round($volume / 1000), // shares → 張
            'transaction'    => 0,
            'external_ratio' => (float) $snapshot->external_ratio,
            'bids'           => $snapshot->best_bid > 0
                ? [['price' => (float) $snapshot->best_bid, 'size' => 0]]
                : [],
            'asks'           => $snapshot->best_ask > 0
                ? [['price' => (float) $snapshot->best_ask, 'size' => 0]]
                : [],
            'is_close'       => false,
            'candles'        => [],
            'source'         => 'db',
        ];
    }

    /**
     * 從 Fugle API 取得完整報價（fallback）
     */
    private function fetchFromApi(string $symbol): JsonResponse
    {
        $quote = $this->fugle->fetchRawQuote($symbol);

        if (!$quote) {
            return response()->json(['error' => '無法取得報價'], 502);
        }
        if (empty($quote['symbol'])) {
            return response()->json(['error' => '查無此代號'], 404);
        }

        $candles = $this->fugle->fetchCandles($symbol);

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
            'candles'        => $candles,
            'daily_candles'  => $this->getDailyCandles($quote['symbol']),
            'source'         => 'api',
        ]);
    }

    /**
     * 嘗試抓取 5 分 K（失敗回空陣列）
     */
    private function fetchCandlesForSymbol(string $symbol): array
    {
        return $this->fugle->fetchCandles($symbol);
    }


    /**
     * 取得近 30 日日 K（先查 DB，fallback Fugle）
     */
    private function getDailyCandles(string $symbol): array
    {
        $stock = Stock::where('symbol', $symbol)->first();
        if ($stock) {
            $quotes = DailyQuote::where('stock_id', $stock->id)
                ->orderByDesc('date')
                ->limit(30)
                ->get()
                ->reverse()
                ->values();

            if ($quotes->isNotEmpty()) {
                return $quotes->map(fn($q) => [
                    'date'   => \Carbon\Carbon::parse($q->date)->format('m/d'),
                    'open'   => (float) $q->open,
                    'high'   => (float) $q->high,
                    'low'    => (float) $q->low,
                    'close'  => (float) $q->close,
                    'volume' => (int) round($q->volume / 1000),
                ])->toArray();
            }
        }

        // Fallback: Fugle Historical API
        $fugleDaily = $this->fugle->fetchDailyCandles($symbol, 30);
        return array_map(fn($c) => [
            'date'   => \Carbon\Carbon::parse($c['date'])->format('m/d'),
            'open'   => $c['open'],
            'high'   => $c['high'],
            'low'    => $c['low'],
            'close'  => $c['close'],
            'volume' => (int) round($c['volume'] / 1000),
        ], $fugleDaily);
    }

    /**
     * POST /api/quote/{symbol}/analyze
     * AI 持倉分析：同時給出短線（當日）與波段（數天）兩個建議
     */
    public function analyze(string $symbol): JsonResponse
    {
        $cost = (float) request('cost');
        if ($cost <= 0) {
            return response()->json(['error' => '請輸入有效的成本價'], 422);
        }

        $shares = (int) request('shares', 0);  // 持倉張數，0 = 未提供
        $direction = request('direction', 'long'); // long or short
        if (!in_array($direction, ['long', 'short'])) {
            $direction = 'long';
        }

        if (!preg_match('/^\d{4,6}$/', $symbol)) {
            return response()->json(['error' => '無效代號'], 422);
        }

        // 先查 DB
        $dbData = $this->getAnalyzeDataFromDb($symbol);

        if (!$dbData) {
            // DB 沒有 → 呼叫 Fugle API
            $dbData = $this->getAnalyzeDataFromApi($symbol);
        }

        if (!$dbData) {
            return response()->json(['error' => '無法取得報價'], 502);
        }

        $prevClose = $dbData['prev_close'];
        $close     = $dbData['close'];
        $open      = $dbData['open'];
        $high      = $dbData['high'];
        $low       = $dbData['low'];
        $volume    = $dbData['volume'];
        $extRatio  = $dbData['external_ratio'];
        $changePct = $dbData['change_pct'];
        $name      = $dbData['name'];
        $candleLines = $dbData['candle_lines'];
        $bidLines  = $dbData['bid_lines'];
        $askLines  = $dbData['ask_lines'];

        $pnlPct = $cost > 0
            ? round(($direction === 'short' ? ($cost - $close) : ($close - $cost)) / $cost * 100, 2)
            : 0;

        // 近 20 日日 K 線（先查 DB，無資料再 fallback Fugle API）
        $dailyKLines = [];
        $stock = Stock::where('symbol', $symbol)->first();
        if ($stock) {
            $dailyQuotes = DailyQuote::where('stock_id', $stock->id)
                ->orderByDesc('date')
                ->limit(20)
                ->get()
                ->reverse();

            if ($dailyQuotes->isNotEmpty()) {
                $dailyKLines = ['日期  開  高  低  收  量(張)  漲%'];
                foreach ($dailyQuotes as $q) {
                    $dailyKLines[] = implode('  ', [
                        \Carbon\Carbon::parse($q->date)->format('m/d'),
                        (float) $q->open,
                        (float) $q->high,
                        (float) $q->low,
                        (float) $q->close,
                        round($q->volume / 1000),
                        ((float) $q->change_percent) . '%',
                    ]);
                }
            }
        }

        // DB 無資料 → Fugle Historical API fallback
        if (empty($dailyKLines)) {
            $fugleDaily = $this->fugle->fetchDailyCandles($symbol, 20);
            if (!empty($fugleDaily)) {
                $dailyKLines = ['日期  開  高  低  收  量(張)  漲%'];
                foreach ($fugleDaily as $c) {
                    $dailyKLines[] = implode('  ', [
                        \Carbon\Carbon::parse($c['date'])->format('m/d'),
                        $c['open'],
                        $c['high'],
                        $c['low'],
                        $c['close'],
                        round($c['volume'] / 1000),
                        $c['change_percent'] . '%',
                    ]);
                }
            }
        }
        $dailyKSection = !empty($dailyKLines) ? implode("\n", $dailyKLines) : '（無日K資料）';

        // 20 日均量（DB 優先，不足 20 筆則 fallback Fugle）
        $avgVolume = '—';
        $dbAvgCount = 0;
        if ($stock) {
            $dbAvgCount = DailyQuote::where('stock_id', $stock->id)->orderByDesc('date')->limit(20)->count();
            if ($dbAvgCount >= 20) {
                $avg = DailyQuote::where('stock_id', $stock->id)
                    ->orderByDesc('date')
                    ->limit(20)
                    ->avg('volume');
                $avgVolume = round($avg / 1000);
            }
        }
        if ($avgVolume === '—') {
            // DB 不足 → 從 Fugle fallback 資料或重新抓
            $fugleDailyForAvg = $fugleDaily ?? $this->fugle->fetchDailyCandles($symbol, 20);
            if (!empty($fugleDailyForAvg)) {
                $totalVol = array_sum(array_column($fugleDailyForAvg, 'volume'));
                $avgVolume = round($totalVol / count($fugleDailyForAvg) / 1000);
            }
        }

        $now = now()->timezone('Asia/Taipei');
        $currentTime = $now->format('H:i');
        $marketClose = '13:30';
        $minutesLeft = max(0, $now->diffInMinutes(\Carbon\Carbon::parse("today {$marketClose}", 'Asia/Taipei'), false));

        $dataBlock = implode("\n", [
            "股票：{$symbol} {$name}",
            "現在時間：{$currentTime}　距收盤：{$minutesLeft}分鐘",
            "昨收：{$prevClose}　開：{$open}　高：{$high}　低：{$low}　現價：{$close}",
            "漲跌：{$changePct}%　成交量：{$volume}張　20日均量：{$avgVolume}張　外盤比：{$extRatio}%",
            "持倉方向：" . ($direction === 'short' ? '做空' : '做多') . ($shares > 0 ? "　張數：{$shares}張" : '') . "　成本價：{$cost}　帳面損益：{$pnlPct}%",
            "",
            "五檔：",
            implode("\n", $askLines),
            "---",
            implode("\n", $bidLines),
            "",
            "近20日日K：",
            $dailyKSection,
            "",
            "今日5分K：",
            implode("\n", $candleLines),
        ]);

        $systemPrompt = <<<'PROMPT'
你是一位台股交易顧問。根據用戶提供的即時報價與近期日K數據，同時從「短線」和「波段」兩個角度分析持倉，一次給出兩個建議。

## 分析面向
1. 中期趨勢：從近20日日K判斷多空趨勢（均線方向、高低點位移），今日走勢在中期脈絡中的位置
2. 開盤型態：開高/開低/平開，跳空幅度
3. 量價關係：今日量 vs 20日均量判斷量能強弱，量價配合度，5分K中量能集中的時段
4. 盤中趨勢：從全天5分K判斷上升/下降/震盪，關鍵轉折點
5. 關鍵價位：日高/日低/開盤價/近20日高低點作為支撐壓力
6. 外盤比：>55%偏多、<45%偏空
7. 持倉方向：做多時價漲有利、做空時價跌有利，所有建議必須配合持倉方向
8. 部位大小：若有提供張數，評估風險時考量部位規模

## 短線建議（今日收盤前必須結束）
- 重點：盤中走勢、時間壓力（尾盤<30分鐘應積極決斷）；若已收盤則評估收盤結果
- 動作：續抱 / 加碼 / 止損 / 觀望
- 加碼條件：趨勢明確且有明確支撐（做多）或壓力（做空）時才建議，必須給出加碼價位

## 波段建議（可持有數天到數週）
- 重點：日K趨勢、量價結構、關鍵支撐壓力位
- 動作：續抱 / 加碼 / 減碼 / 止損 / 觀望
- 加碼條件：中期趨勢未破且回測支撐（做多）或壓力（做空）時，必須給出加碼價位

## 回覆格式（嚴格遵守 JSON，不要加 markdown 標記）
{
  "short": {
    "action": "續抱|加碼|止損|觀望",
    "analysis": "短線分析（100字內）",
    "stop_profit": 123.0,
    "stop_loss": 118.0,
    "add_price": 120.0
  },
  "long": {
    "action": "續抱|加碼|減碼|止損|觀望",
    "analysis": "波段分析（150字內）",
    "stop_profit": 130.0,
    "stop_loss": 115.0,
    "add_price": 118.0
  }
}
stop_profit/stop_loss/add_price 不適用時填 null。add_price 僅在 action 為「加碼」時必填。
PROMPT;

        $anthropicKey = config('services.anthropic.api_key', '');
        $model = config('services.anthropic.model', 'claude-opus-4-6');

        $aiResp = Http::timeout(30)
            ->withHeaders([
                'x-api-key'         => $anthropicKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])
            ->post(self::ANTHROPIC_API, [
                'model'      => $model,
                'max_tokens' => 768,
                'messages'   => [
                    ['role' => 'user', 'content' => $dataBlock],
                ],
                'system' => $systemPrompt,
            ]);

        if (!$aiResp->successful()) {
            return response()->json(['error' => 'AI 分析失敗，請稍後再試'], 502);
        }

        $text = $aiResp->json('content.0.text') ?? '';

        // 清理 markdown 包裹（```json ... ```）
        $cleanText = preg_replace('/^```(?:json)?\s*\n?/i', '', trim($text));
        $cleanText = preg_replace('/\n?```\s*$/', '', $cleanText);

        // 解析 JSON 回應
        $parsed = json_decode($cleanText, true);

        $short = $parsed['short'] ?? null;
        $long  = $parsed['long'] ?? null;

        $fallback = ['action' => '觀望', 'analysis' => '無法解析 AI 回應', 'stop_profit' => null, 'stop_loss' => null];

        return response()->json([
            'short'   => $short ?: $fallback,
            'long'    => $long ?: $fallback,
            'pnl_pct' => $pnlPct,
            'current' => $close,
            'cost'    => $cost,
        ]);
    }

    /**
     * 從 DB 取得 analyze 所需的報價資料
     */
    private function getAnalyzeDataFromDb(string $symbol): ?array
    {
        $stock = Stock::where('symbol', $symbol)->first();
        if (!$stock) {
            return null;
        }

        $snapshot = IntradaySnapshot::where('stock_id', $stock->id)
            ->where('trade_date', now()->format('Y-m-d'))
            ->orderByDesc('snapshot_time')
            ->first();

        if (!$snapshot) {
            return null;
        }

        $prevClose = (float) $snapshot->prev_close;
        $close     = (float) $snapshot->current_price;
        $volume    = (int) round((int) $snapshot->accumulated_volume / 1000);
        $extRatio  = (float) $snapshot->external_ratio;
        $changePct = $prevClose > 0 ? round(($close - $prevClose) / $prevClose * 100, 2) : 0;

        // 嘗試抓 candles
        $candles = $this->fetchCandlesForSymbol($symbol);
        $candleLines = array_map(function ($c) use ($prevClose) {
            $pct = $prevClose > 0 ? round(($c['close'] - $prevClose) / $prevClose * 100, 2) : 0;
            return "{$c['time']} O:{$c['open']} H:{$c['high']} L:{$c['low']} C:{$c['close']} V:{$c['volume']} ({$pct}%)";
        }, $candles);

        return [
            'name'           => $stock->name ?? '',
            'prev_close'     => $prevClose,
            'open'           => (float) $snapshot->open,
            'high'           => (float) $snapshot->high,
            'low'            => (float) $snapshot->low,
            'close'          => $close,
            'volume'         => $volume,
            'external_ratio' => $extRatio,
            'change_pct'     => $changePct,
            'candle_lines'   => $candleLines,
            'bid_lines'      => $snapshot->best_bid > 0
                ? ["買: {$snapshot->best_bid} x -"]
                : [],
            'ask_lines'      => $snapshot->best_ask > 0
                ? ["賣: {$snapshot->best_ask} x -"]
                : [],
        ];
    }

    /**
     * 從 Fugle API 取得 analyze 所需的報價資料
     */
    private function getAnalyzeDataFromApi(string $symbol): ?array
    {
        $quote = $this->fugle->fetchRawQuote($symbol);
        if (!$quote || empty($quote['symbol'])) {
            return null;
        }

        $candles = $this->fugle->fetchCandles($symbol);

        $total     = $quote['total'] ?? [];
        $prevClose = (float) ($quote['referencePrice'] ?? 0);
        $close     = (float) ($quote['closePrice'] ?? 0);
        $volAtAsk  = (int) ($total['tradeVolumeAtAsk'] ?? 0);
        $volAtBid  = (int) ($total['tradeVolumeAtBid'] ?? 0);
        $extRatio  = ($volAtAsk + $volAtBid) > 0
            ? round($volAtAsk / ($volAtAsk + $volAtBid) * 100, 1) : 50;

        $candleLines = array_map(function ($c) use ($prevClose) {
            $pct = $prevClose > 0 ? round(($c['close'] - $prevClose) / $prevClose * 100, 2) : 0;
            return "{$c['time']} O:{$c['open']} H:{$c['high']} L:{$c['low']} C:{$c['close']} V:{$c['volume']} ({$pct}%)";
        }, $candles);

        $bids = array_slice($quote['bids'] ?? [], 0, 5);
        $asks = array_slice($quote['asks'] ?? [], 0, 5);

        return [
            'name'           => $quote['name'] ?? '',
            'prev_close'     => $prevClose,
            'open'           => (float) ($quote['openPrice'] ?? 0),
            'high'           => (float) ($quote['highPrice'] ?? 0),
            'low'            => (float) ($quote['lowPrice'] ?? 0),
            'close'          => $close,
            'volume'         => (int) ($total['tradeVolume'] ?? 0),
            'external_ratio' => $extRatio,
            'change_pct'     => $prevClose > 0 ? round(($close - $prevClose) / $prevClose * 100, 2) : 0,
            'candle_lines'   => $candleLines,
            'bid_lines'      => array_map(fn($b) => "買: {$b['price']} x {$b['size']}", $bids),
            'ask_lines'      => array_map(fn($a) => "賣: {$a['price']} x {$a['size']}", $asks),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyQuote;
use App\Models\InstitutionalTrade;
use App\Models\IntradaySnapshot;
use App\Models\InvestmentThesis;
use App\Models\SectorIndex;
use App\Models\Stock;
use App\Models\StockValuation;
use App\Services\FugleRealtimeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class QuoteController extends Controller
{
    private const ANTHROPIC_API = 'https://api.anthropic.com/v1/messages';
    private const SYMBOL_PATTERN = '/^\d{4,6}[A-Z]?$/';

    public function __construct(private FugleRealtimeClient $fugle)
    {
    }

    /**
     * GET /api/quote/search?q=台積
     * 股票名稱/代號模糊搜尋（供前端 autocomplete）
     */
    public function search(): JsonResponse
    {
        $q = strtoupper(trim(request('q', '')));
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
        $symbol = $this->normalizeSymbol($symbol);
        if (!$this->isValidSymbol($symbol)) {
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

        $candles = \Illuminate\Support\Facades\Cache::remember(
            "candles:{$symbol}",
            60,
            fn() => $this->fugle->fetchCandles($symbol)
        );

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
     * 取得 5 分 K：候選標的從 DB snapshot 聚合，其餘走 Fugle API + 60 秒 cache
     */
    private function fetchCandlesForSymbol(string $symbol): array
    {
        $stock = Stock::where('symbol', $symbol)->first();
        if ($stock) {
            $today = now()->format('Y-m-d');
            $snapshots = IntradaySnapshot::where('stock_id', $stock->id)
                ->where('trade_date', $today)
                ->orderBy('snapshot_time')
                ->get();

            if ($snapshots->count() >= 2) {
                return $this->aggregateSnapshotsToCandles($snapshots);
            }
        }

        // 非候選標的：Fugle API + 60 秒 cache
        return \Illuminate\Support\Facades\Cache::remember(
            "candles:{$symbol}",
            60,
            fn() => $this->fugle->fetchCandles($symbol)
        );
    }

    /**
     * 從 IntradaySnapshot 聚合為前端格式的 5 分 K
     */
    private function aggregateSnapshotsToCandles(\Illuminate\Support\Collection $snapshots): array
    {
        $buckets = [];
        foreach ($snapshots as $snap) {
            $time = $snap->snapshot_time;
            $slot = (int) floor((int) $time->format('i') / 5) * 5;
            $key = $time->format('H') . ':' . str_pad($slot, 2, '0', STR_PAD_LEFT);
            $buckets[$key][] = $snap;
        }
        ksort($buckets);

        $candles = [];
        $prevAccVol = 0;

        foreach ($buckets as $time => $snaps) {
            $first = $snaps[0];
            $last = $snaps[count($snaps) - 1];
            $accVolNow = (int) $last->accumulated_volume;
            $periodVol = max(0, $accVolNow - $prevAccVol);
            $prevAccVol = $accVolNow;

            $prices = array_map(fn($s) => (float) $s->current_price, $snaps);

            $candles[] = [
                'time'   => $time,
                'open'   => (float) $first->current_price,
                'high'   => max($prices),
                'low'    => min($prices),
                'close'  => (float) $last->current_price,
                'volume' => (int) round($periodVol / 1000),
            ];
        }

        return $candles;
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

        // Fallback: Fugle Historical API（cache 5 分鐘）
        $fugleDaily = \Illuminate\Support\Facades\Cache::remember(
            "daily_candles:{$symbol}",
            300,
            fn() => $this->fugle->fetchDailyCandles($symbol, 30)
        );

        usort($fugleDaily, fn($a, $b) => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));

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
        $symbol = $this->normalizeSymbol($symbol);

        $cost = (float) request('cost');
        if ($cost <= 0) {
            return response()->json(['error' => '請輸入有效的成本價'], 422);
        }

        $shares = (int) request('shares', 0);  // 持倉張數，0 = 未提供
        $direction = request('direction', 'long'); // long or short
        if (!in_array($direction, ['long', 'short'])) {
            $direction = 'long';
        }

        if (!$this->isValidSymbol($symbol)) {
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
        $tradeDate = $dbData['trade_date'];
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
        $weekday = ['日', '一', '二', '三', '四', '五', '六'][$now->dayOfWeek];
        $queryDateStr = $now->format('Y-m-d') . "（{$weekday}）";

        $tradeDateCarbon = \Carbon\Carbon::parse($tradeDate);
        $tradeWeekday = ['日', '一', '二', '三', '四', '五', '六'][$tradeDateCarbon->dayOfWeek];
        $tradeDateStr = $tradeDateCarbon->format('Y-m-d') . "（{$tradeWeekday}）";

        $tradeDate = $tradeDate instanceof \Carbon\Carbon ? $tradeDate->format('Y-m-d') : (string) $tradeDate;
        $isTradeDay = $now->format('Y-m-d') === $tradeDate;
        $marketClose = '13:30';
        $minutesLeft = $isTradeDay
            ? max(0, $now->diffInMinutes(\Carbon\Carbon::parse("today {$marketClose}", 'Asia/Taipei'), false))
            : 0;

        // 類股與大盤背景
        $industry = $stock ? ($stock->industry ?? '') : '';
        $sectorLine = '';
        if ($industry) {
            $sectorChg  = SectorIndex::getChangeForIndustry($tradeDate, $industry);
            $sectorRank = SectorIndex::getRankForIndustry($tradeDate, $industry);
            $totalSectors = SectorIndex::where('date', SectorIndex::latestDateOn($tradeDate))->count();
            if ($sectorChg !== null) {
                $sign = $sectorChg >= 0 ? '+' : '';
                $sectorLine = "所屬類股：{$industry}　漲跌：{$sign}{$sectorChg}%　排名：{$sectorRank}/{$totalSectors}";
            }
        }

        // AI 產業論點：找出該股對應到的 active 論點（取 confidence 最高 2 筆）
        $thesisSection = '';
        if ($stock) {
            $matched = InvestmentThesis::where('status', InvestmentThesis::STATUS_ACTIVE)
                ->where('confidence_score', '>=', 35)
                ->orderByDesc('confidence_score')
                ->limit(20)
                ->get()
                ->map(function (InvestmentThesis $t) use ($symbol) {
                    foreach (($t->related_stocks ?? []) as $r) {
                        if (!is_array($r)) continue;
                        if ((string) ($r['symbol'] ?? '') === $symbol) {
                            return ['thesis' => $t, 'related' => $r];
                        }
                    }
                    return null;
                })
                ->filter()
                ->take(2)
                ->values();
            if ($matched->isNotEmpty()) {
                $lines = ['AI 產業論點（波段參考為主，短線不可被論點主導）：'];
                foreach ($matched as $m) {
                    $t = $m['thesis'];
                    $r = $m['related'];
                    $risks = is_array($r['risks'] ?? null) ? implode('、', array_slice($r['risks'], 0, 3)) : '';
                    $lines[] = sprintf(
                        '- 【%s】信心 %d | 此股角色：%s（%s）confidence=%d | 受惠理由：%s%s',
                        $t->title,
                        (int) $t->confidence_score,
                        $r['benefit_level'] ?? 'watch',
                        $r['role'] ?? '-',
                        (int) ($r['confidence'] ?? 0),
                        mb_substr((string) ($r['reasoning'] ?? ''), 0, 80),
                        $risks ? "｜風險：{$risks}" : ''
                    );
                }
                $thesisSection = implode("\n", $lines);
            }
        }

        // 估值（PE/PB/殖利率/EPS）
        $valuationLine = '';
        if ($stock) {
            $v = StockValuation::where('stock_id', $stock->id)
                ->where('date', '<=', $tradeDate)
                ->orderByDesc('date')
                ->first();
            if ($v) {
                $valuationLine = sprintf(
                    '估值：PE=%s　PB=%s　殖利率=%s%%　EPS(TTM)=%s（資料日 %s）',
                    $v->pe_ratio ?? '—',
                    $v->pb_ratio ?? '—',
                    $v->dividend_yield ?? '—',
                    $v->eps_ttm ?? '—',
                    $v->date?->format('Y-m-d') ?? '—'
                );
            }
        }

        // 法人 5 日
        $instLine = '';
        if ($stock) {
            $inst5 = InstitutionalTrade::where('stock_id', $stock->id)
                ->where('date', '<=', $tradeDate)
                ->orderByDesc('date')
                ->limit(5)
                ->get();
            if ($inst5->isNotEmpty()) {
                $total = (int) $inst5->sum('total_net');
                $foreign = (int) $inst5->sum('foreign_net');
                $trust = (int) $inst5->sum('trust_net');
                $dealer = (int) $inst5->sum('dealer_net');
                $fmt = fn ($n) => ($n >= 0 ? '+' : '') . number_format($n / 1000) . '張';
                $instLine = sprintf(
                    '法人 5 日：合計 %s（外資 %s／投信 %s／自營 %s）',
                    $fmt($total),
                    $fmt($foreign),
                    $fmt($trust),
                    $fmt($dealer)
                );
            }
        }

        $limitUpPrice = $prevClose > 0 ? round($prevClose * 1.10, 2) : 0;
        $limitDownPrice = $prevClose > 0 ? round($prevClose * 0.90, 2) : 0;

        $dataBlock = implode("\n", array_filter([
            "股票：{$symbol} {$name}",
            "查詢時間：{$queryDateStr} {$currentTime}" . ($isTradeDay ? "　距收盤：{$minutesLeft}分鐘" : '　（非交易時段）'),
            "報價交易日：{$tradeDateStr}" . (!$isTradeDay ? '　⚠ 以下報價為該交易日收盤資料，非即時' : ''),
            "昨收：{$prevClose}　開：{$open}　高：{$high}　低：{$low}　現價：{$close}　漲停：{$limitUpPrice}　跌停：{$limitDownPrice}",
            "漲跌：{$changePct}%　成交量：{$volume}張　20日均量：{$avgVolume}張　外盤比：{$extRatio}%",
            $sectorLine ?: null,
            $valuationLine ?: null,
            $instLine ?: null,
            "持倉方向：" . ($direction === 'short' ? '做空' : '做多') . ($shares > 0 ? "　張數：{$shares}張" : '') . "　成本價：{$cost}　帳面損益：{$pnlPct}%",
            $thesisSection ?: null,
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
        ], fn($v) => $v !== null));

        $systemPrompt = <<<'PROMPT'
你是台股交易顧問，依即時報價與近期日 K，同時從「短線」與「波段」給雙建議。

## 分析面向
1. 中期趨勢（近20日日K均線方向、高低點位移）
2. 開盤型態（開高/開低/平開、跳空幅度）
3. 量價關係（今日量 vs 20日均量、5分K量能集中時段）
4. 盤中趨勢（5分K上升/下降/震盪、轉折點）
5. 關鍵價位（日高低、開盤價、近20日高低）
6. 外盤比（>55% 偏多 / <45% 偏空）
7. 類股背景（個股漲跌 vs 類股漲跌、類股排名）
8. 持倉方向（做多看價漲、做空看價跌）
9. 部位大小（若有張數，評估風險）
10. 估值（PE/PB/殖利率）：**波段用**。PE 高+殖利率低 → 上檔壓力大；殖利率高+PE 合理 → 下檔有支撐
11. 法人 5 日：中期支撐/壓力指標。外資/投信/自營分項看誰是主力。連續買超 → 波段加碼依據；連續賣超 → 減碼警訊
12. AI 產業論點：**波段用**。判斷 benefit_level（core/secondary/watch）、角色、信心。短線參考即可，不可被論點主導（短線靠技術 + 量價）

## 短線建議
**盤中（距收盤 > 0）**：重盤中走勢與時間壓力（尾盤 <30 分鐘積極決斷）。動作 = 續抱 / 加碼 / 止損 / 觀望。加碼需給明確支撐（多）或壓力（空）+ 加碼價位。
**盤後（距收盤 = 0）**：評估收盤結果並給下一交易日操作。動作 = 下一交易日開盤出場 / 觀察續抱 / 掛價加碼 / 掛價停損。掛價類動作必須給具體 add_price 或 stop_loss。週五盤後跨週末風險高，建議更保守。

## 波段建議（持有數天到數週）
重點 = 日 K 趨勢 + 量價結構 + 支撐壓力 + **估值 + 法人 5 日 + AI 論點**。動作 = 續抱 / 加碼 / 減碼 / 止損 / 觀望。加碼需中期趨勢未破且回測支撐/壓力 + 加碼價位。
論點權重：core + 信心 ≥60 → 視為「題材有效」，加碼/續抱門檻可放寬；watch 或 confidence <50 → 以技術為主，不要被論點背書誤導。

## 回覆格式（嚴格 JSON，不包 markdown）
{
  "short": {
    "action": "<盤中：續抱|加碼|止損|觀望 ；盤後：下一交易日開盤出場|觀察續抱|掛價加碼|掛價停損>",
    "analysis": "<≤100 字>",
    "stop_profit": <num|null>, "stop_loss": <num|null>, "add_price": <num|null>
  },
  "long": {
    "action": "<續抱|加碼|減碼|止損|觀望>",
    "analysis": "<≤150 字>",
    "stop_profit": <num|null>, "stop_loss": <num|null>, "add_price": <num|null>
  }
}
價格欄位不適用填 null；add_price 僅在 action=加碼/掛價加碼 時必填。

## 價格限制
stop_profit / stop_loss / add_price 不可超過漲停或低於跌停；接近漲停時 stop_profit 最高設到漲停價。

## 用語規範
- 報價資料用「該交易日」或具體日期，不用「今日/本日」
- 未來操作用「下一交易日」，不用「明日/明天」
- 查詢時間 ≠ 報價交易日（非交易時段查）時，短線建議以「下一交易日」開盤為基準
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

    private function normalizeSymbol(string $symbol): string
    {
        return strtoupper(trim($symbol));
    }

    private function isValidSymbol(string $symbol): bool
    {
        return preg_match(self::SYMBOL_PATTERN, $symbol) === 1;
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
            'trade_date'     => $snapshot->trade_date,
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

        $candles = $this->fetchCandlesForSymbol($symbol);

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
            'trade_date'     => $quote['date'] ?? now()->format('Y-m-d'),
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

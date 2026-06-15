<?php

namespace App\Services;

use App\Models\InstitutionalTrade;
use App\Models\MarketHoliday;
use App\Models\NewsArticle;
use App\Models\NewsIndex;
use App\Models\PremarketBriefing;
use App\Models\SectorIndex;
use App\Models\Stock;
use App\Models\UsMarketIndex;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PremarketBriefingService
{
    private string $apiKey;
    private string $model;

    // Opus 4.6 pricing (per 1M tokens, USD)
    private const COST_INPUT_PER_M = 15.0;
    private const COST_OUTPUT_PER_M = 75.0;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key', '');
        $this->model = config('services.anthropic.model', 'claude-opus-4-8');
    }

    /**
     * 產出單日盤前簡報並推播
     *
     * @return array{briefing: PremarketBriefing, broadcasted: bool}
     */
    public function generate(string $tradeDate, ?\Closure $logger = null): array
    {
        $log = $logger ?? function (string $msg) { Log::info($msg); };

        $log("收集 {$tradeDate} 盤前素材...");
        $payload = $this->collectInputs($tradeDate);

        $log("呼叫 Opus 產出方向判斷...");
        $aiResult = $this->callOpus($payload);

        $parsed = $this->parseResponse($aiResult['text']);
        $markdown = $this->buildTelegramMarkdown($tradeDate, $payload, $parsed);

        $briefing = PremarketBriefing::updateOrCreate(
            ['trade_date' => $tradeDate],
            [
                'market_context'    => $payload['market_context']['label'] ?? null,
                'direction'         => $parsed['direction'] ?? null,
                'headline'          => $parsed['headline'] ?? null,
                'drivers'           => $parsed['drivers'] ?? [],
                'focus_sectors'     => $parsed['sectors'] ?? [],
                'cautions'          => $parsed['cautions'] ?? [],
                'raw_markdown'      => $markdown,
                'input_payload'     => $payload,
                'model'             => $this->model,
                'prompt_tokens'     => $aiResult['prompt_tokens'] ?? null,
                'completion_tokens' => $aiResult['completion_tokens'] ?? null,
                'cost_usd'          => $this->estimateCost(
                    $aiResult['prompt_tokens'] ?? 0,
                    $aiResult['completion_tokens'] ?? 0
                ),
            ]
        );

        $log("推送 Telegram 通知...");
        app(TelegramService::class)->broadcast($markdown, 'signal');

        return ['briefing' => $briefing, 'broadcasted' => true];
    }

    /**
     * 收集 Opus 推理所需的全部原始資料
     */
    public function collectInputs(string $tradeDate): array
    {
        $marketContext = MarketContextService::detect($tradeDate);

        // 台指期改讀 TX_NIGHT（夜盤收盤 vs 前一日盤收的正確語意），fallback TX（舊有日盤盤中價）
        // 避免 AI 把日盤盤中價誤當成夜盤；TX_DAY 不送進 prompt（briefing 不需要昨日日盤）
        $usIndices = UsMarketIndex::where('date', $tradeDate)
            ->whereIn('symbol', ['^GSPC', '^SOX', '^DJI', '^IXIC', 'DX-Y.NYB', '^VIX', 'TX_NIGHT', 'TX'])
            ->get()
            ->groupBy('symbol');
        $txRow = $usIndices->get('TX_NIGHT')?->first() ?? $usIndices->get('TX')?->first();
        $usIndices = $usIndices->forget(['TX', 'TX_NIGHT'])->flatten();
        if ($txRow) {
            $usIndices->push($txRow);
        }
        $usIndices = $usIndices->map(fn($i) => [
                'symbol'         => $i->symbol === 'TX_NIGHT' ? 'TX' : $i->symbol,
                'name'           => $i->symbol === 'TX_NIGHT' ? '台指期夜盤' : $i->name,
                'close'          => (float) $i->close,
                'change_percent' => (float) $i->change_percent,
            ])
            ->values()
            ->all();

        // NewsIndex：取今日 vs 昨日 industry 情緒差異最大的前 5 個類別
        $todayIndustries = NewsIndex::where('scope', 'industry')
            ->where('date', $tradeDate)
            ->get()
            ->keyBy('scope_value');

        $prevDate = MarketHoliday::previousTradingDay($tradeDate);
        $prevIndustries = NewsIndex::where('scope', 'industry')
            ->where('date', $prevDate)
            ->get()
            ->keyBy('scope_value');

        $newsDiff = [];
        foreach ($todayIndustries as $industry => $idx) {
            $todaySentiment = (float) $idx->sentiment;
            $prevSentiment = isset($prevIndustries[$industry])
                ? (float) $prevIndustries[$industry]->sentiment
                : 50.0;
            $newsDiff[] = [
                'industry'         => $industry,
                'today_sentiment'  => $todaySentiment,
                'yesterday_sentiment' => $prevSentiment,
                'diff'             => round($todaySentiment - $prevSentiment, 2),
                'heatmap'          => (float) $idx->heatmap,
                'article_count'    => (int) $idx->article_count,
            ];
        }
        usort($newsDiff, fn($a, $b) => abs($b['diff']) <=> abs($a['diff']));
        $newsDiff = array_slice($newsDiff, 0, 8);

        $newsOverall = NewsIndex::where('scope', 'overall')
            ->where('date', $tradeDate)
            ->first();

        // 法人 T-1：以 trade_date 往前找最近一個有資料的日期
        $latestInstDate = InstitutionalTrade::where('date', '<', $tradeDate)
            ->orderByDesc('date')
            ->value('date');

        $instTopBuy = [];
        $instTopSell = [];
        if ($latestInstDate) {
            $rows = InstitutionalTrade::with('stock:id,symbol,name,industry')
                ->where('date', $latestInstDate)
                ->orderByDesc('total_net')
                ->limit(5)
                ->get();
            foreach ($rows as $r) {
                $instTopBuy[] = $this->formatInstRow($r);
            }

            $rows = InstitutionalTrade::with('stock:id,symbol,name,industry')
                ->where('date', $latestInstDate)
                ->orderBy('total_net')
                ->limit(5)
                ->get();
            foreach ($rows as $r) {
                $instTopSell[] = $this->formatInstRow($r);
            }
        }

        return [
            'trade_date' => $tradeDate,
            'market_context' => [
                'label'    => $marketContext['label'],
                'triggers' => $marketContext['triggers'] ?? [],
                'hint'     => $marketContext['hint'] ?? '',
                'sox_change' => $marketContext['sox_change'] ?? null,
                'tx_change'  => $marketContext['tx_change'] ?? null,
                'beneficiary_industries' => $marketContext['beneficiary_industries'] ?? [],
            ],
            'us_indices' => $usIndices,
            'news_overall' => $newsOverall ? [
                'date'          => $newsOverall->date->format('Y-m-d'),
                'sentiment'     => (float) $newsOverall->sentiment,
                'heatmap'       => (float) $newsOverall->heatmap,
                'panic'         => (float) $newsOverall->panic,
                'international' => (float) $newsOverall->international,
                'article_count' => (int) $newsOverall->article_count,
            ] : null,
            'news_industry_changes' => $newsDiff,
            'institutional' => [
                'date'      => $latestInstDate,
                'top_buy'   => $instTopBuy,
                'top_sell'  => $instTopSell,
            ],
            'sector_strength' => $this->collectSectorStrength($tradeDate),
            'market_rhythm'   => $this->collectMarketRhythm($tradeDate),
            'key_events'      => $this->collectKeyEvents($tradeDate),
        ];
    }

    /**
     * 取昨日類股強弱（T-1 最近一個交易日）：top 5 強勢 / top 5 弱勢
     */
    private function collectSectorStrength(string $tradeDate): array
    {
        $effectiveDate = SectorIndex::latestDateOn($tradeDate);
        if (!$effectiveDate) {
            return ['date' => null, 'top_gainers' => [], 'top_losers' => []];
        }

        $sectors = SectorIndex::where('date', $effectiveDate)->get();

        $topGainers = $sectors->sortByDesc('change_percent')->take(5)->values()
            ->map(fn($s) => [
                'name'           => $s->sector_name,
                'change_percent' => (float) $s->change_percent,
            ])->all();

        $topLosers = $sectors->sortBy('change_percent')->take(5)->values()
            ->map(fn($s) => [
                'name'           => $s->sector_name,
                'change_percent' => (float) $s->change_percent,
            ])->all();

        return [
            'date'         => $effectiveDate,
            'top_gainers'  => $topGainers,
            'top_losers'   => $topLosers,
        ];
    }

    /**
     * 大盤節奏代理：取 3 個權值類股（電子工業 / 金融保險 / 半導體業）近 5 個交易日累計變化
     * sector_indices 沒有加權指數，這 3 個合計約佔 TAIEX 70-80%，可作為大盤節奏近似
     */
    private function collectMarketRhythm(string $tradeDate): array
    {
        $proxies = ['電子工業', '金融保險', '半導體業'];
        $effectiveDate = SectorIndex::latestDateOn($tradeDate);
        if (!$effectiveDate) {
            return ['date' => null, 'proxies' => []];
        }

        $rows = [];
        foreach ($proxies as $name) {
            $recent = SectorIndex::where('sector_name', $name)
                ->where('date', '<=', $effectiveDate)
                ->orderByDesc('date')
                ->limit(5)
                ->get();

            if ($recent->isEmpty()) {
                continue;
            }

            $cumChange = round((float) $recent->sum('change_percent'), 2);
            $latest = $recent->first();

            $rows[] = [
                'name'              => $name,
                'latest_change'     => (float) $latest->change_percent,
                'five_day_cumulative' => $cumChange,
                'trend'             => $this->describeRhythmTrend($cumChange, $recent->avg('change_percent')),
            ];
        }

        return ['date' => $effectiveDate, 'proxies' => $rows];
    }

    private function describeRhythmTrend(float $cumulative, float $avg): string
    {
        if ($cumulative >= 3.0) return 'strong_up';      // 5 日累漲 ≥ 3%
        if ($cumulative >= 1.0) return 'mild_up';
        if ($cumulative <= -3.0) return 'strong_down';
        if ($cumulative <= -1.0) return 'mild_down';
        return 'sideways';
    }

    /**
     * 取近 24 小時重大事件新聞 top 5
     * 條件：impact=high 或 panic_signal=true，依「panic 優先 + |sentiment| 大」排序
     */
    private function collectKeyEvents(string $tradeDate): array
    {
        $articles = NewsArticle::whereDate('fetched_date', '>=', date('Y-m-d', strtotime($tradeDate . ' -1 day')))
            ->whereDate('fetched_date', '<=', $tradeDate)
            ->whereNotNull('ai_analysis')
            ->where(function ($q) {
                $q->whereRaw("JSON_EXTRACT(ai_analysis, '$.impact') = 'high'")
                  ->orWhereRaw("JSON_EXTRACT(ai_analysis, '$.panic_signal') = true");
            })
            ->orderByRaw("JSON_EXTRACT(ai_analysis, '$.panic_signal') DESC")
            ->orderByRaw('ABS(sentiment_score) DESC')
            ->limit(8)
            ->get();

        return $articles->map(function (NewsArticle $a) {
            $ai = $a->ai_analysis ?? [];
            return [
                'title'           => $a->title,
                'sentiment_score' => (float) $a->sentiment_score,
                'category'        => $a->category,
                'impact'          => $ai['impact'] ?? null,
                'panic_signal'    => (bool) ($ai['panic_signal'] ?? false),
                'risk_type'       => $ai['risk_type'] ?? null,
                'industries'      => $ai['industries'] ?? null,
            ];
        })->values()->all();
    }

    private function formatInstRow(InstitutionalTrade $row): array
    {
        return [
            'symbol'      => $row->stock?->symbol,
            'name'        => $row->stock?->name,
            'industry'    => $row->stock?->industry,
            'foreign_net' => (int) $row->foreign_net,
            'trust_net'   => (int) $row->trust_net,
            'dealer_net'  => (int) $row->dealer_net,
            'total_net'   => (int) $row->total_net,
        ];
    }

    public function callOpus(array $payload): array
    {
        $prompt = $this->buildPrompt($payload);
        $maxAttempts = 3;
        $retryableStatuses = [429, 500, 502, 503, 504, 529];
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::timeout(60)
                    ->withHeaders([
                        'x-api-key'         => $this->apiKey,
                        'anthropic-version' => '2023-06-01',
                        'content-type'      => 'application/json',
                    ])
                    ->post('https://api.anthropic.com/v1/messages', [
                        'model'      => $this->model,
                        'max_tokens' => 1500,
                        'messages'   => [
                            ['role' => 'user', 'content' => $prompt],
                        ],
                    ]);

                if ($response->successful()) {
                    $text = trim($response->json('content.0.text', ''));
                    $usage = $response->json('usage') ?? [];

                    return [
                        'text'              => $text,
                        'prompt_tokens'     => $usage['input_tokens'] ?? null,
                        'completion_tokens' => $usage['output_tokens'] ?? null,
                    ];
                }

                $status = $response->status();
                $body = mb_substr($response->body(), 0, 300);
                Log::warning("PremarketBriefingService attempt {$attempt}/{$maxAttempts} HTTP {$status}: {$body}");
                $lastError = "HTTP {$status}: {$body}";

                if (!in_array($status, $retryableStatuses, true)) {
                    break; // 不可重試的錯誤直接中止
                }
            } catch (\Throwable $e) {
                Log::warning("PremarketBriefingService attempt {$attempt}/{$maxAttempts} exception: " . $e->getMessage());
                $lastError = $e->getMessage();
            }

            if ($attempt < $maxAttempts) {
                $sleepSec = $attempt * 5; // 5s, 10s
                Log::info("PremarketBriefingService: {$sleepSec}s 後重試");
                sleep($sleepSec);
            }
        }

        Log::error("PremarketBriefingService: 連續 {$maxAttempts} 次 Opus 呼叫失敗 — {$lastError}");
        throw new \RuntimeException("Opus API 連續 {$maxAttempts} 次呼叫失敗：{$lastError}");
    }

    private function buildPrompt(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return <<<PROMPT
你是資深台股盤前策略分析師。根據以下隔夜資料，產出一份精簡可執行的「今日方向簡報」供操盤者參考。

## 輸入素材（trade_date = 今日台股交易日）
```json
{$json}
```

## 分析原則
1. 方向判斷以**台指期夜盤**為主、美股指數為輔；費半暴漲/暴跌對台股半導體相關類股有強催化。
2. NewsIndex：industry 情緒分數變化 > +5 視為利多升溫，< -5 視為利空升溫。
3. 法人 T-1：外資+投信合計買超 > 3 億張代表強勢；賣超 > 3 億張代表弱勢。
4. **sector_strength** 反映「昨日的類股慣性」；**market_rhythm** 的 5 日累計變化代表大盤節奏（strong_up/strong_down 都意味趨勢明確，sideways 代表盤整）。
5. **key_events** 中 panic_signal=true 或 impact=high 的新聞要納入考量，可能是「個股利空擴散」或「題材催化」。
6. **不要推薦個股**（個股由 08:00 AI 選股負責），sectors 欄位只給類股名稱（如「半導體」「金融」「航運」）。
7. headline 必須一句點出方向，避免「視情況」「謹慎觀察」這類含糊用語。

## sectors 欄位語意（依 direction 給對應的類股）
- direction = **bullish** → 給「**追擊類股**」（今日主流、相對抗跌、有催化）
- direction = **bearish** → 給「**迴避類股**」（最受國際利空衝擊、權值股拖累對象）
- direction = **neutral** → 給「**觀察類股**」（量能集中、可能領漲領跌）

## cautions 必須包含具體動作（**強制**）
至少 1 條 caution 必須是**可執行的操作建議**，例如：
- 「建議倉位 ≤ 30%，等開盤跳空後 15 分鐘止穩才考慮進場」
- 「停損縮緊至 2%，獲利目標下修」
- 「半倉觀望，盤中量縮才能追多」
- 「重點守住停損，不搶反彈」

另 1 條 caution 可以是風險提示或剩餘留意點。

## 輸出規範
**僅輸出純 JSON**，不要加 markdown 標記、不要加其他文字。schema：

```json
{
  "direction": "bullish | neutral | bearish",
  "headline": "≤ 30 字一句結論",
  "drivers": ["≤ 50 字 × 最多 3 條，依重要性排序"],
  "sectors": ["≤ 3 個產業類股名稱（依 direction 對應追擊/迴避/觀察）"],
  "cautions": ["≤ 60 字 × 最多 2 條，**至少 1 條必須是具體操作建議**"]
}
```
PROMPT;
    }

    public function parseResponse(string $rawText): array
    {
        $cleaned = preg_replace('/^```json?\s*/i', '', $rawText);
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);
        $cleaned = trim($cleaned);

        $parsed = json_decode($cleaned, true);

        if (!is_array($parsed) && preg_match('/\{[\s\S]*\}/u', $rawText, $m)) {
            $parsed = json_decode($m[0], true);
        }

        if (!is_array($parsed)) {
            Log::error('PremarketBriefingService: 無法解析 Opus 回應', ['raw' => mb_substr($rawText, 0, 500)]);
            return [
                'direction'     => 'neutral',
                'headline'      => 'AI 回應解析失敗，請手動檢視原文',
                'drivers'       => [],
                'sectors'       => [],
                'cautions'      => ['AI 回應未通過 JSON 解析'],
            ];
        }

        // 兼容舊欄位 focus_sectors（若未來改 schema 不會炸）
        $sectors = $parsed['sectors'] ?? $parsed['focus_sectors'] ?? [];

        return [
            'direction'     => $parsed['direction'] ?? 'neutral',
            'headline'      => $parsed['headline'] ?? '',
            'drivers'       => array_values(array_filter((array) ($parsed['drivers'] ?? []), 'is_string')),
            'sectors'       => array_values(array_filter((array) $sectors, 'is_string')),
            'cautions'      => array_values(array_filter((array) ($parsed['cautions'] ?? []), 'is_string')),
        ];
    }

    private function buildTelegramMarkdown(string $tradeDate, array $payload, array $parsed): string
    {
        $direction = $parsed['direction'] ?? 'neutral';
        $directionEmoji = match ($direction) {
            'bullish'  => '📈 偏多',
            'bearish'  => '📉 偏空',
            default    => '⚖️ 中性',
        };

        $sectorsLabel = match ($direction) {
            'bullish'  => '🎯 *追擊類股*',
            'bearish'  => '🚫 *迴避類股*',
            default    => '🔍 *觀察類股*',
        };

        $shortDate = substr($tradeDate, 5); // MM-DD
        $lines = [];
        $lines[] = "🌅 *盤前方向 {$shortDate}*";
        $lines[] = '';
        $lines[] = "*{$directionEmoji}*｜" . ($parsed['headline'] ?: '（無 headline）');

        if (!empty($parsed['drivers'])) {
            $lines[] = '';
            $lines[] = '*驅動因素*';
            foreach ($parsed['drivers'] as $i => $d) {
                $lines[] = ($i + 1) . '. ' . $d;
            }
        }

        if (!empty($parsed['sectors'])) {
            $lines[] = '';
            $lines[] = "{$sectorsLabel}：" . implode('、', $parsed['sectors']);
        }

        if (!empty($parsed['cautions'])) {
            $lines[] = '';
            $lines[] = '⚠️ *操作建議 / 風險*';
            foreach ($parsed['cautions'] as $c) {
                $lines[] = '- ' . $c;
            }
        }

        // 附腳註：市場情境、大盤節奏
        $footnotes = [];
        $ctx = $payload['market_context']['label'] ?? null;
        if ($ctx && $ctx !== 'normal') {
            $triggers = implode('、', $payload['market_context']['triggers'] ?? []);
            $footnotes[] = "情境：{$ctx}（{$triggers}）";
        }

        $rhythm = $payload['market_rhythm']['proxies'] ?? [];
        if (!empty($rhythm)) {
            $rhythmParts = [];
            foreach ($rhythm as $p) {
                $sign = $p['five_day_cumulative'] >= 0 ? '+' : '';
                $rhythmParts[] = "{$p['name']} {$sign}{$p['five_day_cumulative']}%";
            }
            $footnotes[] = '大盤節奏 5 日累計：' . implode(' / ', $rhythmParts);
        }

        if (!empty($footnotes)) {
            $lines[] = '';
            foreach ($footnotes as $fn) {
                $lines[] = "_{$fn}_";
            }
        }

        return implode("\n", $lines);
    }

    private function estimateCost(int $promptTokens, int $completionTokens): float
    {
        $cost = ($promptTokens / 1_000_000) * self::COST_INPUT_PER_M
              + ($completionTokens / 1_000_000) * self::COST_OUTPUT_PER_M;
        return round($cost, 4);
    }
}

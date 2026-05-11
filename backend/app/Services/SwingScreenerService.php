<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\DailyQuote;
use App\Models\InstitutionalTrade;
use App\Models\InvestmentThesis;
use App\Models\MarginTrade;
use App\Models\Stock;
use App\Models\SectorIndex;
use App\Models\StockValuation;
use App\Models\SwingPosition;
use App\Models\ThesisStockLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SwingScreenerService
{
    private string $apiKey;
    private string $model;
    private int $maxAiAttempts = 3;

    public function __construct(private FugleRealtimeClient $fugle)
    {
        $this->apiKey = config('services.anthropic.api_key', '');
        $this->model = config('services.anthropic.screening_model', 'claude-opus-4-6');
    }

    public function screen(string $date): Collection
    {
        $theses = InvestmentThesis::where('status', InvestmentThesis::STATUS_ACTIVE)
            ->where('confidence_score', '>=', 40)
            ->where(function ($q) use ($date) {
                $q->whereNull('research_date')
                    ->orWhere('research_date', '<=', $date);
            })
            ->orderByDesc('confidence_score')
            ->limit(12)
            ->get();

        $rows = Stock::where('is_swing_eligible', true)->get()
            ->map(fn (Stock $stock) => $this->buildCandidatePayload($stock, $date, $theses))
            ->filter()
            ->sortByDesc('pre_score')
            ->take(100)
            ->values();

        if (!$this->apiKey) {
            throw new \RuntimeException('短線 AI 選股需要 Anthropic API key；禁止退回規則分數產生候選。');
        }

        $aiRows = $rows->take(30)->values();
        $selected = $this->askAiWithRetry($date, $rows, $theses, $aiRows);
        $selectedBySymbol = collect($selected)->keyBy('symbol');

        Candidate::where('trade_date', $date)->where('mode', 'swing')->delete();

        $created = $aiRows->map(function (array $row) use ($date, $selectedBySymbol) {
            $ai = $selectedBySymbol->get($row['symbol']);
            $score = max(0, min(100, (float) $ai['score']));
            $aiSelected = (bool) $ai['selected'];
            $entry = (float) ($ai['entry_price'] ?? $row['entry_price']);
            $stop = (float) ($ai['stop_loss'] ?? $row['stop_loss']);
            $target = (float) ($ai['target_price'] ?? $row['target_price']);
            $entryPlan = $ai['entry_plan'] ?? [];
            $entryPlan = array_merge([
                'entry_price' => $entry,
                'target_price' => $target,
                'stop_loss' => $stop,
                'expected_holding_days' => $ai['expected_holding_days'] ?? null,
                'target_eta_days' => $ai['target_eta_days'] ?? null,
                'target_price_reasoning' => $ai['target_price_reasoning'] ?? null,
                'eta_reasoning' => $ai['eta_reasoning'] ?? null,
                'review_after_days' => $ai['review_after_days'] ?? null,
            ], $entryPlan);
            $swingThesis = array_merge(
                is_array($row['thesis'] ?? null) ? $row['thesis'] : [],
                is_array($ai['thesis'] ?? null) ? $ai['thesis'] : []
            );

            $candidate = Candidate::create([
                'stock_id' => $row['stock_id'],
                'trade_date' => $date,
                'mode' => 'swing',
                'suggested_buy' => $entry,
                'target_price' => $target,
                'stop_loss' => $stop,
                'risk_reward_ratio' => $entry > $stop ? round(($target - $entry) / ($entry - $stop), 2) : 0,
                'score' => $score,
                'reasons' => $row['reasons'],
                'indicators' => $row['indicators'],
                'haiku_selected' => $score >= 45,
                'haiku_reasoning' => '短線物理篩選與論點關聯分數',
                'ai_selected' => $aiSelected,
                'ai_reasoning' => $ai['reasoning'],
                'swing_strategy' => $ai['strategy'] ?? $row['strategy'],
                'swing_reasoning' => $ai['reasoning'],
                'swing_thesis' => $swingThesis,
                'swing_time_horizon_days' => (int) ($ai['time_horizon_days'] ?? $ai['target_eta_days'] ?? 20),
                'swing_entry_plan' => $entryPlan,
                'swing_risk_notes' => $ai['risk_notes'] ?? $row['risk_notes'],
            ]);

            foreach ($row['thesis_links'] as $link) {
                ThesisStockLink::updateOrCreate(
                    ['investment_thesis_id' => $link['thesis_id'], 'stock_id' => $row['stock_id']],
                    ['relevance_score' => $link['score'], 'evidence' => $link['evidence']]
                );
            }

            return $candidate->load('stock');
        });

        $created->where('ai_selected', true)
            ->sortByDesc('score')
            ->values()
            ->slice(15)
            ->each(function (Candidate $candidate) {
                $candidate->update([
                    'ai_selected' => false,
                    'ai_reasoning' => trim(($candidate->ai_reasoning ?? '') . "\n\n系統控管：短線每日最多選入 15 檔，此檔保留為觀察候選。"),
                ]);
            });

        $this->relinkActivePositions($created);

        return $created->map->fresh(['stock']);
    }

    private function relinkActivePositions(Collection $created): void
    {
        $candidateIdsByStock = $created->keyBy('stock_id')->map->id;
        if ($candidateIdsByStock->isEmpty()) {
            return;
        }

        SwingPosition::whereIn('status', SwingPosition::ACTIVE_STATUSES)
            ->whereIn('stock_id', $candidateIdsByStock->keys())
            ->get()
            ->each(function (SwingPosition $position) use ($candidateIdsByStock) {
                $candidateId = $candidateIdsByStock->get($position->stock_id);
                if ($candidateId && (int) $position->candidate_id !== (int) $candidateId) {
                    $position->candidate_id = $candidateId;
                    $position->save();
                }
            });
    }

    private function isLikelyEtf(Stock $stock): bool
    {
        if (str_starts_with($stock->symbol ?? '', '00')) {
            return true;
        }
        $name = $stock->name ?? '';
        return mb_stripos($name, 'ETF') !== false
            || mb_stripos($name, '指數') !== false
            || mb_stripos($name, '基金') !== false;
    }

    private function buildCandidatePayload(Stock $stock, string $date, Collection $theses): ?array
    {
        $quotes = DailyQuote::where('stock_id', $stock->id)
            ->where('date', '<=', $date)
            ->orderByDesc('date')
            ->limit(80)
            ->get();
        if ($quotes->count() < 60 && $date === now()->toDateString()) {
            $this->backfillDailyQuotesFromFugle($stock, 80);
            $quotes = DailyQuote::where('stock_id', $stock->id)
                ->where('date', '<=', $date)
                ->orderByDesc('date')
                ->limit(80)
                ->get();
        }
        if ($quotes->count() < 60) {
            Log::info("SwingScreener skipped {$stock->symbol}: insufficient_kline_after_fugle count={$quotes->count()} date={$date}");
            return null;
        }

        $closes = $quotes->pluck('close')->map(fn ($v) => (float) $v)->toArray();
        $highs = $quotes->pluck('high')->map(fn ($v) => (float) $v)->toArray();
        $lows = $quotes->pluck('low')->map(fn ($v) => (float) $v)->toArray();
        $volumes = $quotes->pluck('volume')->map(fn ($v) => (int) $v)->toArray();
        $latest = $quotes->first();
        $close = (float) $latest->close;
        if ($close < 10 || ($volumes[0] / 1000) < 300) {
            return null;
        }

        $ma5 = TechnicalIndicator::sma($closes, 5);
        $ma10 = TechnicalIndicator::sma($closes, 10);
        $ma20 = TechnicalIndicator::sma($closes, 20);
        $ma60 = TechnicalIndicator::sma($closes, 60);
        $rsi = TechnicalIndicator::rsi($closes);
        $kd = TechnicalIndicator::kd($highs, $lows, $closes);
        $atr = TechnicalIndicator::atr($highs, $lows, $closes);
        $bollinger = TechnicalIndicator::bollinger($closes);
        $macd = TechnicalIndicator::macd($closes);

        $inst = InstitutionalTrade::where('stock_id', $stock->id)->where('date', '<=', $date)->orderByDesc('date')->limit(5)->get();
        $margin = MarginTrade::where('stock_id', $stock->id)->where('date', '<=', $date)->orderByDesc('date')->limit(5)->get();
        $valuation = StockValuation::where('stock_id', $stock->id)->where('date', '<=', $date)->orderByDesc('date')->first();
        $isEtf = $this->isLikelyEtf($stock);
        // ETF 的 industry 欄位資料品質差（部分舊 ETF 被誤填成塑膠/紡織等），不對應單一類股
        $sectorChange = ($isEtf || !$stock->industry) ? null : SectorIndex::getChangeForIndustry($date, $stock->industry);
        $sectorRank = ($isEtf || !$stock->industry) ? null : SectorIndex::getRankForIndustry($date, $stock->industry);

        $trendScore = ($ma20 && $ma60 && $close > $ma20 && $ma20 >= $ma60) ? 25 : 0;
        $pullbackScore = ($ma20 && abs($close - $ma20) / $ma20 < 0.05) ? 15 : 0;
        $chipScore = min(25, max(0, $inst->sum('total_net') / 100000));
        $volumeScore = min(15, max(0, (($volumes[0] / max(1, array_sum(array_slice($volumes, 0, 20)) / 20)) - 1) * 10));
        $thesisLinks = $this->scoreThesisLinks($stock, $theses);
        $thesisScore = min(20, collect($thesisLinks)->max('score') / 5);
        $preScore = round(30 + $trendScore + $pullbackScore + $chipScore + $volumeScore + $thesisScore, 2);

        if ($preScore < 45) {
            return null;
        }

        $entry = round($close, 2);
        $stop = round(max($close * 0.92, $close - (($atr ?: $close * 0.03) * 1.5)), 2);
        $target = round($close + (($atr ?: $close * 0.03) * 2.5), 2);

        $topThesis = collect($thesisLinks)->sortByDesc('score')->first();

        return [
            'stock_id' => $stock->id,
            'symbol' => $stock->symbol,
            'name' => $stock->name,
            'industry' => $stock->industry,
            'pre_score' => $preScore,
            'entry_price' => $entry,
            'stop_loss' => $stop,
            'target_price' => $target,
            'strategy' => $pullbackScore > 0 ? 'trend_pullback' : 'trend_follow',
            'reasoning' => '趨勢、籌碼、量能與產業論點綜合評估入選。',
            'risk_notes' => ['跌破停損', '法人轉賣', '產業論點降權或失效'],
            'reasons' => array_values(array_filter([
                $trendScore ? '中期趨勢向上' : null,
                $pullbackScore ? '靠近均線支撐' : null,
                $chipScore ? '法人買超' : null,
                $thesisScore ? '產業論點關聯' : null,
            ])),
            'indicators' => compact('ma5', 'ma10', 'ma20', 'ma60', 'rsi', 'kd', 'atr', 'bollinger', 'macd'),
            'valuation' => $valuation ? [
                'pe_ratio' => $valuation->pe_ratio,
                'pb_ratio' => $valuation->pb_ratio,
                'dividend_yield' => $valuation->dividend_yield,
                'eps_ttm' => $valuation->eps_ttm,
            ] : null,
            'sector' => [
                'name' => $stock->industry,
                'change_percent' => $sectorChange,
                'rank' => $sectorRank,
            ],
            'chips' => [
                'institutional_5d' => $inst->sum('total_net'),
                'margin_5d' => $margin->sum('margin_change'),
            ],
            'thesis' => $topThesis,
            'thesis_links' => $thesisLinks,
        ];
    }

    private function scoreThesisLinks(Stock $stock, Collection $theses): array
    {
        $text = mb_strtolower(($stock->industry ?? '') . ' ' . $stock->name . ' ' . $stock->symbol);
        return $theses->map(function (InvestmentThesis $thesis) use ($text, $stock) {
            $related = $this->findRelatedStock($thesis, $stock);
            if ($related) {
                $base = match ($related['benefit_level'] ?? 'watch') {
                    'core' => 75,
                    'secondary' => 55,
                    default => 35,
                };
                $score = $base
                    + min(10, (int) round(($related['confidence'] ?? 50) / 10))
                    + min(10, (int) round($thesis->confidence_score / 10));

                return [
                    'thesis_id' => $thesis->id,
                    'title' => $thesis->title,
                    'score' => min(100, $score),
                    'source' => 'related_stock',
                    'benefit_level' => $related['benefit_level'] ?? 'watch',
                    'role' => $related['role'] ?? null,
                    'related_reasoning' => $related['reasoning'] ?? null,
                    'related_confidence' => $related['confidence'] ?? null,
                    'risks' => $related['risks'] ?? [],
                    'evidence' => array_values(array_filter([
                        'AI 論點點名個股',
                        ($related['benefit_level'] ?? null) ? '受益層級 ' . $related['benefit_level'] : null,
                        ($related['role'] ?? null) ? '角色：' . $related['role'] : null,
                        $related['reasoning'] ?? null,
                    ])),
                ];
            }

            $score = 0;
            $evidence = [];
            foreach (($thesis->beneficiary_industries ?? []) as $industry) {
                if ($stock->industry && mb_stripos($stock->industry, (string) $industry) !== false) {
                    $score += 35;
                    $evidence[] = "產業符合 {$industry}";
                }
            }
            foreach (($thesis->beneficiary_keywords ?? []) as $keyword) {
                if ($keyword && mb_stripos($text, mb_strtolower((string) $keyword)) !== false) {
                    $score += 10;
                    $evidence[] = "關鍵字 {$keyword}";
                }
            }
            $score += (int) round($thesis->confidence_score / 5);
            return [
                'thesis_id' => $thesis->id,
                'title' => $thesis->title,
                'score' => min(60, $score),
                'source' => 'keyword_industry',
                'evidence' => array_slice($evidence, 0, 5),
            ];
        })->filter(fn ($link) => $link['score'] >= 20)->values()->all();
    }

    private function findRelatedStock(InvestmentThesis $thesis, Stock $stock): ?array
    {
        foreach (($thesis->related_stocks ?? []) as $related) {
            if (!is_array($related)) {
                continue;
            }
            if ((string) ($related['symbol'] ?? '') === (string) $stock->symbol) {
                return $related;
            }
        }

        return null;
    }

    private function backfillDailyQuotesFromFugle(Stock $stock, int $days): void
    {
        $candles = $this->fugle->fetchDailyCandles($stock->symbol, $days);
        if (empty($candles)) {
            return;
        }

        foreach ($candles as $candle) {
            if (empty($candle['date']) || (float) ($candle['close'] ?? 0) <= 0) {
                continue;
            }

            $open = (float) ($candle['open'] ?? 0);
            $high = (float) ($candle['high'] ?? 0);
            $low = (float) ($candle['low'] ?? 0);
            $close = (float) ($candle['close'] ?? 0);
            $changePercent = (float) ($candle['change_percent'] ?? 0);
            $change = $changePercent !== 0
                ? round($close - ($close / (1 + ($changePercent / 100))), 2)
                : 0.0;
            $prevClose = $close - $change;
            $amplitude = $prevClose > 0 ? round(($high - $low) / $prevClose * 100, 2) : 0;

            DailyQuote::updateOrCreate(
                ['stock_id' => $stock->id, 'date' => $candle['date']],
                [
                    'open' => $open,
                    'high' => $high,
                    'low' => $low,
                    'close' => $close,
                    'volume' => (int) ($candle['volume'] ?? 0),
                    'trade_value' => 0,
                    'trade_count' => 0,
                    'change' => $change,
                    'change_percent' => $changePercent,
                    'amplitude' => $amplitude,
                ]
            );
        }
    }

    private function askAi(string $date, Collection $rows, Collection $theses): array
    {
        $thesisText = $theses->map(function ($t) {
            $related = collect($t->related_stocks ?? [])
                ->take(12)
                ->map(fn ($s) => ($s['symbol'] ?? '') . ($s['name'] ?? '') . '/' . ($s['benefit_level'] ?? 'watch') . '/' . ($s['role'] ?? ''))
                ->filter()
                ->implode('；');

            return "- #{$t->id} {$t->title} 信心{$t->confidence_score}: {$t->description}" . ($related ? " | 個股映射：{$related}" : '');
        })->implode("\n");
        $candidates = $rows->take(30)->values();
        $stockText = $candidates->map(function ($r) {
            $val = $r['valuation'] ?? null;
            $valText = $val
                ? sprintf('PE=%s PB=%s 殖利率=%s%% EPS=%s',
                    $val['pe_ratio'] ?? '—',
                    $val['pb_ratio'] ?? '—',
                    $val['dividend_yield'] ?? '—',
                    $val['eps_ttm'] ?? '—',
                )
                : '估值=—';

            $sec = $r['sector'] ?? null;
            $secChange = $sec['change_percent'] ?? null;
            $secRank = $sec['rank'] ?? null;
            $secText = $secChange !== null
                ? sprintf('類股%s%s%%%s',
                    $secChange >= 0 ? '+' : '',
                    $secChange,
                    $secRank ? "(排名#{$secRank})" : '',
                )
                : '類股=—';

            return "{$r['symbol']} {$r['name']} {$r['industry']} pre_score={$r['pre_score']} close={$r['entry_price']} | {$valText} | {$secText} | thesis=" . json_encode($r['thesis'], JSON_UNESCAPED_UNICODE);
        })->implode("\n");
        $totalCount = $candidates->count();

        $prompt = <<<PROMPT
你是穩健型台股理財專員，請從候選股票中挑選適合 1-4 週短線配置的標的。避免追高，重視下檔風險、產業論點、籌碼、技術位置與估值。

日期：{$date}

產業論點：
{$thesisText}

股票候選：
{$stockText}

評分規則（嚴格遵守，違反此規則的回應將被視為無效）：
1. **必須對全部 {$totalCount} 檔候選逐一輸出**，順序不限，但每一檔都要有獨立 score + reasoning。
2. score 必須在候選之間呈現顯著差異，禁止集中給滿分。建議分布：
   - 90 分以上：最多 2 檔。
   - 80–89 分：最多 4 檔。
   - 70–79 分：5–8 檔（次選擔當，可作為備援）。
   - 70 分以下：其餘候選依下檔風險、論點關聯度、技術位置高低排序。
3. selected=true 僅給予實質想下單的檔次（8–12 檔），其餘設 false。
4. score 與 selected 必須一致：score < 70 不得 selected=true；score >= 80 應該 selected=true。
5. 同一個 strategy 內也應該有分數階梯，不要全部一樣分。
6. benefit_level=core 可提高論點權重；secondary 中度加權；watch 只能當輔助。
7. **字數紀律（嚴格控制 output 長度以免被截斷）**：
   - reasoning：**最多 50 字**，一句話帶出「策略 + 個股映射角色 + 關鍵風險」。
   - target_price_reasoning：**最多 35 字**，必含一個依據（壓力/均線/ATR/R:R 之一）。
   - eta_reasoning：**最多 30 字**，必含一個依據（趨勢斜率/波動/量能/題材催化窗之一）。
   - risk_notes：**最多 3 條，每條 15 字內**。
   - selected=false 的檔次，reasoning 可更短（20-30 字），不需展開細節。
8. **禁止輸出 entry_plan 物件**——系統會自動從 top-level 欄位組合，重複輸出會被視為違規。

請只輸出 JSON 陣列（共 {$totalCount} 筆，與候選清單一一對應），不要包 markdown：
{
  "symbol": "2330",
  "selected": true,
  "score": 0到100,
  "strategy": "trend_pullback/trend_follow/base_breakout",
  "reasoning": "≤50字一句話判斷",
  "thesis": {"title": "論點", "benefit_level": "core/secondary/watch", "role": "產業鏈角色"},
  "entry_price": 100,
  "target_price": 110,
  "target_price_reasoning": "≤35字目標價依據",
  "stop_loss": 95,
  "time_horizon_days": 20,
  "expected_holding_days": "10-25",
  "target_eta_days": 12,
  "eta_reasoning": "≤30字ETA依據",
  "review_after_days": 5,
  "risk_notes": ["風險1","風險2","風險3"]
}
PROMPT;

        try {
            $response = Http::timeout(240)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $this->model,
                    'max_tokens' => 16000,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);

            if (!$response->successful()) {
                Log::error('SwingScreener API error: ' . $response->body());
                throw new \RuntimeException('短線 AI 選股 API 失敗：' . mb_substr($response->body(), 0, 300));
            }

            if ($response->json('stop_reason') === 'max_tokens') {
                Log::error('SwingScreener: hit max_tokens limit, response truncated');
                throw new \RuntimeException('短線 AI 選股回覆超過 max_tokens，被截斷；未寫入候選。');
            }

            $text = $response->json('content.0.text', '');
            // Robust JSON array extraction：取第一個 [ 到最後一個 ]，避開 markdown 包裹與前後雜訊
            $start = strpos($text, '[');
            $end = strrpos($text, ']');
            if ($start === false || $end === false || $end <= $start) {
                Log::error('SwingScreener: no JSON array brackets found, snippet=' . mb_substr(trim($text), 0, 500));
                throw new \RuntimeException('短線 AI 選股回覆不是 JSON 陣列；未寫入候選。');
            }
            $data = json_decode(substr($text, $start, $end - $start + 1), true);
            if (!is_array($data)) {
                Log::error('SwingScreener: JSON decode failed, snippet=' . mb_substr(trim($text), 0, 500));
                throw new \RuntimeException('短線 AI 選股 JSON 解析失敗；未寫入候選。');
            }
            return $data;
        } catch (\Throwable $e) {
            Log::error('SwingScreener: ' . $e->getMessage());
            throw $e;
        }
    }

    private function askAiWithRetry(string $date, Collection $rows, Collection $theses, Collection $aiRows): array
    {
        $last = null;

        for ($attempt = 1; $attempt <= $this->maxAiAttempts; $attempt++) {
            try {
                $selected = $this->askAi($date, $rows, $theses);
                $this->assertValidAiSelections($selected, $aiRows);

                if ($attempt > 1) {
                    Log::info("SwingScreener: AI succeeded on retry attempt {$attempt}");
                }

                return $selected;
            } catch (\Throwable $e) {
                $last = $e;
                Log::warning("SwingScreener: AI attempt {$attempt}/{$this->maxAiAttempts} failed: " . $e->getMessage());

                if ($attempt < $this->maxAiAttempts) {
                    usleep(500000 * $attempt);
                }
            }
        }

        throw new \RuntimeException(
            '短線 AI 選股重試 ' . $this->maxAiAttempts . ' 次仍失敗；未寫入候選。最後錯誤：' . ($last?->getMessage() ?? 'unknown'),
            previous: $last
        );
    }

    private function assertValidAiSelections(array $selected, Collection $rows): void
    {
        $expectedSymbols = $rows->pluck('symbol')->map(fn ($symbol) => (string) $symbol)->values();
        $selectedBySymbol = collect($selected)->filter(fn ($item) => is_array($item) && isset($item['symbol']))
            ->keyBy(fn ($item) => (string) $item['symbol']);

        $missing = $expectedSymbols->reject(fn ($symbol) => $selectedBySymbol->has($symbol))->values();
        if ($missing->isNotEmpty()) {
            throw new \RuntimeException('短線 AI 選股回覆缺少候選：' . $missing->take(8)->implode(', '));
        }

        $selectedCount = 0;
        foreach ($expectedSymbols as $symbol) {
            $item = $selectedBySymbol->get($symbol);
            if (!isset($item['score'], $item['selected'], $item['reasoning'])) {
                throw new \RuntimeException("短線 AI 選股 {$symbol} 缺少 score/selected/reasoning。");
            }
            $score = (float) $item['score'];
            if ($score < 0 || $score > 100) {
                throw new \RuntimeException("短線 AI 選股 {$symbol} score 超出 0-100。");
            }
            if (trim((string) $item['reasoning']) === '' || trim((string) $item['reasoning']) === '趨勢、籌碼、量能與產業論點綜合評估入選。') {
                throw new \RuntimeException("短線 AI 選股 {$symbol} reasoning 無效。");
            }
            if ((bool) $item['selected']) {
                $targetReasoning = trim((string) ($item['target_price_reasoning'] ?? data_get($item, 'entry_plan.target_price_reasoning', '')));
                $etaReasoning = trim((string) ($item['eta_reasoning'] ?? data_get($item, 'entry_plan.eta_reasoning', '')));
                if ($targetReasoning === '' || $etaReasoning === '') {
                    throw new \RuntimeException("短線 AI 選股 {$symbol} 缺少目標價或 ETA 數字理由。");
                }
            }
            if ((bool) $item['selected']) {
                $selectedCount++;
            }
        }

        if ($selectedCount < 8 || $selectedCount > 15) {
            throw new \RuntimeException("短線 AI 選股 selected 數量異常：{$selectedCount}。");
        }
    }
}

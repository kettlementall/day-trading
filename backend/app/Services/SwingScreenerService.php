<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\DailyQuote;
use App\Models\InstitutionalTrade;
use App\Models\InvestmentThesis;
use App\Models\MarginTrade;
use App\Models\Stock;
use App\Models\StockValuation;
use App\Models\ThesisStockLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SwingScreenerService
{
    private string $apiKey;
    private string $model;
    private int $maxAiAttempts = 3;

    public function __construct()
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
                'swing_thesis' => $ai['thesis'] ?? $row['thesis'],
                'swing_time_horizon_days' => (int) ($ai['time_horizon_days'] ?? $ai['target_eta_days'] ?? 20),
                'swing_entry_plan' => $ai['entry_plan'] ?? [
                    'entry_price' => $entry,
                    'target_price' => $target,
                    'stop_loss' => $stop,
                    'expected_holding_days' => $ai['expected_holding_days'] ?? null,
                    'target_eta_days' => $ai['target_eta_days'] ?? null,
                    'review_after_days' => $ai['review_after_days'] ?? null,
                ],
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

        return $created->map->fresh(['stock']);
    }

    private function buildCandidatePayload(Stock $stock, string $date, Collection $theses): ?array
    {
        $quotes = DailyQuote::where('stock_id', $stock->id)
            ->where('date', '<=', $date)
            ->orderByDesc('date')
            ->limit(80)
            ->get();
        if ($quotes->count() < 60) {
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
                'score' => min(100, $score),
                'evidence' => array_slice($evidence, 0, 5),
            ];
        })->filter(fn ($link) => $link['score'] >= 20)->values()->all();
    }

    private function askAi(string $date, Collection $rows, Collection $theses): array
    {
        $thesisText = $theses->map(fn ($t) => "- #{$t->id} {$t->title} 信心{$t->confidence_score}: {$t->description}")->implode("\n");
        $candidates = $rows->take(30)->values();
        $stockText = $candidates->map(fn ($r) =>
            "{$r['symbol']} {$r['name']} {$r['industry']} pre_score={$r['pre_score']} close={$r['entry_price']} thesis=" . json_encode($r['thesis'], JSON_UNESCAPED_UNICODE)
        )->implode("\n");
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
   - 90 分以上：最多 2 檔，且 reasoning 必須具體說明為何優於其他候選。
   - 80–89 分：最多 4 檔。
   - 70–79 分：5–8 檔（次選擔當，可作為備援）。
   - 70 分以下：其餘候選依下檔風險、論點關聯度、技術位置高低排序。
3. selected=true 僅給予實質想下單的檔次（8–12 檔），其餘設 false。
4. score 與 selected 必須一致：score < 70 不得 selected=true；score >= 80 應該 selected=true。
5. 同一個 strategy（trend_pullback/trend_follow/base_breakout）內也應該有分數階梯，不要全部一樣分。

請只輸出 JSON 陣列（共 {$totalCount} 筆，與候選清單一一對應）：
{
  "symbol": "2330",
  "selected": true,
  "score": 0到100,
  "strategy": "trend_pullback/trend_follow/base_breakout",
  "reasoning": "理專式判斷一句話 30-60 字（90 分以上必須說明為何優於其他候選）",
  "thesis": {"title": "論點", "chain_position": "位置", "relevance_score": 0到100},
  "entry_price": 100,
  "target_price": 110,
  "stop_loss": 95,
  "time_horizon_days": 20,
  "expected_holding_days": "10-25",
  "target_eta_days": 12,
  "review_after_days": 5,
  "entry_plan": {"expected_holding_days":"10-25","target_eta_days":12,"review_after_days":5},
  "risk_notes": ["風險"]
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
                $selectedCount++;
            }
        }

        if ($selectedCount < 8 || $selectedCount > 15) {
            throw new \RuntimeException("短線 AI 選股 selected 數量異常：{$selectedCount}。");
        }
    }
}

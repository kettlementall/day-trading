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

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key', '');
        $this->model = config('services.anthropic.screening_model', 'claude-opus-4-6');
    }

    public function screen(string $date): Collection
    {
        $theses = InvestmentThesis::where('status', InvestmentThesis::STATUS_ACTIVE)
            ->where('confidence_score', '>=', 40)
            ->orderByDesc('confidence_score')
            ->limit(12)
            ->get();

        $rows = Stock::where('is_day_trading', true)->get()
            ->map(fn (Stock $stock) => $this->buildCandidatePayload($stock, $date, $theses))
            ->filter()
            ->sortByDesc('pre_score')
            ->take(100)
            ->values();

        Candidate::where('trade_date', $date)->where('mode', 'swing')->delete();

        $selected = $this->apiKey ? $this->askAi($date, $rows, $theses) : [];
        $selectedBySymbol = collect($selected)->keyBy('symbol');

        $created = $rows->take(30)->map(function (array $row) use ($date, $selectedBySymbol) {
            $ai = $selectedBySymbol->get($row['symbol'], []);
            $score = max(0, min(100, (float) ($ai['score'] ?? $row['pre_score'])));
            $aiSelected = (bool) ($ai['selected'] ?? $score >= 55);
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
                'ai_reasoning' => $ai['reasoning'] ?? $row['reasoning'],
                'swing_strategy' => $ai['strategy'] ?? $row['strategy'],
                'swing_reasoning' => $ai['reasoning'] ?? $row['reasoning'],
                'swing_thesis' => $ai['thesis'] ?? $row['thesis'],
                'swing_time_horizon_days' => (int) ($ai['time_horizon_days'] ?? 20),
                'swing_entry_plan' => $ai['entry_plan'] ?? [
                    'entry_price' => $entry,
                    'target_price' => $target,
                    'stop_loss' => $stop,
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
        $stockText = $rows->take(40)->map(fn ($r) =>
            "{$r['symbol']} {$r['name']} {$r['industry']} score={$r['pre_score']} close={$r['entry_price']} thesis=" . json_encode($r['thesis'], JSON_UNESCAPED_UNICODE)
        )->implode("\n");

        $prompt = <<<PROMPT
你是穩健型台股理財專員，請從候選股票中挑選適合 1-4 週短線配置的標的。避免追高，重視下檔風險、產業論點、籌碼、技術位置與估值。

日期：{$date}

產業論點：
{$thesisText}

股票候選：
{$stockText}

請只輸出 JSON 陣列，最多 20 筆：
{
  "symbol": "2330",
  "selected": true,
  "score": 0到100,
  "strategy": "trend_pullback/trend_follow/base_breakout",
  "reasoning": "理專式判斷",
  "thesis": {"title": "論點", "chain_position": "位置", "relevance_score": 0到100},
  "entry_price": 100,
  "target_price": 110,
  "stop_loss": 95,
  "time_horizon_days": 20,
  "entry_plan": {},
  "risk_notes": ["風險"]
}
PROMPT;

        try {
            $response = Http::timeout(120)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $this->model,
                    'max_tokens' => 5000,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);

            if (!$response->successful()) {
                Log::error('SwingScreener API error: ' . $response->body());
                return [];
            }

            $text = trim($response->json('content.0.text', ''));
            $text = preg_replace('/^```json?\s*/i', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
            $data = json_decode($text, true);

            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            Log::error('SwingScreener: ' . $e->getMessage());
            return [];
        }
    }
}

<?php

namespace App\Services;

use App\Models\InvestmentThesis;
use App\Models\NewsArticle;
use App\Models\NewsIndex;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InvestmentThesisResearchService
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key', '');
        $this->model = config('services.anthropic.screening_model', 'claude-opus-4-6');
    }

    public function research(string $date): array
    {
        $articles = NewsArticle::where('fetched_date', '>=', now()->parse($date)->subDays(14)->toDateString())
            ->whereNotNull('sentiment_score')
            ->orderByDesc('published_at')
            ->limit(80)
            ->get();

        $indices = NewsIndex::where('date', '<=', $date)
            ->orderByDesc('date')
            ->limit(20)
            ->get();

        $items = $this->apiKey
            ? $this->askAi($date, $articles, $indices)
            : $this->fallbackTheses($articles);

        $saved = 0;
        foreach ($items as $item) {
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $confidence = max(0, min(100, (int) ($item['confidence_score'] ?? 55)));
            $status = $confidence < 35 ? InvestmentThesis::STATUS_INACTIVE : InvestmentThesis::STATUS_ACTIVE;

            $existing = InvestmentThesis::where('title', $title)->first();
            if ($existing?->status === InvestmentThesis::STATUS_DISABLED) {
                continue;
            }

            InvestmentThesis::updateOrCreate(
                ['title' => $title],
                [
                    'description' => (string) ($item['description'] ?? ''),
                    'industry_chain' => $item['industry_chain'] ?? [],
                    'beneficiary_industries' => $item['beneficiary_industries'] ?? [],
                    'beneficiary_keywords' => $item['beneficiary_keywords'] ?? [],
                    'evidence_summary' => (string) ($item['evidence_summary'] ?? ''),
                    'risk_factors' => $item['risk_factors'] ?? [],
                    'sentiment_divergence' => $item['sentiment_divergence'] ?? null,
                    'confidence_score' => $confidence,
                    'status' => $status,
                    'last_evaluated_at' => now(),
                ]
            );
            $saved++;
        }

        $this->decayStaleTheses();

        return ['saved' => $saved, 'input_articles' => $articles->count()];
    }

    private function askAi(string $date, $articles, $indices): array
    {
        $newsLines = $articles->map(fn ($a) =>
            "- [{$a->industry}/{$a->sentiment_label}] {$a->title}" . ($a->summary ? " — " . mb_substr($a->summary, 0, 120) : '')
        )->implode("\n");
        $indexLines = $indices->map(fn ($i) =>
            "{$i->date->format('Y-m-d')} {$i->scope}:{$i->scope_value} 情緒{$i->sentiment} 熱度{$i->heatmap} 恐慌{$i->panic}"
        )->implode("\n");

        $prompt = <<<PROMPT
你是台股短線投資研究員。請根據近期新聞與產業指數，建立或更新可用於 1-4 週短線配置的產業投資論點。

日期：{$date}

新聞：
{$newsLines}

產業/新聞指數：
{$indexLines}

請只輸出 JSON 陣列，最多 8 筆。每筆格式：
{
  "title": "論點名稱",
  "description": "為什麼這個論點成立",
  "industry_chain": ["上游", "中游", "下游"],
  "beneficiary_industries": ["半導體"],
  "beneficiary_keywords": ["HBM", "PCB"],
  "evidence_summary": "新聞與資料佐證",
  "risk_factors": ["風險"],
  "sentiment_divergence": "none/bullish_fundamental_bearish_sentiment/bearish_fundamental_bullish_sentiment",
  "confidence_score": 0到100整數
}
PROMPT;

        try {
            $response = Http::timeout(90)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $this->model,
                    'max_tokens' => 3500,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);

            if (!$response->successful()) {
                Log::error('InvestmentThesisResearch API error: ' . $response->body());
                return $this->fallbackTheses($articles);
            }

            $text = trim($response->json('content.0.text', ''));
            $text = preg_replace('/^```json?\s*/i', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
            $data = json_decode($text, true);

            return is_array($data) ? $data : $this->fallbackTheses($articles);
        } catch (\Throwable $e) {
            Log::error('InvestmentThesisResearch: ' . $e->getMessage());
            return $this->fallbackTheses($articles);
        }
    }

    private function fallbackTheses($articles): array
    {
        $groups = $articles->whereNotNull('industry')->groupBy('industry');
        $items = [];

        foreach ($groups as $industry => $group) {
            if ($group->count() < 3) {
                continue;
            }
            $avg = (float) $group->avg('sentiment_score');
            $items[] = [
                'title' => "{$industry} 短線題材延續",
                'description' => "近期 {$industry} 新聞量與情緒具備短線觀察價值。",
                'industry_chain' => [$industry],
                'beneficiary_industries' => [$industry],
                'beneficiary_keywords' => collect(NewsIndustryMap::INDUSTRIES[$industry] ?? [$industry])->take(8)->values()->all(),
                'evidence_summary' => $group->take(5)->pluck('title')->implode('；'),
                'risk_factors' => ['新聞題材退燒', '股價已提前反映', '法人轉賣'],
                'sentiment_divergence' => $avg < 0 ? 'bearish_fundamental_bullish_sentiment' : 'none',
                'confidence_score' => max(40, min(75, 50 + (int) round($avg / 4) + min(15, $group->count()))),
            ];
        }

        return array_slice($items, 0, 8);
    }

    private function decayStaleTheses(): void
    {
        InvestmentThesis::where('status', '!=', InvestmentThesis::STATUS_DISABLED)
            ->where(function ($q) {
                $q->whereNull('last_evaluated_at')
                    ->orWhere('last_evaluated_at', '<', now()->subDays(3));
            })
            ->get()
            ->each(function (InvestmentThesis $thesis) {
                $next = max(0, $thesis->confidence_score - 10);
                $thesis->update([
                    'confidence_score' => $next,
                    'status' => $next < 35 ? InvestmentThesis::STATUS_INACTIVE : $thesis->status,
                ]);
            });
    }
}

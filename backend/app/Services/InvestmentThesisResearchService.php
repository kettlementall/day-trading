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

# 輸出規範（嚴格遵守）

只輸出 JSON 陣列，最多 8 筆，不要有任何解釋文字、不要包裹 markdown code block。
每筆物件**必須**包含下列所有欄位（欄位名稱完全一致，不可改成 sector/horizon/direction 等別名）：

- `title` (string)：論點名稱，例：「AI 算力基建供應鏈」
- `description` (string)：為什麼這個論點成立的核心敘事（2-4 句）
- `industry_chain` (string[])：上中下游節點，例 ["晶圓代工", "封測", "PCB", "散熱"]
- `beneficiary_industries` (string[])：受惠產業類別（用以對應 stocks.industry 欄位），例 ["半導體", "電子零組件"]
- `beneficiary_keywords` (string[])：股票名稱/個股關鍵字，用以對 Stock.name 字串匹配，例 ["台積電", "TSMC", "日月光", "HBM"]
- `evidence_summary` (string)：佐證新聞重點摘要
- `risk_factors` (string[])：風險清單
- `sentiment_divergence` (string)：必須為這三個值之一 — `none` / `bullish_fundamental_bearish_sentiment` / `bearish_fundamental_bullish_sentiment`
- `confidence_score` (整數，0-100)：信心分。建議分布：> 80 信心極強的最多 2 筆；60-80 主流 4-5 筆；< 60 列觀察。

範例（僅示範格式，請依新聞實際內容產生）：
[
  {
    "title": "AI 算力基建供應鏈",
    "description": "AI server 訂單能見度延伸到 2027 年，HBM、CoWoS、液冷需求同步爆發，台廠在 PCB/CCL/封測有獨家受惠位置。",
    "industry_chain": ["晶圓代工", "HBM", "CoWoS", "PCB", "液冷"],
    "beneficiary_industries": ["半導體", "電子零組件"],
    "beneficiary_keywords": ["台積電", "TSMC", "日月光", "華通", "台光電", "雙鴻"],
    "evidence_summary": "10/30 NVDA 法說上修；華通 11/5 公告 N3 PCB 出貨；台光電 CCL 連 5 月成長",
    "risk_factors": ["美中對 AI 晶片出口管制", "下游雲端資本支出放緩"],
    "sentiment_divergence": "none",
    "confidence_score": 85
  }
]
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
                    'max_tokens' => 6000,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);

            if (!$response->successful()) {
                Log::error('InvestmentThesisResearch API error: ' . $response->body());
                return $this->fallbackTheses($articles);
            }

            $stopReason = $response->json('stop_reason');
            if ($stopReason === 'max_tokens') {
                Log::warning('InvestmentThesisResearch: hit max_tokens limit, response truncated');
            }

            $text = $response->json('content.0.text', '');
            // Robust JSON array extraction: 不論 AI 是否包 markdown / 有前後文字，
            // 只取第一個 [ 到最後一個 ] 之間（過去 preg_replace 會把整段吃掉）。
            $start = strpos($text, '[');
            $end = strrpos($text, ']');
            if ($start === false || $end === false || $end <= $start) {
                Log::warning('InvestmentThesisResearch: no JSON array brackets found, snippet=' . mb_substr(trim($text), 0, 200));
                return $this->fallbackTheses($articles);
            }
            $data = json_decode(substr($text, $start, $end - $start + 1), true);
            if (!is_array($data)) {
                Log::warning('InvestmentThesisResearch: JSON decode failed, snippet=' . mb_substr(trim($text), 0, 200));
                return $this->fallbackTheses($articles);
            }
            $valid = collect($data)->filter(fn ($i) => is_array($i) && !empty($i['title']))->count();
            if ($valid === 0 && count($data) > 0) {
                Log::warning('InvestmentThesisResearch: AI response missing title field, first item keys=' . json_encode(array_keys($data[0] ?? [])));
                return $this->fallbackTheses($articles);
            }
            return $data;
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

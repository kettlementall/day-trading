<?php

namespace App\Services;

use App\Models\NewsArticle;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SentimentAnalyzer
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key', '');
        $this->model = config('services.anthropic.model', 'claude-haiku-4-5-20251001');
    }

    /**
     * 分析單篇新聞的情緒
     */
    public function analyze(NewsArticle $article): array
    {
        if (!$this->apiKey) {
            Log::warning('SentimentAnalyzer: ANTHROPIC_API_KEY 未設定');
            return $this->fallbackAnalysis($article);
        }

        $prompt = $this->buildPrompt($article);

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $this->model,
                    'max_tokens' => 300,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

            if (!$response->successful()) {
                Log::error('SentimentAnalyzer API error: ' . $response->body());
                return $this->fallbackAnalysis($article);
            }

            $text = $response->json('content.0.text', '');
            return $this->parseResponse($text);
        } catch (\Exception $e) {
            Log::error('SentimentAnalyzer: ' . $e->getMessage());
            return $this->fallbackAnalysis($article);
        }
    }

    /**
     * 批次分析（多篇新聞一次送出，節省 token）
     */
    public function analyzeBatch(array $articles): array
    {
        if (!$this->apiKey || empty($articles)) {
            return array_map(fn ($a) => $this->fallbackAnalysis($a), $articles);
        }

        $prompt = $this->buildBatchPrompt($articles);

        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $this->model,
                    'max_tokens' => 2000,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

            if (!$response->successful()) {
                Log::error('SentimentAnalyzer batch API error: ' . $response->body());
                return array_map(fn ($a) => $this->fallbackAnalysis($a), $articles);
            }

            $text = $response->json('content.0.text', '');
            return $this->parseBatchResponse($text, count($articles));
        } catch (\Exception $e) {
            Log::error('SentimentAnalyzer batch: ' . $e->getMessage());
            return array_map(fn ($a) => $this->fallbackAnalysis($a), $articles);
        }
    }

    private function buildPrompt(NewsArticle $article): string
    {
        $title = $article->title;
        $summary = $article->summary ? mb_substr($article->summary, 0, 300) : '';

        return <<<PROMPT
分析以下台灣財經新聞的市場情緒。

標題: {$title}
摘要: {$summary}

請用 JSON 回覆，格式如下（不要加其他文字）：
{
  "sentiment_score": <-100到100的整數，正面為正、負面為負>,
  "sentiment_label": "<positive/negative/neutral>",
  "industries": ["<受影響的產業，從以下選擇: 半導體, AI與雲端, 電子零組件, 面板光電, 通訊網路, 金融, 傳產, 生技醫療, 綠能車用, 地緣政治, 總體經濟>"],
  "impact": "<high/medium/low>",
  "panic_signal": <true/false，是否含有恐慌性字眼>,
  "summary": "<一句話摘要影響>"
}
PROMPT;
    }

    private function buildBatchPrompt(array $articles): string
    {
        $lines = [];
        foreach ($articles as $i => $article) {
            $idx = $i + 1;
            $title = $article->title;
            $summary = $article->summary ? mb_substr($article->summary, 0, 200) : '';
            $lines[] = "[{$idx}] {$title}" . ($summary ? " — {$summary}" : '');
        }
        $newsList = implode("\n", $lines);
        $count = count($articles);

        return <<<PROMPT
分析以下 {$count} 則台灣財經新聞的市場情緒。

{$newsList}

請用 JSON 陣列回覆，每則新聞一個物件，格式如下（不要加其他文字）：
[
  {
    "index": 1,
    "sentiment_score": <-100到100的整數>,
    "sentiment_label": "<positive/negative/neutral>",
    "industries": ["<受影響的產業: 半導體/AI與雲端/電子零組件/面板光電/通訊網路/金融/傳產/生技醫療/綠能車用/地緣政治/總體經濟>"],
    "impact": "<high/medium/low>",
    "panic_signal": <true/false>,
    "summary": "<一句話>"
  }
]
PROMPT;
    }

    private function parseResponse(string $text): array
    {
        $text = trim($text);
        // 移除 markdown code block
        $text = preg_replace('/^```json?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        $data = json_decode($text, true);

        if (!is_array($data) || !isset($data['sentiment_score'])) {
            return [
                'sentiment_score' => 0,
                'sentiment_label' => 'neutral',
                'industries' => [],
                'impact' => 'low',
                'panic_signal' => false,
                'summary' => '',
            ];
        }

        return $data;
    }

    private function parseBatchResponse(string $text, int $expectedCount): array
    {
        $text = trim($text);
        $text = preg_replace('/^```json?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        $data = json_decode($text, true);

        if (!is_array($data)) {
            return array_fill(0, $expectedCount, [
                'sentiment_score' => 0,
                'sentiment_label' => 'neutral',
                'industries' => [],
                'impact' => 'low',
                'panic_signal' => false,
                'summary' => '',
            ]);
        }

        // 補齊不足的
        $results = [];
        for ($i = 0; $i < $expectedCount; $i++) {
            $results[] = $data[$i] ?? [
                'sentiment_score' => 0,
                'sentiment_label' => 'neutral',
                'industries' => [],
                'impact' => 'low',
                'panic_signal' => false,
                'summary' => '',
            ];
        }

        return $results;
    }

    /**
     * 無 API key 時的關鍵字降級分析
     */
    private function fallbackAnalysis(NewsArticle $article): array
    {
        $text = $article->title . ' ' . ($article->summary ?? '');
        $score = 0;

        $positive = ['利多', '看好', '突破', '創新高', '大漲', '上漲', '成長', '獲利', '營收創', '買超', '加碼', '利好'];
        $negative = ['利空', '下跌', '崩', '跌停', '恐慌', '警示', '衰退', '虧損', '拋售', '賣超', '危機', '暴跌', '重挫'];
        $panic = ['崩盤', '恐慌', '暴跌', '股災', '融斷', '黑天鵝'];

        foreach ($positive as $kw) {
            if (mb_strpos($text, $kw) !== false) $score += 20;
        }
        foreach ($negative as $kw) {
            if (mb_strpos($text, $kw) !== false) $score -= 20;
        }

        $hasPanic = false;
        foreach ($panic as $kw) {
            if (mb_strpos($text, $kw) !== false) { $hasPanic = true; break; }
        }

        $score = max(-100, min(100, $score));
        $label = $score > 10 ? 'positive' : ($score < -10 ? 'negative' : 'neutral');

        return [
            'sentiment_score' => $score,
            'sentiment_label' => $label,
            'industries' => array_filter([$article->industry]),
            'impact' => abs($score) > 40 ? 'high' : (abs($score) > 15 ? 'medium' : 'low'),
            'panic_signal' => $hasPanic,
            'summary' => '',
        ];
    }
}

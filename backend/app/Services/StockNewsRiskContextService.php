<?php

namespace App\Services;

use App\Models\NewsArticle;
use App\Models\Stock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class StockNewsRiskContextService
{
    public function build(Stock $stock, string $date, int $days = 5, int $limit = 6): array
    {
        $from = Carbon::parse($date)->subDays($days)->toDateString();
        $to = Carbon::parse($date)->toDateString();

        $symbolClean = preg_replace('/[^0-9A-Za-z]/', '', (string) $stock->symbol);
        // negative lookahead 排除常見「年/月/日/點/萬/億/兆/元」單位，避免 4 碼 symbol 撞年份（如 2027 撞「2027 年」）或撞金額/指數點。
        $symbolRegex = "(^|[^0-9A-Za-z]){$symbolClean}(?!年|月|日|點|萬|億|兆|元)([^0-9A-Za-z]|$)";

        $direct = NewsArticle::whereBetween('fetched_date', [$from, $to])
            ->where(function ($q) use ($stock, $symbolRegex) {
                $q->where('title', 'like', "%{$stock->name}%")
                    ->orWhere('summary', 'like', "%{$stock->name}%")
                    ->orWhere('title', 'regexp', $symbolRegex)
                    ->orWhere('summary', 'regexp', $symbolRegex);
            })
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
        $directIds = $direct->pluck('id')->flip();

        $industryRisk = collect();
        if ($stock->industry) {
            $industryRisk = NewsArticle::whereBetween('fetched_date', [$from, $to])
                ->where('industry', $stock->industry)
                ->orderByDesc('published_at')
                ->limit(20)
                ->get()
                ->filter(fn (NewsArticle $article) =>
                    $article->sentiment_label === 'negative'
                    || (bool) data_get($article->ai_analysis, 'short_term_risk')
                    || !in_array(data_get($article->ai_analysis, 'risk_type', 'none'), [null, '', 'none'], true)
                )
                ->take(3)
                ->values();
        }

        $articles = $direct
            ->merge($industryRisk)
            ->unique('id')
            ->sortBy([
                fn (NewsArticle $article) => $directIds->has($article->id) ? 0 : 1,
                fn (NewsArticle $article) => -1 * ($article->published_at?->timestamp ?? 0),
            ])
            ->take($limit)
            ->values();

        return [
            'has_short_term_risk' => $articles->contains(fn (NewsArticle $article) => (bool) data_get($article->ai_analysis, 'short_term_risk')),
            'risk_type' => $this->primaryRiskType($articles),
            'risk_reason' => $this->primaryRiskReason($articles),
            'articles' => $articles->map(fn (NewsArticle $article) => $this->summarizeArticle($article))->all(),
        ];
    }

    public function toPrompt(array $context): string
    {
        $articles = collect($context['articles'] ?? []);
        if ($articles->isEmpty()) {
            return '（近 5 日無明確個股新聞風險）';
        }

        return $articles->map(function (array $article) {
            $risk = $article['short_term_risk']
                ? "短線風險={$article['risk_type']}：{$article['risk_reason']}"
                : '短線風險=none';

            return sprintf(
                '- [%s] %s（%s, %s）%s',
                $article['published_at'] ?? '-',
                $article['title'],
                $article['sentiment_label'] ?? 'neutral',
                $risk,
                $article['summary'] ? " 摘要：{$article['summary']}" : ''
            );
        })->implode("\n");
    }

    private function summarizeArticle(NewsArticle $article): array
    {
        return [
            'id' => $article->id,
            'published_at' => $article->published_at?->format('m/d H:i'),
            'title' => $article->title,
            'sentiment_label' => $article->sentiment_label,
            'short_term_risk' => (bool) data_get($article->ai_analysis, 'short_term_risk', false),
            'risk_type' => data_get($article->ai_analysis, 'risk_type', 'none') ?: 'none',
            'risk_reason' => data_get($article->ai_analysis, 'risk_reason'),
            'summary' => $this->excerpt(data_get($article->ai_analysis, 'summary') ?: $article->summary ?: $article->content, 120),
        ];
    }

    private function primaryRiskType(Collection $articles): string
    {
        $article = $articles->first(fn (NewsArticle $article) => (bool) data_get($article->ai_analysis, 'short_term_risk'));

        return (string) data_get($article?->ai_analysis, 'risk_type', 'none');
    }

    private function primaryRiskReason(Collection $articles): ?string
    {
        $article = $articles->first(fn (NewsArticle $article) => (bool) data_get($article->ai_analysis, 'short_term_risk'));

        return data_get($article?->ai_analysis, 'risk_reason');
    }

    private function excerpt(?string $text, int $length): ?string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }

        return mb_substr(preg_replace('/\s+/u', ' ', $text), 0, $length);
    }
}

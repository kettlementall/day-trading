<?php

namespace App\Console\Commands;

use App\Models\NewsArticle;
use App\Services\NewsIndustryMap;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchNews extends Command
{
    protected $signature = 'news:fetch {date?}';
    protected $description = '抓取鉅亨新聞並歸類產業';

    private const CNYES_CATEGORIES = [
        'tw_stock' => '台股',
        'wd_stock' => '國際股',
        'forex'    => '外匯',
    ];

    public function handle(): int
    {
        $date = $this->argument('date')
            ? Carbon::parse($this->argument('date'))->toDateString()
            : now()->toDateString();

        $this->info("抓取鉅亨新聞: {$date}");

        $totalCount = 0;

        foreach (self::CNYES_CATEGORIES as $category => $label) {
            $this->info("  鉅亨 {$label} ({$category})");
            $count = $this->fetchCnyesCategory($category, $date);
            $totalCount += $count;
            $this->info("    匯入 {$count} 篇");

            usleep(500_000);
        }

        $backfilled = $this->backfillMissingCnyesContent($date);
        if ($backfilled > 0) {
            $this->info("  回補內文 {$backfilled} 篇");
        }

        $time = now()->format('H:i');
        app(TelegramService::class)->broadcast("✅ *新聞抓取*({$time}) 完成\n📅 {$date} | 共 {$totalCount} 篇", 'system');

        $this->info("新聞抓取完成，共 {$totalCount} 篇");

        return self::SUCCESS;
    }

    private function backfillMissingCnyesContent(string $date): int
    {
        $count = 0;
        NewsArticle::where('source', 'cnyes')
            ->where('fetched_date', $date)
            ->whereIn('category', ['tw_stock', 'international'])
            ->whereNull('content')
            ->whereNotNull('url')
            ->orderByDesc('published_at')
            ->limit(30)
            ->get()
            ->each(function (NewsArticle $article) use (&$count) {
                if (!preg_match('/\/news\/id\/(\d+)/', (string) $article->url, $matches)) {
                    return;
                }

                $content = $this->fetchCnyesContent($matches[1], (string) $article->url);
                usleep(300_000);

                if (!$content) {
                    return;
                }

                $article->fill([
                    'content' => mb_substr($content, 0, 12000),
                    'content_fetched_at' => now(),
                    'sentiment_score' => null,
                    'sentiment_label' => null,
                    'ai_analysis' => null,
                ]);
                $article->save();
                $count++;
            });

        return $count;
    }

    private function fetchCnyesCategory(string $category, string $date): int
    {
        $mappedCategory = $category === 'tw_stock' ? 'tw_stock' : 'international';
        $count = 0;

        foreach ([1, 2] as $page) {
            try {
                $response = Http::timeout(15)
                    ->get("https://api.cnyes.com/media/api/v1/newslist/category/{$category}", [
                        'limit' => 100,
                        'page'  => $page,
                    ]);

                if (!$response->successful()) {
                    $this->warn("    HTTP {$response->status()} (page {$page})");
                    break;
                }

                $items = $response->json('items.data', []);
                if (empty($items)) {
                    break;
                }

                foreach ($items as $item) {
                    $title = trim($item['title'] ?? '');
                    if (!$title) continue;

                    $summary = trim($item['summary'] ?? '');
                    $newsId = $item['newsId'] ?? '';
                    $url = $newsId ? "https://news.cnyes.com/news/id/{$newsId}" : '';
                    $publishedAt = isset($item['publishAt'])
                        ? Carbon::createFromTimestamp($item['publishAt'], 'Asia/Taipei')
                        : null;
                    if ($publishedAt && $publishedAt->toDateString() > $date) {
                        continue;
                    }
                    $content = null;
                    if ($newsId && in_array($category, ['tw_stock', 'wd_stock'], true)) {
                        $content = $this->fetchCnyesContent((string) $newsId, $url);
                        usleep(200_000);
                    }

                    $fullText = $title . ' ' . $summary . ' ' . ($content ?? '');
                    $industry = NewsIndustryMap::classify($fullText);

                    $article = NewsArticle::firstOrNew([
                        'source' => 'cnyes',
                        'title' => mb_substr($title, 0, 500),
                        'fetched_date' => $date,
                    ]);
                    $updates = [
                        'summary' => mb_substr($summary, 0, 2000),
                        'url' => mb_substr($url, 0, 1000),
                        'category' => $mappedCategory,
                        'industry' => $industry,
                        'published_at' => $publishedAt,
                    ];
                    $newContent = $content ? mb_substr($content, 0, 12000) : null;
                    if ($newContent) {
                        $updates['content'] = $newContent;
                        $updates['content_fetched_at'] = now();
                    }
                    if (!$article->exists || ($newContent && !$article->content)) {
                        $updates['sentiment_score'] = null;
                        $updates['sentiment_label'] = null;
                        $updates['ai_analysis'] = null;
                    }
                    $article->fill($updates);
                    $article->save();
                    $count++;
                }

                usleep(300_000); // 兩頁之間稍等
            } catch (\Exception $e) {
                Log::error("FetchNews cnyes/{$category} page {$page}: " . $e->getMessage());
                $this->error("    錯誤: " . $e->getMessage());
                break;
            }
        }

        return $count;
    }

    private function fetchCnyesContent(string $newsId, string $url): ?string
    {
        try {
            $response = Http::timeout(15)->get("https://api.cnyes.com/media/api/v1/news/{$newsId}");
            if ($response->successful()) {
                $content = $response->json('items.content')
                    ?? $response->json('items.data.content')
                    ?? $response->json('content');
                $text = $this->cleanContent((string) $content);
                if ($text !== '') {
                    return $text;
                }
            }
        } catch (\Exception $e) {
            Log::warning("FetchNews cnyes detail {$newsId}: " . $e->getMessage());
        }

        if ($url === '') {
            return null;
        }

        try {
            $response = Http::timeout(15)->get($url);
            if (!$response->successful()) {
                return null;
            }

            $html = $response->body();
            $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html);
            $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html);
            $text = $this->cleanContent(strip_tags($html));

            return $text !== '' ? $text : null;
        } catch (\Exception $e) {
            Log::warning("FetchNews cnyes html {$newsId}: " . $e->getMessage());
            return null;
        }
    }

    private function cleanContent(string $content): string
    {
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = preg_replace('/\s+/u', ' ', $content);

        return trim((string) $content);
    }
}

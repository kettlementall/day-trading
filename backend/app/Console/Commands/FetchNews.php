<?php

namespace App\Console\Commands;

use App\Models\NewsArticle;
use App\Services\NewsIndustryMap;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchNews extends Command
{
    protected $signature = 'news:fetch {date?}';
    protected $description = '抓取 RSS 新聞並歸類產業';

    public function handle(): int
    {
        $date = $this->argument('date')
            ? Carbon::parse($this->argument('date'))->toDateString()
            : now()->toDateString();

        $this->info("抓取新聞: {$date}");

        $totalCount = 0;

        foreach (NewsIndustryMap::RSS_FEEDS as $feed) {
            $this->info("  來源: {$feed['name']}");
            $count = $this->fetchFeed($feed, $date);
            $totalCount += $count;
            $this->info("    匯入 {$count} 篇");

            // 避免請求過快
            usleep(500_000);
        }

        // Google News 按產業關鍵字搜尋
        $googleCount = $this->fetchGoogleNews($date);
        $totalCount += $googleCount;

        $this->info("新聞抓取完成，共 {$totalCount} 篇");

        return self::SUCCESS;
    }

    private function fetchFeed(array $feed, string $date): int
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->get($feed['url']);

            if (!$response->successful()) {
                $this->warn("    HTTP {$response->status()}");
                return 0;
            }

            $xml = @simplexml_load_string($response->body());
            if (!$xml) {
                $this->warn("    XML 解析失敗");
                return 0;
            }

            $count = 0;
            $items = $xml->channel->item ?? $xml->entry ?? [];

            foreach ($items as $item) {
                $title = trim((string) ($item->title ?? ''));
                if (!$title) continue;

                $link = trim((string) ($item->link ?? $item->guid ?? ''));
                $desc = strip_tags(trim((string) ($item->description ?? $item->summary ?? '')));
                $pubDate = (string) ($item->pubDate ?? $item->published ?? '');

                $publishedAt = null;
                if ($pubDate) {
                    try {
                        $publishedAt = Carbon::parse($pubDate);
                    } catch (\Exception $e) {
                        $publishedAt = null;
                    }
                }

                $fullText = $title . ' ' . $desc;
                $industry = NewsIndustryMap::classify($fullText);

                NewsArticle::updateOrCreate(
                    ['source' => $feed['source'], 'title' => mb_substr($title, 0, 500), 'fetched_date' => $date],
                    [
                        'summary' => mb_substr($desc, 0, 2000),
                        'url' => mb_substr($link, 0, 1000),
                        'category' => $feed['category'],
                        'industry' => $industry,
                        'published_at' => $publishedAt,
                    ]
                );
                $count++;

                if ($count >= 50) break; // 每個來源最多50篇
            }

            return $count;
        } catch (\Exception $e) {
            Log::error("FetchNews {$feed['name']}: " . $e->getMessage());
            $this->error("    錯誤: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * 用 Google News RSS 搜尋各產業關鍵字
     */
    private function fetchGoogleNews(string $date): int
    {
        $searchTerms = [
            // 中文
            '台股' => ['category' => 'tw_stock', 'hl' => 'zh-TW', 'gl' => 'TW', 'ceid' => 'TW:zh-Hant'],
            '美股+台灣' => ['category' => 'international', 'hl' => 'zh-TW', 'gl' => 'TW', 'ceid' => 'TW:zh-Hant'],
            '半導體+台灣' => ['category' => 'industry', 'hl' => 'zh-TW', 'gl' => 'TW', 'ceid' => 'TW:zh-Hant'],
            'AI+科技+台灣' => ['category' => 'industry', 'hl' => 'zh-TW', 'gl' => 'TW', 'ceid' => 'TW:zh-Hant'],
            '金融+台灣+股市' => ['category' => 'industry', 'hl' => 'zh-TW', 'gl' => 'TW', 'ceid' => 'TW:zh-Hant'],
            // 英文國際
            'US stock market today' => ['category' => 'international', 'hl' => 'en', 'gl' => 'US', 'ceid' => 'US:en'],
            'semiconductor TSMC Nvidia' => ['category' => 'international', 'hl' => 'en', 'gl' => 'US', 'ceid' => 'US:en'],
            'Federal Reserve interest rate' => ['category' => 'international', 'hl' => 'en', 'gl' => 'US', 'ceid' => 'US:en'],
            'Asia stock market Taiwan' => ['category' => 'international', 'hl' => 'en', 'gl' => 'US', 'ceid' => 'US:en'],
            'AI technology stocks' => ['category' => 'international', 'hl' => 'en', 'gl' => 'US', 'ceid' => 'US:en'],
        ];

        $total = 0;

        foreach ($searchTerms as $query => $config) {
            $category = $config['category'];
            $this->info("  Google News: {$query}");
            $encoded = urlencode($query);
            $url = "https://news.google.com/rss/search?q={$encoded}&hl={$config['hl']}&gl={$config['gl']}&ceid={$config['ceid']}";

            try {
                $response = Http::timeout(15)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                    ->get($url);

                if (!$response->successful()) continue;

                $xml = @simplexml_load_string($response->body());
                if (!$xml) continue;

                $count = 0;
                foreach ($xml->channel->item ?? [] as $item) {
                    $title = trim((string) ($item->title ?? ''));
                    if (!$title) continue;

                    $link = trim((string) ($item->link ?? ''));
                    $desc = strip_tags(trim((string) ($item->description ?? '')));
                    $pubDate = (string) ($item->pubDate ?? '');

                    $publishedAt = null;
                    if ($pubDate) {
                        try { $publishedAt = Carbon::parse($pubDate); } catch (\Exception $e) {}
                    }

                    $fullText = $title . ' ' . $desc;
                    $industry = NewsIndustryMap::classify($fullText);

                    NewsArticle::updateOrCreate(
                        ['source' => 'google', 'title' => mb_substr($title, 0, 500), 'fetched_date' => $date],
                        [
                            'summary' => mb_substr($desc, 0, 2000),
                            'url' => mb_substr($link, 0, 1000),
                            'category' => $category,
                            'industry' => $industry,
                            'published_at' => $publishedAt,
                        ]
                    );
                    $count++;
                    if ($count >= 20) break;
                }

                $total += $count;
                $this->info("    匯入 {$count} 篇");
                usleep(500_000);
            } catch (\Exception $e) {
                Log::error("FetchNews google/{$query}: " . $e->getMessage());
            }
        }

        return $total;
    }
}

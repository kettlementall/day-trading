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

        $time = now()->format('H:i');
        app(TelegramService::class)->send("✅ *新聞抓取*({$time}) 完成\n📅 {$date} | 共 {$totalCount} 篇");

        $this->info("新聞抓取完成，共 {$totalCount} 篇");

        return self::SUCCESS;
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

                    $fullText = $title . ' ' . $summary;
                    $industry = NewsIndustryMap::classify($fullText);

                    NewsArticle::updateOrCreate(
                        ['source' => 'cnyes', 'title' => mb_substr($title, 0, 500), 'fetched_date' => $date],
                        [
                            'summary' => mb_substr($summary, 0, 2000),
                            'url' => mb_substr($url, 0, 1000),
                            'category' => $mappedCategory,
                            'industry' => $industry,
                            'published_at' => $publishedAt,
                        ]
                    );
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
}

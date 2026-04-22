<?php

namespace App\Console\Commands;

use App\Models\NewsArticle;
use App\Models\NewsIndex;
use App\Services\SentimentAnalyzer;
use App\Services\TelegramService;
use Illuminate\Console\Command;

class ComputeNewsIndices extends Command
{
    protected $signature = 'news:compute-indices {date?}';
    protected $description = '分析新聞情緒並計算各項指數';

    public function handle(): int
    {
        $date = $this->argument('date') ?? now()->toDateString();
        $this->info("計算新聞指數: {$date}");

        // 1. 先跑情緒分析（尚未分析的新聞）
        $this->analyzeArticles($date);

        // 2. 計算總體指數
        $this->computeOverall($date);

        // 3. 計算各產業指數
        $this->computeByIndustry($date);

        // 發送通知
        $overall = NewsIndex::where('date', $date)->where('scope', 'overall')->first();
        $industryCount = NewsIndex::where('date', $date)->where('scope', 'industry')->count();
        $time = now()->format('H:i');

        if ($overall) {
            app(TelegramService::class)->send(sprintf(
                "✅ *新聞指數*(%s) 完成\n📅 %s | %d 篇 · %d 產業\n情緒 %.0f | 熱度 %.0f | 恐慌 %.0f | 國際 %.0f",
                $time, $date, $overall->article_count, $industryCount,
                $overall->sentiment, $overall->heatmap, $overall->panic, $overall->international
            ));
        }

        $this->info('新聞指數計算完成');
        return self::SUCCESS;
    }

    private function analyzeArticles(string $date): void
    {
        $unanalyzed = NewsArticle::where('fetched_date', $date)
            ->whereNull('sentiment_score')
            ->get();

        if ($unanalyzed->isEmpty()) {
            $this->info("  所有新聞已分析完畢");
            return;
        }

        $this->info("  待分析 {$unanalyzed->count()} 篇新聞");

        $analyzer = new SentimentAnalyzer();

        // 每 10 篇一批送出
        foreach ($unanalyzed->chunk(10) as $chunk) {
            $articles = $chunk->values()->all();
            $results = $analyzer->analyzeBatch($articles);

            foreach ($articles as $i => $article) {
                $result = $results[$i] ?? [];

                // 如果 Claude 回傳了更精確的產業分類，更新
                $industries = $result['industries'] ?? [];
                $industry = $article->industry;
                if (!$industry && !empty($industries)) {
                    $industry = $industries[0];
                }

                $article->update([
                    'sentiment_score' => $result['sentiment_score'] ?? 0,
                    'sentiment_label' => $result['sentiment_label'] ?? 'neutral',
                    'industry' => $industry,
                    'ai_analysis' => $result,
                ]);
            }

            $this->info("    已分析 {$chunk->count()} 篇");
            usleep(300_000); // rate limit
        }
    }

    private function computeOverall(string $date): void
    {
        $articles = NewsArticle::where('fetched_date', $date)
            ->whereNotNull('sentiment_score')
            ->get();

        if ($articles->isEmpty()) {
            $this->warn("  無已分析新聞，跳過總體指數");
            return;
        }

        $sentiment = $this->scoreToIndex($articles->avg('sentiment_score'));
        $heatmap = $this->heatIndex($articles->count(), $date);
        $panic = $this->panicIndex($articles);
        $international = $this->internationalIndex($articles);

        NewsIndex::updateOrCreate(
            ['date' => $date, 'scope' => 'overall', 'scope_value' => null],
            [
                'sentiment' => $sentiment,
                'heatmap' => $heatmap,
                'panic' => $panic,
                'international' => $international,
                'article_count' => $articles->count(),
            ]
        );

        $this->info("  總體: 情緒={$sentiment} 熱度={$heatmap} 恐慌={$panic} 國際={$international} ({$articles->count()}篇)");
    }

    private function computeByIndustry(string $date): void
    {
        $articles = NewsArticle::where('fetched_date', $date)
            ->whereNotNull('sentiment_score')
            ->whereNotNull('industry')
            ->get();

        $grouped = $articles->groupBy('industry');

        foreach ($grouped as $industry => $group) {
            $sentiment = $this->scoreToIndex($group->avg('sentiment_score'));
            $heatmap = $this->heatIndex($group->count(), $date, $industry);
            $panic = $this->panicIndex($group);

            NewsIndex::updateOrCreate(
                ['date' => $date, 'scope' => 'industry', 'scope_value' => $industry],
                [
                    'sentiment' => $sentiment,
                    'heatmap' => $heatmap,
                    'panic' => $panic,
                    'international' => 50, // 產業層級不算國際風向
                    'article_count' => $group->count(),
                ]
            );

            $this->info("  {$industry}: 情緒={$sentiment} 熱度={$heatmap} ({$group->count()}篇)");
        }
    }

    /**
     * 將 -100~100 的情緒分數轉為 0~100 指數
     */
    private function scoreToIndex(float $avg): float
    {
        return round(($avg + 100) / 2, 2);
    }

    /**
     * 熱度指數：今日新聞量 vs 近7日均量
     */
    private function heatIndex(int $todayCount, string $date, ?string $industry = null): float
    {
        $query = NewsArticle::where('fetched_date', '<', $date)
            ->where('fetched_date', '>=', now()->parse($date)->subDays(7)->toDateString());

        if ($industry) {
            $query->where('industry', $industry);
        }

        $avgCount = $query->count() / 7;

        if ($avgCount <= 0) return 50;

        $ratio = $todayCount / $avgCount;
        // ratio 1.0 = 50, ratio 2.0 = 75, ratio 0.5 = 25
        $index = 50 + ($ratio - 1) * 25;
        return round(max(0, min(100, $index)), 2);
    }

    /**
     * 恐慌指數：負面高影響新聞 + panic_signal 的比例
     */
    private function panicIndex($articles): float
    {
        if ($articles->isEmpty()) return 50;

        $panicCount = $articles->filter(function ($a) {
            $analysis = $a->ai_analysis ?? [];
            $isPanic = $analysis['panic_signal'] ?? false;
            $isHighNeg = ($a->sentiment_score ?? 0) < -40 && ($analysis['impact'] ?? '') === 'high';
            return $isPanic || $isHighNeg;
        })->count();

        $ratio = $panicCount / $articles->count();
        // 0% = 0 (無恐慌), 50%以上 = 100 (極度恐慌)
        $index = min(100, $ratio * 200);
        return round($index, 2);
    }

    /**
     * 國際風向：國際新聞的情緒
     */
    private function internationalIndex($articles): float
    {
        $intl = $articles->filter(fn ($a) => $a->category === 'international');

        if ($intl->isEmpty()) return 50;

        return $this->scoreToIndex($intl->avg('sentiment_score'));
    }
}

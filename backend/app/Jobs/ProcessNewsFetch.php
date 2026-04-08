<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessNewsFetch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public string $date,
    ) {}

    public function handle(): void
    {
        $cacheKey = "news_fetch_status:{$this->date}";

        Cache::put($cacheKey, ['status' => 'running', 'steps' => [], 'progress' => '抓取新聞中...'], 600);

        $steps = [];

        // Step 1: 抓新聞
        try {
            Artisan::call('news:fetch', ['date' => $this->date]);
            $steps[] = ['step' => '抓取新聞', 'success' => true];
        } catch (\Throwable $e) {
            Log::error("News fetch failed: " . $e->getMessage());
            $steps[] = ['step' => '抓取新聞', 'success' => false];
        }

        Cache::put($cacheKey, ['status' => 'running', 'steps' => $steps, 'progress' => '分析新聞中...'], 600);

        // Step 2: 分析 + 計算指數
        try {
            Artisan::call('news:compute-indices', ['date' => $this->date]);
            $steps[] = ['step' => '分析與計算指數', 'success' => true];
        } catch (\Throwable $e) {
            Log::error("News compute failed: " . $e->getMessage());
            $steps[] = ['step' => '分析與計算指數', 'success' => false];
        }

        $allSuccess = collect($steps)->every('success');

        Cache::put($cacheKey, [
            'status' => 'done',
            'success' => $allSuccess,
            'steps' => $steps,
        ], 600);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessNewsFetch;
use App\Models\NewsArticle;
use App\Models\NewsIndex;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class NewsController extends Controller
{
    /**
     * 取得儀表板資料（指數 + 產業排行 + 新聞列表）
     */
    public function dashboard(Request $request): JsonResponse
    {
        $date = $request->get('date', now()->toDateString());

        $overall = NewsIndex::where('date', $date)
            ->where('scope', 'overall')
            ->first();

        $industries = NewsIndex::where('date', $date)
            ->where('scope', 'industry')
            ->orderByDesc('sentiment')
            ->get();

        $articles = NewsArticle::where('fetched_date', $date)
            ->whereNotNull('sentiment_score')
            ->orderByDesc('published_at')
            ->get([
                'id', 'source', 'title', 'url', 'category', 'industry',
                'sentiment_score', 'sentiment_label', 'ai_analysis',
                'published_at',
            ]);

        $dates = NewsIndex::where('scope', 'overall')
            ->orderByDesc('date')
            ->limit(30)
            ->pluck('date');

        $lastFetchedAt = NewsArticle::where('fetched_date', $date)
            ->whereNotNull('sentiment_score')
            ->max('updated_at');

        return response()->json([
            'date' => $date,
            'overall' => $overall,
            'industries' => $industries,
            'articles' => $articles,
            'available_dates' => $dates,
            'last_fetched_at' => $lastFetchedAt,
        ]);
    }

    /**
     * 手動觸發：派發非同步 job 抓取 + 分析
     */
    public function fetch(Request $request): JsonResponse
    {
        $date = $request->get('date', now()->toDateString());
        $cacheKey = "news_fetch_status:{$date}";

        // 如果已在執行中，不重複派發
        $current = Cache::get($cacheKey);
        if ($current && $current['status'] === 'running') {
            return response()->json([
                'queued' => true,
                'message' => '正在處理中，請稍候...',
            ]);
        }

        Cache::put($cacheKey, ['status' => 'running', 'steps' => [], 'progress' => '排隊中...'], 600);

        ProcessNewsFetch::dispatch($date);

        return response()->json([
            'queued' => true,
            'message' => '已開始處理，請稍候查詢進度',
        ]);
    }

    /**
     * 查詢抓取進度
     */
    public function fetchStatus(Request $request): JsonResponse
    {
        $date = $request->get('date', now()->toDateString());
        $cacheKey = "news_fetch_status:{$date}";

        $status = Cache::get($cacheKey, ['status' => 'idle']);

        return response()->json($status);
    }
}

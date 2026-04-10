<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BacktestRound;
use App\Services\BacktestOptimizer;
use App\Services\BacktestService;
use App\Services\DailyReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BacktestController extends Controller
{
    public function rounds(): JsonResponse
    {
        $rounds = BacktestRound::orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json($rounds);
    }

    public function optimize(Request $request): JsonResponse
    {
        $from = $request->input('from', now()->subDays(30)->format('Y-m-d'));
        $to = $request->input('to', now()->format('Y-m-d'));

        $optimizer = new BacktestOptimizer();
        $round = $optimizer->analyze($from, $to);

        return response()->json($round);
    }

    public function apply(BacktestRound $round): JsonResponse
    {
        if ($round->applied) {
            return response()->json(['message' => '此回測建議已套用過'], 422);
        }

        $optimizer = new BacktestOptimizer();
        $optimizer->applyRound($round);

        return response()->json($round->fresh());
    }

    /**
     * 帶驗證的優化循環（SSE 串流回傳進度）
     */
    public function optimizeValidated(Request $request): StreamedResponse
    {
        $from = $request->input('from', now()->subDays(60)->format('Y-m-d'));
        $to = $request->input('to', now()->format('Y-m-d'));
        $maxAttempts = (int) $request->input('max_attempts', 10);

        return response()->stream(function () use ($from, $to, $maxAttempts) {
            // 關閉輸出緩衝
            while (ob_get_level()) ob_end_clean();

            $sendEvent = function (string $event, array $data) {
                echo "event: {$event}\n";
                echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
                flush();
            };

            $optimizer = new BacktestOptimizer();

            $result = $optimizer->optimizeWithValidation(
                $from,
                $to,
                $maxAttempts,
                function (string $msg) use ($sendEvent) {
                    $sendEvent('log', ['message' => $msg]);
                }
            );

            $sendEvent('done', $result);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * 單日候選標的 AI 檢討分析（SSE 串流）
     */
    public function dailyReview(Request $request): StreamedResponse
    {
        $date = $request->input('date', now()->toDateString());

        return response()->stream(function () use ($date) {
            while (ob_get_level()) ob_end_clean();

            $sendEvent = function (string $event, array $data) {
                echo "event: {$event}\n";
                echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
                flush();
            };

            $service = new DailyReviewService();

            $result = $service->review(
                $date,
                function (string $msg) use ($sendEvent) {
                    $sendEvent('log', ['message' => $msg]);
                },
                function (string $chunk) use ($sendEvent) {
                    $sendEvent('chunk', ['text' => $chunk]);
                }
            );

            $sendEvent('done', $result);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}

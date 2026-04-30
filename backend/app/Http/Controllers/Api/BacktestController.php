<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BacktestRound;
use App\Models\DailyReview;
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
     * 取得已存的檢討報告
     */
    public function dailyReviewShow(Request $request): JsonResponse
    {
        $date = $request->input('date', now()->subDay()->toDateString());
        $mode = $request->input('mode', 'intraday');

        $review = DailyReview::where('trade_date', $date)->where('mode', $mode)->first();

        if (!$review) {
            return response()->json(['exists' => false]);
        }

        return response()->json([
            'exists'           => true,
            'date'             => $review->trade_date->format('Y-m-d'),
            'mode'             => $review->mode,
            'candidates_count' => $review->candidates_count,
            'report'           => $review->report,
        ]);
    }

    /**
     * 取得所有有報告的日期列表
     */
    public function dailyReviewDates(Request $request): JsonResponse
    {
        $mode = $request->input('mode', 'intraday');

        $dates = DailyReview::where('mode', $mode)
            ->orderByDesc('trade_date')
            ->pluck('trade_date')
            ->map(fn($d) => $d->format('Y-m-d'));

        return response()->json($dates);
    }

    /**
     * 明牌分析：使用者輸入賺錢明牌 → AI 從數值找理由 → 存成高優先教訓（SSE 串流）
     */
    public function analyzeTip(Request $request): StreamedResponse
    {
        $date    = $request->input('date', now()->toDateString());
        $symbol  = strtoupper(trim($request->input('symbol', '')));
        $notes   = $request->input('notes') ?? '';
        $mode    = $request->input('mode', 'intraday');
        $outcome = $request->input('outcome', 'win'); // win | loss

        return response()->stream(function () use ($date, $symbol, $notes, $mode, $outcome) {
            while (ob_get_level()) ob_end_clean();

            $sendEvent = function (string $event, array $data) {
                echo "event: {$event}\n";
                echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
                flush();
            };

            if (!$symbol) {
                $sendEvent('done', ['error' => '請輸入股票代號']);
                return;
            }

            $service = new DailyReviewService();

            $result = $service->analyzeTip(
                $date,
                $symbol,
                $notes,
                function (string $msg) use ($sendEvent) {
                    $sendEvent('log', ['message' => $msg]);
                },
                function (string $chunk) use ($sendEvent) {
                    $sendEvent('chunk', ['text' => $chunk]);
                },
                $mode,
                $outcome
            );

            $sendEvent('done', $result);
        }, 200, [
            'Content-Type'    => 'text/event-stream',
            'Cache-Control'   => 'no-cache',
            'Connection'      => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * 單日候選標的 AI 檢討分析（SSE 串流）
     */
    public function dailyReview(Request $request): StreamedResponse
    {
        $date = $request->input('date', now()->toDateString());
        $mode = $request->input('mode', 'intraday');

        return response()->stream(function () use ($date, $mode) {
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
                },
                $mode
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

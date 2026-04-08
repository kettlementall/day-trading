<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Services\BacktestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CandidateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $date = $request->get('date', now()->toDateString());

        $candidates = Candidate::with(['stock', 'result'])
            ->where('trade_date', $date)
            ->orderByDesc('score')
            ->get();

        $lastUpdatedAt = Candidate::where('trade_date', $date)->max('updated_at');

        return response()->json([
            'date' => $date,
            'count' => $candidates->count(),
            'data' => $candidates,
            'last_updated_at' => $lastUpdatedAt,
        ]);
    }

    public function show(Candidate $candidate): JsonResponse
    {
        $candidate->load(['stock', 'result']);

        return response()->json($candidate);
    }

    public function dates(): JsonResponse
    {
        $dates = Candidate::selectRaw('trade_date, COUNT(*) as count')
            ->groupBy('trade_date')
            ->orderByDesc('trade_date')
            ->limit(30)
            ->get();

        return response()->json($dates);
    }

    /**
     * 取得盤前確認結果（當日候選標的 + 盤中即時信號）
     */
    public function morning(Request $request): JsonResponse
    {
        $date = $request->get('date', now()->toDateString());

        $candidates = Candidate::with(['stock', 'result'])
            ->where('trade_date', $date)
            ->orderByDesc('morning_confirmed')
            ->orderByDesc('morning_score')
            ->orderByDesc('score')
            ->get();

        $confirmed = $candidates->where('morning_confirmed', true)->count();

        return response()->json([
            'date' => $date,
            'total' => $candidates->count(),
            'confirmed' => $confirmed,
            'data' => $candidates,
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $days = $request->get('days', 30);
        $from = now()->subDays($days)->toDateString();
        $to = now()->toDateString();

        $service = new BacktestService();
        $metrics = $service->computeMetrics($from, $to);
        $metrics['period_days'] = $days;

        return response()->json($metrics);
    }
}

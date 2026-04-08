<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
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
        $since = now()->subDays($days)->toDateString();

        $total = Candidate::where('trade_date', '>=', $since)->count();
        $withResult = Candidate::where('trade_date', '>=', $since)
            ->whereHas('result')
            ->count();
        $hitTarget = Candidate::where('trade_date', '>=', $since)
            ->whereHas('result', fn ($q) => $q->where('hit_target', true))
            ->count();

        $hitRate = $withResult > 0 ? round($hitTarget / $withResult * 100, 1) : 0;

        $avgProfit = Candidate::where('trade_date', '>=', $since)
            ->whereHas('result')
            ->with('result')
            ->get()
            ->avg(fn ($c) => $c->result->max_profit_percent);

        return response()->json([
            'period_days' => $days,
            'total_candidates' => $total,
            'evaluated' => $withResult,
            'hit_target' => $hitTarget,
            'hit_rate' => $hitRate,
            'avg_max_profit' => round($avgProfit ?? 0, 2),
        ]);
    }
}

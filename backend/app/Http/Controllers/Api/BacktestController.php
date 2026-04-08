<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BacktestRound;
use App\Services\BacktestOptimizer;
use App\Services\BacktestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}

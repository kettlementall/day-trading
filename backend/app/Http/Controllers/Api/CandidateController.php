<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\CandidateMonitor;
use App\Models\IntradaySnapshot;
use App\Models\MarketHoliday;
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
            ->orderByDesc('ai_selected')
            ->orderByDesc('score')
            ->get();

        $lastUpdatedAt = Candidate::where('trade_date', $date)->max('updated_at');

        $holiday = MarketHoliday::where('date', $date)->first();
        $isHoliday = MarketHoliday::isHoliday($date);

        return response()->json([
            'date' => $date,
            'count' => $candidates->count(),
            'data' => $candidates,
            'last_updated_at' => $lastUpdatedAt,
            'is_holiday' => $isHoliday,
            'holiday_name' => $isHoliday
                ? ($holiday?->name ?? (\Carbon\Carbon::parse($date)->isWeekend() ? '週末' : null))
                : null,
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

    /**
     * 取得候選標的的盤中快照
     */
    public function snapshots(Candidate $candidate): JsonResponse
    {
        $snapshots = IntradaySnapshot::where('stock_id', $candidate->stock_id)
            ->where('trade_date', $candidate->trade_date)
            ->orderBy('snapshot_time')
            ->get();

        return response()->json([
            'candidate_id' => $candidate->id,
            'stock_id' => $candidate->stock_id,
            'trade_date' => $candidate->trade_date->format('Y-m-d'),
            'count' => $snapshots->count(),
            'data' => $snapshots,
        ]);
    }

    /**
     * 取得當日所有 monitor 狀態
     */
    public function monitors(Request $request): JsonResponse
    {
        $date = $request->get('date', now()->toDateString());

        $monitors = CandidateMonitor::with(['candidate.stock'])
            ->whereHas('candidate', fn($q) => $q->where('trade_date', $date))
            ->get()
            ->map(function ($monitor) {
                $candidate = $monitor->candidate;
                $stock = $candidate->stock;

                // 取最新快照
                $latestSnapshot = IntradaySnapshot::where('stock_id', $stock->id)
                    ->where('trade_date', $candidate->trade_date)
                    ->orderByDesc('snapshot_time')
                    ->first();

                $currentPrice = $latestSnapshot ? (float) $latestSnapshot->current_price : null;
                $profitPct = null;
                if ($currentPrice && $monitor->entry_price && (float) $monitor->entry_price > 0) {
                    $profitPct = round(($currentPrice - (float) $monitor->entry_price) / (float) $monitor->entry_price * 100, 2);
                }

                return [
                    'id' => $monitor->id,
                    'candidate_id' => $candidate->id,
                    'symbol' => $stock->symbol,
                    'name' => $stock->name,
                    'status' => $monitor->status,
                    'strategy' => $candidate->intraday_strategy,
                    'entry_price' => $monitor->entry_price,
                    'entry_time' => $monitor->entry_time?->format('H:i'),
                    'exit_price' => $monitor->exit_price,
                    'exit_time' => $monitor->exit_time?->format('H:i'),
                    'current_target' => $monitor->current_target,
                    'current_stop' => $monitor->current_stop,
                    'current_price' => $currentPrice,
                    'profit_pct' => $profitPct,
                    'skip_reason' => $monitor->skip_reason,
                    'last_ai_advice' => $monitor->ai_advice_log
                        ? collect($monitor->ai_advice_log)->last()
                        : null,
                    'updated_at' => $monitor->updated_at,
                ];
            });

        return response()->json([
            'date' => $date,
            'count' => $monitors->count(),
            'active' => $monitors->whereIn('status', CandidateMonitor::ACTIVE_STATUSES)->count(),
            'data' => $monitors->values(),
        ]);
    }

    /**
     * 取得單檔 monitor 詳細（含完整 state_log + ai_advice_log）
     */
    public function monitor(Candidate $candidate): JsonResponse
    {
        $monitor = $candidate->monitor;

        if (!$monitor) {
            return response()->json(['error' => '此候選標的尚無監控紀錄'], 404);
        }

        $monitor->load('candidate.stock');

        return response()->json($monitor);
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

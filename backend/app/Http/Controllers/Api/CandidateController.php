<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\CandidateMonitor;
use App\Models\IntradaySnapshot;
use App\Models\MarketHoliday;
use App\Models\UsMarketIndex;
use App\Services\BacktestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CandidateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $date = $request->get('date', now()->toDateString());
        $mode = $request->get('mode', 'intraday');

        if (!in_array($mode, ['intraday', 'overnight'])) {
            $mode = 'intraday';
        }

        $relations = ['stock', 'result', 'monitor'];

        $query = Candidate::with($relations)
            ->where('trade_date', $date)
            ->where('mode', $mode);

        // 隔日沖：只顯示 Opus 已審核過的標的（ai_reasoning 有內容代表 Opus 留過評語）
        // ai_selected 預設值為 false，無法靠它判斷是否有被 Opus 看過
        if ($mode === 'overnight') {
            $query->where('ai_reasoning', '!=', '')->whereNotNull('ai_reasoning');
        }

        // viewer 只看 AI 選中的標的（不顯示被排除的卡片）
        if (! $request->user()?->isAdmin()) {
            $query->where('ai_selected', true);
        }

        $candidates = $query
            ->orderByDesc('ai_selected')
            ->orderByDesc('score')
            ->get();

        $lastUpdatedAt = Candidate::where('trade_date', $date)->max('updated_at');

        $holiday = MarketHoliday::where('date', $date)->first();
        $isHoliday = MarketHoliday::isHoliday($date);

        $usIndices = UsMarketIndex::where('date', $date)->get()->map(fn($i) => [
            'symbol' => $i->symbol,
            'name' => $i->name,
            'close' => (float) $i->close,
            'change_percent' => (float) $i->change_percent,
        ]);

        return response()->json([
            'date' => $date,
            'mode' => $mode,
            'count' => $candidates->count(),
            'data' => $candidates,
            'last_updated_at' => $lastUpdatedAt,
            'is_holiday' => $isHoliday,
            'holiday_name' => $isHoliday
                ? ($holiday?->name ?? (\Carbon\Carbon::parse($date)->isWeekend() ? '週末' : null))
                : null,
            'us_indices' => $usIndices,
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
            ->where('mode', 'intraday')
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
        $mode = $request->get('mode', 'intraday');
        if (!in_array($mode, ['intraday', 'overnight'])) {
            $mode = 'intraday';
        }

        $monitors = CandidateMonitor::with(['candidate.stock'])
            ->whereHas('candidate', fn($q) => $q->where('trade_date', $date)->where('mode', $mode))
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

                // 距離百分比
                $target = (float) ($monitor->current_target ?? 0);
                $stop = (float) ($monitor->current_stop ?? 0);
                $distTarget = ($currentPrice && $target > 0) ? round(($target - $currentPrice) / $currentPrice * 100, 2) : null;
                $distStop = ($currentPrice && $stop > 0) ? round(($currentPrice - $stop) / $currentPrice * 100, 2) : null;

                // 持有時間
                $holdingMinutes = null;
                if ($monitor->entry_time && in_array($monitor->status, ['holding', 'entry_signal'])) {
                    $holdingMinutes = (int) $monitor->entry_time->diffInMinutes(now());
                }

                // 進場條件描述
                $entryTrigger = match ($candidate->intraday_strategy ?? 'momentum') {
                    'breakout_fresh', 'momentum' => $target > 0 ? "突破 {$target}" : '突破壓力位',
                    'breakout_retest', 'gap_pullback' => $stop > 0 ? "回測 {$stop} 止穩" : '回測支撐止穩',
                    'bounce' => $stop > 0 ? "觸及 {$stop} 反彈" : '觸及支撐反彈',
                    'gap_reversal' => '跳空確認（缺口不補）',
                    default => '突破壓力位',
                };

                return [
                    'id' => $monitor->id,
                    'candidate_id' => $candidate->id,
                    'symbol' => $stock->symbol,
                    'name' => $stock->name,
                    'status' => $monitor->status,
                    'strategy' => $candidate->intraday_strategy,
                    'entry_trigger' => $entryTrigger,
                    'entry_price' => $monitor->entry_price,
                    'entry_time' => $monitor->entry_time?->format('H:i'),
                    'exit_price' => $monitor->exit_price,
                    'exit_time' => $monitor->exit_time?->format('H:i'),
                    'current_target' => $monitor->current_target,
                    'current_stop' => $monitor->current_stop,
                    'current_price' => $currentPrice,
                    'profit_pct' => $profitPct,
                    'dist_target_pct' => $distTarget,
                    'dist_stop_pct' => $distStop,
                    'holding_minutes' => $holdingMinutes,
                    'change_percent' => $latestSnapshot?->change_percent ?? null,
                    'morning_grade' => $candidate->morning_grade,
                    'limit_up' => $latestSnapshot?->limit_up ?? false,
                    'limit_down' => $latestSnapshot?->limit_down ?? false,
                    'skip_reason' => $monitor->skip_reason,
                    'exit_reason' => $monitor->state_log
                        ? collect($monitor->state_log)->last()['reason'] ?? null
                        : null,
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
        $mode = $request->get('mode', 'intraday');
        $from = now()->subDays($days)->toDateString();
        $to = now()->toDateString();

        $service = new BacktestService();

        if ($mode === 'overnight') {
            $metrics = $service->computeOvernightMetrics($from, $to);
        } else {
            $metrics = $service->computeMetrics($from, $to);
        }

        $metrics['period_days'] = $days;

        return response()->json($metrics);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\DailyQuote;
use App\Models\InvestmentThesis;
use App\Models\MarketHoliday;
use App\Models\SwingPosition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SwingController extends Controller
{
    public function candidates(Request $request): JsonResponse
    {
        $requestedDate = $request->get('date', now()->toDateString());
        $isHoliday = MarketHoliday::isHoliday($requestedDate);
        $date = $isHoliday ? MarketHoliday::previousTradingDay($requestedDate) : $requestedDate;
        $holiday = $isHoliday ? MarketHoliday::where('date', $requestedDate)->first() : null;

        $query = Candidate::with('stock')
            ->where('trade_date', $date)
            ->where('mode', 'swing');

        // viewer 只看 AI 選中的標的（不顯示被排除的卡片）
        if (! $request->user()?->isAdmin()) {
            $query->where('ai_selected', true);
        }

        $candidates = $query
            ->orderByDesc('ai_selected')
            ->orderByDesc('score')
            ->get();

        return response()->json([
            'date' => $date,
            'requested_date' => $requestedDate,
            'trade_date' => $date,
            'is_holiday' => $isHoliday,
            'holiday_name' => $isHoliday
                ? ($holiday?->name ?? (\Carbon\Carbon::parse($requestedDate)->isWeekend() ? '週末' : null))
                : null,
            'latest_trading_date' => $date,
            'thesis_updated_at' => InvestmentThesis::where(function ($q) use ($date) {
                $q->whereNull('research_date')
                    ->orWhere('research_date', '<=', $date);
            })->max('last_evaluated_at'),
            'count' => $candidates->count(),
            'data' => $candidates,
        ]);
    }

    public function positions(Request $request): JsonResponse
    {
        $positions = SwingPosition::with(['stock', 'candidate', 'snapshots' => fn ($q) => $q->orderBy('date')])
            ->where('user_id', $request->user()->id)
            ->orderByRaw("FIELD(status, 'holding', 'exit_suggested', 'watching', 'closed', 'stopped')")
            ->orderByDesc('entry_date')
            ->get()
            ->map(function (SwingPosition $position) {
                $latest = DailyQuote::where('stock_id', $position->stock_id)->orderByDesc('date')->first();
                $current = $latest ? (float) $latest->close : null;
                $entry = (float) $position->entry_price;
                $shares = (int) $position->shares;
                $marketValue = $current !== null ? $current * $shares : null;
                $cost = $entry * $shares;

                $position->setAttribute('current_price', $current);
                $position->setAttribute('unrealized_profit_percent', $current && $entry > 0 ? round(($current - $entry) / $entry * 100, 2) : null);
                $position->setAttribute('market_value', $marketValue);
                $position->setAttribute('cost_amount', $cost);
                $position->setAttribute('risk_amount', $position->current_stop ? max(0, ($entry - (float) $position->current_stop) * $shares) : null);
                $latestSnapshot = $position->snapshots->last();
                $position->setAttribute('tracking_status', [
                    'latest_snapshot_date' => $latestSnapshot?->date?->format('Y-m-d'),
                    'latest_snapshot_at' => $latestSnapshot?->updated_at?->toDateTimeString(),
                    'has_latest_snapshot' => $latest && $latestSnapshot && $latestSnapshot->date->isSameDay($latest->date),
                ]);
                return $position;
            });

        return response()->json([
            'data' => $positions,
            'total_risk_exposure' => $this->riskExposure($positions),
        ]);
    }

    public function storePosition(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'candidate_id' => 'required|exists:candidates,id',
            'entry_price' => 'required|numeric|min:0.01',
            'shares' => 'required|integer|min:1',
        ]);

        $candidate = Candidate::where('mode', 'swing')->findOrFail($validated['candidate_id']);

        $existing = SwingPosition::where('user_id', $request->user()->id)
            ->where('candidate_id', $candidate->id)
            ->whereIn('status', SwingPosition::ACTIVE_STATUSES)
            ->first();
        if ($existing) {
            return response()->json(['message' => '這檔候選已存在於你的短線持倉中'], 422);
        }

        $entryPlan = $candidate->swing_entry_plan ?? [];
        $today = now()->toDateString();
        $entryDate = MarketHoliday::isHoliday($today)
            ? MarketHoliday::previousTradingDay($today)
            : $today;

        $position = SwingPosition::create([
            'user_id' => $request->user()->id,
            'candidate_id' => $candidate->id,
            'stock_id' => $candidate->stock_id,
            'status' => SwingPosition::STATUS_HOLDING,
            'entry_price' => $validated['entry_price'],
            'shares' => $validated['shares'],
            'entry_date' => $entryDate,
            'current_stop' => $candidate->stop_loss,
            'current_target' => $candidate->target_price,
            'max_holding_days' => $candidate->swing_time_horizon_days ?: 20,
            'latest_advice' => [
                'action' => 'hold',
                'reasoning' => '使用者手動建立短線持倉，等待每日盤後追蹤。',
                'current_stop' => (float) $candidate->stop_loss,
                'current_target' => (float) $candidate->target_price,
                'target_price_reasoning' => $entryPlan['target_price_reasoning'] ?? null,
                'expected_holding_days' => $entryPlan['expected_holding_days'] ?? null,
                'target_eta_days' => $entryPlan['target_eta_days'] ?? null,
                'eta_reasoning' => $entryPlan['eta_reasoning'] ?? null,
                'time_pressure' => 'normal',
                'thesis_health' => 'unknown',
                'technical_health' => 'unknown',
                'chip_health' => 'unknown',
                'risk_pressure' => 'low',
            ],
        ]);

        return response()->json($position->load(['stock', 'candidate']), 201);
    }

    public function updatePosition(Request $request, SwingPosition $position): JsonResponse
    {
        abort_unless($position->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'status' => 'sometimes|in:watching,holding,exit_suggested,closed,stopped',
            'current_stop' => 'sometimes|nullable|numeric|min:0',
            'current_target' => 'sometimes|nullable|numeric|min:0',
            'exit_price' => 'sometimes|nullable|numeric|min:0',
            'exit_date' => 'sometimes|nullable|date',
        ]);

        if (isset($validated['status']) && in_array($validated['status'], [SwingPosition::STATUS_CLOSED, SwingPosition::STATUS_STOPPED], true)) {
            $validated['exit_date'] = $validated['exit_date'] ?? now()->toDateString();
        }

        $position->update($validated);

        return response()->json($position->fresh(['stock', 'candidate', 'snapshots']));
    }

    public function destroyPosition(Request $request, SwingPosition $position): JsonResponse
    {
        abort_unless($position->user_id === $request->user()->id, 403);

        $position->delete();

        return response()->json(['message' => 'deleted']);
    }

    public function sizing(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'capital' => 'required|numeric|min:1',
            'risk_percent' => 'required|numeric|min:0.01|max:100',
            'entry_price' => 'required|numeric|min:0.01',
            'stop_loss' => 'required|numeric|min:0.01',
        ]);

        $riskBudget = $validated['capital'] * ($validated['risk_percent'] / 100);
        $riskPerShare = max(0, $validated['entry_price'] - $validated['stop_loss']);
        $shares = $riskPerShare > 0 ? (int) floor($riskBudget / $riskPerShare) : 0;

        return response()->json([
            'risk_budget' => round($riskBudget, 2),
            'risk_per_share' => round($riskPerShare, 2),
            'suggested_shares' => $shares,
            'suggested_lots' => intdiv($shares, 1000),
            'capital_required' => round($shares * $validated['entry_price'], 2),
        ]);
    }

    private function riskExposure($positions): array
    {
        $active = $positions->whereIn('status', SwingPosition::ACTIVE_STATUSES);
        $byThesis = $active->groupBy(fn ($p) => $p->candidate?->swing_thesis['title'] ?? '未分類')
            ->map(fn ($group, $title) => [
                'thesis' => $title,
                'positions' => $group->count(),
                'market_value' => round($group->sum('market_value'), 2),
                'risk_amount' => round($group->sum('risk_amount'), 2),
            ])->values();

        return [
            'active_positions' => $active->count(),
            'market_value' => round($active->sum('market_value'), 2),
            'risk_amount' => round($active->sum('risk_amount'), 2),
            'by_thesis' => $byThesis,
        ];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiLesson;
use App\Models\Candidate;
use App\Models\DailyQuote;
use App\Models\IntradaySnapshot;
use App\Models\InvestmentThesis;
use App\Models\MarketHoliday;
use App\Models\SwingPosition;
use App\Services\FugleRealtimeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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
                $live = $this->resolveLivePrice($position->stock_id, $position->stock?->symbol);
                $current = $live['current_price'] ?? null;
                $entry = (float) $position->entry_price;
                $shares = (int) $position->shares;
                $marketValue = $current !== null ? $current * $shares : null;
                $cost = $entry * $shares;

                $position->setAttribute('current_price', $current);
                $position->setAttribute('prev_close', $live['prev_close'] ?? null);
                $position->setAttribute('change_pct', $live['change_pct'] ?? null);
                $position->setAttribute('price_source', $live['source'] ?? null);
                $position->setAttribute('price_fetched_at', $live['fetched_at'] ?? null);
                $position->setAttribute('unrealized_profit_percent', $current && $entry > 0 ? round(($current - $entry) / $entry * 100, 2) : null);
                $position->setAttribute('market_value', $marketValue);
                $position->setAttribute('cost_amount', $cost);
                $position->setAttribute('risk_amount', $position->current_stop ? max(0, ($entry - (float) $position->current_stop) * $shares) : null);
                $latestDaily = DailyQuote::where('stock_id', $position->stock_id)->orderByDesc('date')->first();
                $latestSnapshot = $position->snapshots->last();
                $position->setAttribute('tracking_status', [
                    'latest_snapshot_date' => $latestSnapshot?->date?->format('Y-m-d'),
                    'latest_snapshot_at' => $latestSnapshot?->updated_at?->toDateTimeString(),
                    'has_latest_snapshot' => $latestDaily && $latestSnapshot && $latestSnapshot->date->isSameDay($latestDaily->date),
                ]);
                return $position;
            });

        return response()->json([
            'data' => $positions,
            'total_risk_exposure' => $this->riskExposure($positions),
        ]);
    }

    /**
     * 輕量端點：只回傳當前持倉的最新報價（給前端分鐘級輪詢用）
     */
    public function livePrices(Request $request): JsonResponse
    {
        $positions = SwingPosition::with('stock')
            ->where('user_id', $request->user()->id)
            ->whereIn('status', SwingPosition::ACTIVE_STATUSES)
            ->get();

        $payload = $positions->map(function (SwingPosition $position) {
            $live = $this->resolveLivePrice($position->stock_id, $position->stock?->symbol);
            $current = $live['current_price'] ?? null;
            $entry = (float) $position->entry_price;
            $shares = (int) $position->shares;

            return [
                'id' => $position->id,
                'symbol' => $position->stock?->symbol,
                'current_price' => $current,
                'prev_close' => $live['prev_close'] ?? null,
                'change_pct' => $live['change_pct'] ?? null,
                'unrealized_profit_percent' => $current && $entry > 0 ? round(($current - $entry) / $entry * 100, 2) : null,
                'market_value' => $current !== null ? round($current * $shares, 2) : null,
                'source' => $live['source'] ?? null,
                'fetched_at' => $live['fetched_at'] ?? null,
            ];
        });

        return response()->json([
            'server_time' => now()->toDateTimeString(),
            'data' => $payload,
        ]);
    }

    /**
     * 取得即時報價：當日 IntradaySnapshot 最新一筆 → fallback Fugle realtime quote → fallback DailyQuote
     * 結果以 symbol 為 key，Cache 30 秒（前端輪詢間隔 60 秒，多請求共用一份）
     *
     * @return array{current_price: float|null, prev_close: float|null, change_pct: float|null, source: string, fetched_at: string|null}|null
     */
    private function resolveLivePrice(int $stockId, ?string $symbol): ?array
    {
        if (!$symbol) {
            return null;
        }

        return Cache::remember("swing:live-price:{$symbol}", 30, function () use ($stockId, $symbol) {
            $today = now()->toDateString();

            $snapshot = IntradaySnapshot::where('stock_id', $stockId)
                ->where('trade_date', $today)
                ->orderByDesc('snapshot_time')
                ->first();

            if ($snapshot) {
                $price = (float) $snapshot->current_price;
                $prev = (float) $snapshot->prev_close;
                return [
                    'current_price' => $price,
                    'prev_close' => $prev > 0 ? $prev : null,
                    'change_pct' => $prev > 0 ? round(($price - $prev) / $prev * 100, 2) : null,
                    'source' => 'snapshot',
                    'fetched_at' => $snapshot->snapshot_time?->toDateTimeString() ?? $snapshot->updated_at?->toDateTimeString(),
                ];
            }

            try {
                /** @var FugleRealtimeClient $fugle */
                $fugle = app(FugleRealtimeClient::class);
                $raw = $fugle->fetchRawQuote($symbol);
                if ($raw && !empty($raw['closePrice'])) {
                    $price = (float) $raw['closePrice'];
                    $prev = (float) ($raw['referencePrice'] ?? 0);
                    return [
                        'current_price' => $price,
                        'prev_close' => $prev > 0 ? $prev : null,
                        'change_pct' => $prev > 0 ? round(($price - $prev) / $prev * 100, 2) : null,
                        'source' => 'fugle',
                        'fetched_at' => now()->toDateTimeString(),
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning("swing live-price fugle [{$symbol}]: " . $e->getMessage());
            }

            $daily = DailyQuote::where('stock_id', $stockId)->orderByDesc('date')->first();
            if ($daily) {
                return [
                    'current_price' => (float) $daily->close,
                    'prev_close' => null,
                    'change_pct' => null,
                    'source' => 'daily_close',
                    'fetched_at' => $daily->date?->toDateString(),
                ];
            }

            return [
                'current_price' => null,
                'prev_close' => null,
                'change_pct' => null,
                'source' => 'none',
                'fetched_at' => null,
            ];
        });
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
            'exit_reason' => 'sometimes|nullable|in:' . implode(',', SwingPosition::EXIT_REASONS),
            'exit_note' => 'sometimes|nullable|string|max:200',
        ]);

        $isClosing = isset($validated['status'])
            && in_array($validated['status'], [SwingPosition::STATUS_CLOSED, SwingPosition::STATUS_STOPPED], true);

        if ($isClosing) {
            $validated['exit_date'] = $validated['exit_date'] ?? now()->toDateString();
            if (empty($validated['exit_reason'])) {
                $validated['exit_reason'] = $this->inferExitReason(
                    $validated['status'],
                    (float) ($validated['exit_price'] ?? $position->exit_price ?? 0),
                    (float) ($position->entry_price ?? 0),
                    (float) ($position->current_target ?? 0),
                    (float) ($position->current_stop ?? 0),
                );
            }
        }

        $position->update($validated);

        return response()->json($position->fresh(['stock', 'candidate', 'snapshots']));
    }

    /**
     * 平倉時自動推算 exit_reason：
     * 1. exit_price ≤ current_stop → stop_hit（觸及停損）
     * 2. exit_price ≥ current_target → target_hit（觸及目標）
     * 3. exit_price > entry_price 中間區間 → take_profit_manual（主動停利）
     * 4. exit_price < entry_price 中間區間 → cut_loss_manual（主動停損）
     * 5. status=stopped 預設 cut_loss_manual；其他預設 other
     */
    private function inferExitReason(string $status, float $exitPrice, float $entry, float $target, float $stop): string
    {
        if ($stop > 0 && $exitPrice > 0 && $exitPrice <= $stop) {
            return 'stop_hit';
        }
        if ($target > 0 && $exitPrice >= $target) {
            return 'target_hit';
        }
        if ($entry > 0 && $exitPrice > 0) {
            return $exitPrice > $entry ? 'take_profit_manual' : 'cut_loss_manual';
        }
        return $status === SwingPosition::STATUS_STOPPED ? 'cut_loss_manual' : 'other';
    }

    public function destroyPosition(Request $request, SwingPosition $position): JsonResponse
    {
        abort_unless($position->user_id === $request->user()->id, 403);

        $position->delete();

        return response()->json(['message' => 'deleted']);
    }

    public function addShares(Request $request, SwingPosition $position): JsonResponse
    {
        abort_unless($position->user_id === $request->user()->id, 403);
        abort_unless(in_array($position->status, SwingPosition::ACTIVE_STATUSES, true), 422, '已結束的持倉不能加倉');

        $validated = $request->validate([
            'price' => 'required|numeric|min:0.01',
            'shares' => 'required|integer|min:1',
        ]);

        $oldShares = (int) $position->shares;
        $oldEntry = (float) $position->entry_price;
        $newShares = $oldShares + (int) $validated['shares'];
        $newEntry = ($oldEntry * $oldShares + (float) $validated['price'] * (int) $validated['shares']) / $newShares;

        $position->update([
            'entry_price' => round($newEntry, 2),
            'shares' => $newShares,
        ]);

        return response()->json($position->fresh(['stock', 'candidate']));
    }

    public function reduceShares(Request $request, SwingPosition $position): JsonResponse
    {
        abort_unless($position->user_id === $request->user()->id, 403);
        abort_unless(in_array($position->status, SwingPosition::ACTIVE_STATUSES, true), 422, '已結束的持倉不能減倉');

        $validated = $request->validate([
            'price' => 'required|numeric|min:0.01',
            'shares' => 'required|integer|min:1',
        ]);

        $oldShares = (int) $position->shares;
        $sellShares = (int) $validated['shares'];
        abort_if($sellShares > $oldShares, 422, "減倉股數 {$sellShares} 超過目前持有 {$oldShares}");

        if ($sellShares === $oldShares) {
            // 全部出清 → 視同平倉
            $position->update([
                'status' => SwingPosition::STATUS_CLOSED,
                'exit_price' => $validated['price'],
                'exit_date' => now()->toDateString(),
            ]);
        } else {
            // 部分減倉：股數減少，平均成本不變（剩餘部位的成本基準不變）
            $position->update([
                'shares' => $oldShares - $sellShares,
            ]);
        }

        return response()->json($position->fresh(['stock', 'candidate']));
    }

    public function lessons(Request $request): JsonResponse
    {
        $lessons = AiLesson::active()
            ->whereIn('mode', ['swing', 'both'])
            ->orderByDesc('priority')
            ->orderByDesc('trade_date')
            ->get()
            ->map(function (AiLesson $l) {
                $expiresAt = $l->expires_at;
                $daysLeft = $expiresAt ? max(0, now()->startOfDay()->diffInDays($expiresAt, false)) : null;
                return [
                    'id' => $l->id,
                    'trade_date' => $l->trade_date?->format('Y-m-d'),
                    'type' => $l->type,
                    'category' => $l->category,
                    'mode' => $l->mode,
                    'source' => $l->source,
                    'priority' => (int) $l->priority,
                    'content' => $l->content,
                    'expires_at' => $expiresAt?->format('Y-m-d'),
                    'days_left' => $daysLeft,
                ];
            });

        return response()->json([
            'count' => $lessons->count(),
            'data' => $lessons,
        ]);
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

<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\CandidateMonitor;
use App\Models\DailyQuote;
use App\Models\IntradaySnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class MonitorService
{
    public function __construct(private TelegramService $telegram)
    {
    }

    /**
     * 為當日 AI 選中的候選建立 monitor（status=pending）
     */
    public function initializeMonitors(string $date): Collection
    {
        $candidates = Candidate::with('stock')
            ->where('trade_date', $date)
            ->where('ai_selected', true)
            ->get();

        $monitors = collect();

        foreach ($candidates as $candidate) {
            $monitor = CandidateMonitor::updateOrCreate(
                ['candidate_id' => $candidate->id],
                ['status' => CandidateMonitor::STATUS_PENDING]
            );
            $monitors->push($monitor);
        }

        return $monitors;
    }

    /**
     * 套用 AI 開盤校準結果
     *
     * @param  array  $calibrations  keyed by symbol: {approved, strategy_override, adjusted_support, adjusted_resistance, entry_conditions, notes}
     */
    public function applyCalibration(string $date, array $calibrations): void
    {
        $candidates = Candidate::with(['stock', 'monitor'])
            ->where('trade_date', $date)
            ->where('ai_selected', true)
            ->get();

        $approvedCount = 0;
        $skippedCount = 0;

        foreach ($candidates as $candidate) {
            $monitor = $candidate->monitor;
            if (!$monitor) continue;

            $symbol = $candidate->stock->symbol;
            $cal = $calibrations[$symbol] ?? null;

            if (!$cal) {
                // AI 沒有回傳此標的，保持 pending
                continue;
            }

            if ($cal['approved'] ?? false) {
                // 通過校準 → watching
                $this->transition($monitor, CandidateMonitor::STATUS_WATCHING, 'AI 開盤校準通過');

                // 設定動態目標/停損
                $monitor->update([
                    'current_target' => $cal['adjusted_resistance'] ?? $candidate->reference_resistance,
                    'current_stop' => $cal['adjusted_support'] ?? $candidate->reference_support,
                    'ai_calibration' => $cal,
                ]);

                // 更新 candidate 的 morning 相容欄位
                $candidate->update([
                    'morning_confirmed' => true,
                    'morning_score' => ($candidate->score + ($candidate->ai_score_adjustment ?? 0)),
                    'morning_signals' => [
                        'ai_calibration' => 'approved',
                        'notes' => $cal['notes'] ?? '',
                    ],
                ]);

                $approvedCount++;

                $this->telegram->send(sprintf(
                    "[校準] %s %s AI通過 | %s | 支撐 %s / 壓力 %s",
                    $symbol,
                    $candidate->stock->name,
                    $candidate->intraday_strategy ?? '-',
                    $cal['adjusted_support'] ?? '-',
                    $cal['adjusted_resistance'] ?? '-'
                ));
            } else {
                // 否決 → skipped
                $reason = $cal['reason'] ?? 'AI 開盤校準否決';
                $this->transition($monitor, CandidateMonitor::STATUS_SKIPPED, $reason);
                $monitor->update(['skip_reason' => $reason, 'ai_calibration' => $cal]);

                $candidate->update([
                    'morning_confirmed' => false,
                    'morning_signals' => [
                        'ai_calibration' => 'denied',
                        'notes' => $reason,
                    ],
                ]);

                $skippedCount++;

                $this->telegram->send(sprintf(
                    "[否決] %s %s AI否決 | %s",
                    $symbol,
                    $candidate->stock->name,
                    $reason
                ));
            }
        }

        $this->telegram->send(sprintf(
            "📋 *開盤校準完成*：通過 %d 檔 / 否決 %d 檔",
            $approvedCount,
            $skippedCount
        ));
    }

    /**
     * 處理新快照：對所有活躍 monitor 評估狀態轉換
     */
    public function processSnapshot(string $date): void
    {
        $monitors = CandidateMonitor::with(['candidate.stock'])
            ->whereHas('candidate', fn($q) => $q->where('trade_date', $date))
            ->whereIn('status', CandidateMonitor::ACTIVE_STATUSES)
            ->get();

        foreach ($monitors as $monitor) {
            $candidate = $monitor->candidate;
            $stock = $candidate->stock;

            // 取最新快照
            $latestSnapshot = IntradaySnapshot::where('stock_id', $stock->id)
                ->where('trade_date', $date)
                ->orderByDesc('snapshot_time')
                ->first();

            if (!$latestSnapshot) continue;

            // 13:25 強制平倉
            $now = now();
            if ($now->format('H:i') >= '13:25' && $monitor->status === CandidateMonitor::STATUS_HOLDING) {
                $this->exitPosition($monitor, $latestSnapshot->current_price, 'closed', '13:25 強制平倉');
                continue;
            }

            match ($monitor->status) {
                CandidateMonitor::STATUS_WATCHING => $this->evaluateWatching($monitor, $candidate, $date),
                CandidateMonitor::STATUS_ENTRY_SIGNAL => $this->evaluateEntrySignal($monitor, $candidate, $date),
                CandidateMonitor::STATUS_HOLDING => $this->evaluateHolding($monitor, $candidate, $date),
                default => null,
            };
        }
    }

    /**
     * watching → entry_signal 或 skipped
     */
    private function evaluateWatching(CandidateMonitor $monitor, Candidate $candidate, string $date): void
    {
        $stock = $candidate->stock;
        $snapshots = $this->getRecentSnapshots($stock->id, $date, 5);
        if ($snapshots->count() < 2) return;

        $latest = $snapshots->last();
        $price = (float) $latest->current_price;
        $cal = $monitor->ai_calibration ?? [];
        $entryConditions = $cal['entry_conditions'] ?? [];

        // 進場條件
        $minVolumeRatio = (float) ($entryConditions['min_volume_ratio'] ?? 1.5);
        $minExternalRatio = (float) ($entryConditions['min_external_ratio'] ?? 55);

        // 量能條件
        if ((float) $latest->estimated_volume_ratio < $minVolumeRatio) return;

        // 外盤比條件
        if ((float) $latest->external_ratio < $minExternalRatio) return;

        // 策略特定條件
        $strategy = $candidate->intraday_strategy ?? 'momentum';
        $support = (float) ($monitor->current_stop ?? $candidate->reference_support ?? 0);
        $resistance = (float) ($monitor->current_target ?? $candidate->reference_resistance ?? 0);

        $entryTriggered = match ($strategy) {
            'breakout_fresh', 'momentum' => $price > $resistance * 0.995, // 接近或突破壓力
            'breakout_retest', 'gap_pullback' => $this->isPullbackEntry($snapshots, $support),
            'bounce' => $this->isBounceEntry($snapshots, $support),
            default => $price > $resistance * 0.995,
        };

        if (!$entryTriggered) return;

        // 區分 pullback vs weakness
        $trajectory = $this->classifyTrajectory($snapshots);
        if ($trajectory === 'weakness') {
            Log::info("MonitorService: {$stock->symbol} 走弱到價，不進場");
            $this->telegram->send(sprintf(
                "[走弱] %s %s 走弱到價 %.2f，不進場 | 外盤 %.0f%%",
                $stock->symbol,
                $stock->name,
                $price,
                (float) $latest->external_ratio
            ));
            return;
        }

        // 判定進場類型
        $entryType = match ($strategy) {
            'breakout_fresh', 'momentum' => 'breakout',
            'breakout_retest', 'gap_pullback' => 'pullback',
            'bounce' => 'bounce',
            default => $trajectory, // pullback or weakness
        };

        // 觸發進場訊號
        $this->transition($monitor, CandidateMonitor::STATUS_ENTRY_SIGNAL, "進場條件成立（{$strategy}）");

        // 計算動態目標/停損
        $entryPrice = $price;
        $avgAmplitude = $this->getAvgAmplitude($stock->id, $date, 5);
        $targetPrice = round($entryPrice * (1 + $avgAmplitude * 0.45 / 100), 2);
        $stopPrice = round($entryPrice * (1 - $avgAmplitude * 0.55 / 100), 2);

        // 上限限制
        $targetPrice = min($targetPrice, round($entryPrice * 1.03, 2));
        $stopPrice = max($stopPrice, round($entryPrice * 0.975, 2));

        $monitor->update([
            'entry_price' => $entryPrice,
            'entry_time' => now(),
            'entry_type' => $entryType,
            'current_target' => $targetPrice,
            'current_stop' => $stopPrice,
        ]);

        // 自動轉為 holding
        $this->transition($monitor, CandidateMonitor::STATUS_HOLDING, '進場確認');

        $this->telegram->send(sprintf(
            "[進場] %s %s %.2f | 量 %.1fx | 外盤 %.0f%% | 目標 %.2f / 停損 %.2f",
            $stock->symbol,
            $stock->name,
            $entryPrice,
            $latest->estimated_volume_ratio,
            $latest->external_ratio,
            $targetPrice,
            $stopPrice
        ));
    }

    /**
     * entry_signal 狀態評估（目前合併到 watching 直接轉 holding）
     */
    private function evaluateEntrySignal(CandidateMonitor $monitor, Candidate $candidate, string $date): void
    {
        // 如果停留在 entry_signal 超過 5 分鐘仍未確認，回到 watching
        if ($monitor->entry_time && $monitor->entry_time->diffInMinutes(now()) > 5) {
            $this->transition($monitor, CandidateMonitor::STATUS_WATCHING, '進場訊號超時，回到觀望');
            $monitor->update(['entry_price' => null, 'entry_time' => null]);
        }
    }

    /**
     * holding → target_hit / stop_hit / trailing_stop
     */
    private function evaluateHolding(CandidateMonitor $monitor, Candidate $candidate, string $date): void
    {
        $stock = $candidate->stock;
        $latest = IntradaySnapshot::where('stock_id', $stock->id)
            ->where('trade_date', $date)
            ->orderByDesc('snapshot_time')
            ->first();

        if (!$latest) return;

        $price = (float) $latest->current_price;
        $entryPrice = (float) $monitor->entry_price;
        $target = (float) $monitor->current_target;
        $stop = (float) $monitor->current_stop;

        if ($entryPrice <= 0) return;

        // 達標出場
        if ($target > 0 && $price >= $target) {
            $this->exitPosition($monitor, $price, 'target_hit', sprintf('達標 %.2f', $target));
            return;
        }

        // 停損出場
        if ($stop > 0 && $price <= $stop) {
            $this->exitPosition($monitor, $price, 'stop_hit', sprintf('停損 %.2f', $stop));
            return;
        }

        // 移動停利：最高點回落超過 50% 已實現利潤
        $highSinceEntry = (float) $latest->high; // 簡化：用當日最高
        $unrealizedProfit = $highSinceEntry - $entryPrice;
        if ($unrealizedProfit > 0 && $entryPrice > 0) {
            $currentProfit = $price - $entryPrice;
            $pullbackRatio = $unrealizedProfit > 0 ? $currentProfit / $unrealizedProfit : 1;

            // 從最高回落超過 50%，且已有 >1% 利潤曾經出現過
            if ($pullbackRatio < 0.5 && ($unrealizedProfit / $entryPrice * 100) > 1.0) {
                $this->exitPosition($monitor, $price, 'trailing_stop', sprintf('移動停利，從高點 %.2f 回落', $highSinceEntry));
                return;
            }
        }

        // 時間停損：持有 > 60 分鐘且利潤 < 0.5%
        if ($monitor->entry_time) {
            $holdingMinutes = $monitor->entry_time->diffInMinutes(now());
            $profitPct = ($price - $entryPrice) / $entryPrice * 100;

            if ($holdingMinutes > 60 && $profitPct < 0.5) {
                $this->exitPosition($monitor, $price, 'trailing_stop', sprintf('時間停損（持有 %d 分鐘，利潤 %.1f%%）', $holdingMinutes, $profitPct));
                return;
            }
        }

        // 動態調停損：利潤 > 2% 時，停損拉高至進場價 +0.5%
        $profitPct = ($price - $entryPrice) / $entryPrice * 100;
        if ($profitPct > 2.0 && $stop < $entryPrice * 1.005) {
            $newStop = round($entryPrice * 1.005, 2);
            $monitor->update(['current_stop' => $newStop]);
        }
        // 利潤 > 4% 時，停損拉高至進場價 +2%
        if ($profitPct > 4.0 && $stop < $entryPrice * 1.02) {
            $newStop = round($entryPrice * 1.02, 2);
            $monitor->update(['current_stop' => $newStop]);
        }
    }

    /**
     * 套用 AI 滾動建議
     */
    public function applyRollingAdvice(CandidateMonitor $monitor, array $advice): void
    {
        $action = $advice['action'] ?? 'hold';
        $notes = $advice['notes'] ?? '';

        $monitor->logAiAdvice($action, $notes, $advice['adjustments'] ?? null);

        match ($action) {
            'exit' => $this->exitByAiAdvice($monitor, $notes),
            'skip' => $this->skipByAiAdvice($monitor, $notes),
            'hold' => $this->applyAdjustments($monitor, $advice),
            'entry' => null, // AI 建議進場由規則式處理，這裡只記錄
            default => null,
        };

        $monitor->save();
    }

    /**
     * 狀態轉換 + 記錄
     */
    private function transition(CandidateMonitor $monitor, string $newStatus, string $reason): void
    {
        $oldStatus = $monitor->status;
        $monitor->logTransition($oldStatus, $newStatus, $reason);
        $monitor->status = $newStatus;
        $monitor->save();
    }

    /**
     * 出場處理
     */
    private function exitPosition(CandidateMonitor $monitor, float $exitPrice, string $exitStatus, string $reason): void
    {
        $entryPrice = (float) $monitor->entry_price;
        $profitPct = $entryPrice > 0 ? round(($exitPrice - $entryPrice) / $entryPrice * 100, 2) : 0;
        $holdingMin = $monitor->entry_time ? $monitor->entry_time->diffInMinutes(now()) : 0;

        $this->transition($monitor, $exitStatus, $reason);
        $monitor->update([
            'exit_price' => $exitPrice,
            'exit_time' => now(),
        ]);

        $stock = $monitor->candidate->stock;
        $sign = $profitPct >= 0 ? '+' : '';
        $tag = match ($exitStatus) {
            'target_hit' => '達標',
            'stop_hit' => '停損',
            'trailing_stop' => '停利',
            'closed' => '收盤',
            default => '出場',
        };

        $this->telegram->send(sprintf(
            "[%s] %s %s %s%.1f%% @ %.2f | 持有 %dmin",
            $tag,
            $stock->symbol,
            $stock->name,
            $sign,
            $profitPct,
            $exitPrice,
            $holdingMin
        ));
    }

    private function exitByAiAdvice(CandidateMonitor $monitor, string $notes): void
    {
        if ($monitor->status !== CandidateMonitor::STATUS_HOLDING) return;

        $stock = $monitor->candidate->stock;
        $latest = IntradaySnapshot::where('stock_id', $stock->id)
            ->where('trade_date', $monitor->candidate->trade_date->format('Y-m-d'))
            ->orderByDesc('snapshot_time')
            ->first();

        if ($latest) {
            $this->exitPosition($monitor, (float) $latest->current_price, 'trailing_stop', "AI建議出場：{$notes}");
        }
    }

    private function skipByAiAdvice(CandidateMonitor $monitor, string $notes): void
    {
        if ($monitor->status !== CandidateMonitor::STATUS_WATCHING) return;
        $this->transition($monitor, CandidateMonitor::STATUS_SKIPPED, "AI建議放棄：{$notes}");
        $monitor->update(['skip_reason' => $notes]);

        $stock = $monitor->candidate->stock;
        $this->telegram->send(sprintf("[AI放棄] %s %s | %s", $stock->symbol, $stock->name, $notes));
    }

    private function applyAdjustments(CandidateMonitor $monitor, array $advice): void
    {
        $adjustments = $advice['adjustments'] ?? [];
        $updated = [];

        if (isset($adjustments['target'])) {
            $monitor->current_target = $adjustments['target'];
            $updated[] = "目標→{$adjustments['target']}";
        }
        if (isset($adjustments['stop'])) {
            $monitor->current_stop = $adjustments['stop'];
            $updated[] = "停損→{$adjustments['stop']}";
        }

        if (!empty($updated)) {
            $stock = $monitor->candidate->stock;
            $this->telegram->send(sprintf(
                "[AI調整] %s %s %s | %s",
                $stock->symbol,
                $stock->name,
                implode(' ', $updated),
                $advice['notes'] ?? ''
            ));
        }
    }

    // ===== 輔助方法 =====

    private function getRecentSnapshots(int $stockId, string $date, int $count): Collection
    {
        return IntradaySnapshot::where('stock_id', $stockId)
            ->where('trade_date', $date)
            ->orderBy('snapshot_time')
            ->get()
            ->takeLast($count);
    }

    /**
     * 拉回進場判斷：價格從高點拉回至支撐附近，且量縮
     */
    private function isPullbackEntry(Collection $snapshots, float $support): bool
    {
        if ($snapshots->count() < 3 || $support <= 0) return false;

        $latest = $snapshots->last();
        $price = (float) $latest->current_price;

        // 價格在支撐 ±0.5% 範圍內
        $tolerance = $support * 0.005;
        if ($price < $support - $tolerance || $price > $support + $tolerance) return false;

        // 最近 3 筆量遞減
        $recent3 = $snapshots->takeLast(3)->values();
        for ($i = 1; $i < $recent3->count(); $i++) {
            if ($recent3[$i]->accumulated_volume >= $recent3[$i - 1]->accumulated_volume * 1.1) {
                return false; // 量沒有遞減
            }
        }

        return true;
    }

    /**
     * 反彈進場判斷：觸及支撐後連續反彈
     */
    private function isBounceEntry(Collection $snapshots, float $support): bool
    {
        if ($snapshots->count() < 3 || $support <= 0) return false;

        $recent3 = $snapshots->takeLast(3)->values();

        // 至少有一筆曾觸及支撐
        $touchedSupport = false;
        foreach ($recent3 as $s) {
            if ((float) $s->current_price <= $support * 1.005) {
                $touchedSupport = true;
                break;
            }
        }
        if (!$touchedSupport) return false;

        // 最後 2 筆價格上升 + 外盤比上升
        $last = $recent3->last();
        $prev = $recent3[$recent3->count() - 2];

        return (float) $last->current_price > (float) $prev->current_price
            && (float) $last->external_ratio > (float) $prev->external_ratio;
    }

    /**
     * 判斷走勢軌跡：pullback（健康拉回）vs weakness（持續走弱）
     */
    private function classifyTrajectory(Collection $snapshots): string
    {
        if ($snapshots->count() < 3) return 'pullback';

        $recent = $snapshots->takeLast(5)->values();
        $downMoveVolume = 0;
        $upMoveVolume = 0;
        $consecutiveDown = 0;

        for ($i = 1; $i < $recent->count(); $i++) {
            $prev = $recent[$i - 1];
            $curr = $recent[$i];
            $deltaVolume = max(0, $curr->accumulated_volume - $prev->accumulated_volume);

            if ((float) $curr->current_price < (float) $prev->current_price) {
                $downMoveVolume += $deltaVolume;
                $consecutiveDown++;
            } else {
                $upMoveVolume += $deltaVolume;
                $consecutiveDown = 0;
            }
        }

        // 連續 3+ 筆下跌且下跌量大於上漲量 → weakness
        if ($consecutiveDown >= 3 && $downMoveVolume > $upMoveVolume * 1.5) {
            return 'weakness';
        }

        return 'pullback';
    }

    /**
     * 取近 N 日平均振幅
     */
    private function getAvgAmplitude(int $stockId, string $date, int $days): float
    {
        $amplitudes = DailyQuote::where('stock_id', $stockId)
            ->where('date', '<', $date)
            ->orderByDesc('date')
            ->limit($days)
            ->pluck('amplitude')
            ->map(fn($v) => (float) $v);

        return $amplitudes->isNotEmpty() ? $amplitudes->avg() : 3.0;
    }
}

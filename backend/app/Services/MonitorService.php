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
            ->where('mode', 'intraday')
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
            ->where('mode', 'intraday')
            ->where('ai_selected', true)
            ->get();

        $gradeCounts = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
        $gradeLabels = ['A' => '強力推薦', 'B' => '標準進場', 'C' => '觀察', 'D' => '放棄'];
        $gradeEmojis = ['A' => '🟢', 'B' => '🔵', 'C' => '🟡', 'D' => '🔴'];

        foreach ($candidates as $candidate) {
            $monitor = $candidate->monitor;
            if (!$monitor) continue;

            $symbol = $candidate->stock->symbol;
            $cal = $calibrations[$symbol] ?? null;

            if (!$cal) {
                continue;
            }

            // 相容舊格式：approved bool → grade 轉換
            $grade = strtoupper($cal['grade'] ?? ($cal['approved'] ?? false ? 'B' : 'D'));
            if (!in_array($grade, ['A', 'B', 'C', 'D'])) $grade = 'D';

            $gradeCounts[$grade]++;

            if (in_array($grade, ['A', 'B', 'C'])) {
                // A/B/C → watching（C 為觀察模式，紙上交易）
                $statusNote = $grade === 'C'
                    ? 'AI 校準 C 級（觀察）'
                    : "AI 校準 {$grade} 級（{$gradeLabels[$grade]}）";
                $this->transition($monitor, CandidateMonitor::STATUS_WATCHING, $statusNote);

                $monitor->update([
                    'current_target' => $cal['adjusted_resistance'] ?? $candidate->reference_resistance,
                    'current_stop' => $cal['adjusted_support'] ?? $candidate->reference_support,
                    'ai_calibration' => $cal,
                ]);

                $candidate->update([
                    'morning_confirmed' => in_array($grade, ['A', 'B']),
                    'morning_grade' => $grade,
                    'morning_score' => ($candidate->score + ($candidate->ai_score_adjustment ?? 0)),
                    'morning_signals' => [
                        'ai_calibration' => 'grade_' . $grade,
                        'notes' => $cal['notes'] ?? '',
                    ],
                ]);

                $this->telegram->send(sprintf(
                    "[當沖校準%s] %s %s %s | %s | 支撐 %s / 壓力 %s",
                    $gradeEmojis[$grade],
                    $grade,
                    $symbol,
                    $candidate->stock->name,
                    $candidate->intraday_strategy ?? '-',
                    $cal['adjusted_support'] ?? '-',
                    $cal['adjusted_resistance'] ?? '-'
                ));
            } else {
                // D → skipped
                $reason = $cal['reason'] ?? 'AI 校準 D 級（放棄）';
                $this->transition($monitor, CandidateMonitor::STATUS_SKIPPED, $reason);
                $monitor->update(['skip_reason' => $reason, 'ai_calibration' => $cal]);

                $candidate->update([
                    'morning_confirmed' => false,
                    'morning_grade' => 'D',
                    'morning_signals' => [
                        'ai_calibration' => 'grade_D',
                        'notes' => $reason,
                    ],
                ]);

                $this->telegram->send(sprintf(
                    "[當沖校準🔴] D %s %s | %s",
                    $symbol,
                    $candidate->stock->name,
                    $reason
                ));
            }
        }

        $summary = collect($gradeCounts)
            ->filter(fn($c) => $c > 0)
            ->map(fn($c, $g) => "{$gradeEmojis[$g]}{$g}:{$c}")
            ->implode(' ');
        $this->telegram->send("📋 *當沖開盤校準完成*：{$summary}");
    }

    /**
     * 處理新快照：對所有活躍 monitor 評估狀態轉換
     * 回傳 [monitor_id => emergencyReason] 供呼叫方觸發緊急 AI
     */
    public function processSnapshot(string $date): array
    {
        $monitors = CandidateMonitor::with(['candidate.stock'])
            ->whereHas('candidate', fn($q) => $q->where('trade_date', $date)->where('mode', 'intraday'))
            ->whereIn('status', CandidateMonitor::ACTIVE_STATUSES)
            ->get();

        $emergencyMonitors = [];

        foreach ($monitors as $monitor) {
            $candidate = $monitor->candidate;
            $stock = $candidate->stock;

            // 取最新快照
            $latestSnapshot = IntradaySnapshot::where('stock_id', $stock->id)
                ->where('trade_date', $date)
                ->orderByDesc('snapshot_time')
                ->first();

            if (!$latestSnapshot) continue;

            // 漲停中無法買入，watching 狀態不評估進場
            if ($latestSnapshot->limit_up && $monitor->status === CandidateMonitor::STATUS_WATCHING) {
                continue;
            }

            // 13:25 強制平倉
            $now = now();
            if ($now->format('H:i') >= '13:25' && $monitor->status === CandidateMonitor::STATUS_HOLDING) {
                $this->exitPosition($monitor, $latestSnapshot->current_price, 'closed', '13:25 強制平倉');
                continue;
            }

            if ($monitor->status === CandidateMonitor::STATUS_HOLDING) {
                $emergencyReason = $this->evaluateHolding($monitor, $candidate, $date);
                if ($emergencyReason !== null) {
                    $emergencyMonitors[$monitor->id] = $emergencyReason;
                }
            } else {
                match ($monitor->status) {
                    CandidateMonitor::STATUS_WATCHING => $this->evaluateWatching($monitor, $candidate, $date),
                    CandidateMonitor::STATUS_ENTRY_SIGNAL => $this->evaluateEntrySignal($monitor, $candidate, $date),
                    default => null,
                };
            }
        }

        return $emergencyMonitors;
    }

    /**
     * watching → entry_signal 或 skipped
     */
    private function evaluateWatching(CandidateMonitor $monitor, Candidate $candidate, string $date): void
    {
        // C 級（觀察）：只追蹤紙上交易，不觸發實際進場
        if ($candidate->morning_grade === 'C') {
            return;
        }

        $stock = $candidate->stock;
        $snapshots = $this->getRecentSnapshots($stock->id, $date, 5);
        if ($snapshots->count() < 2) return;

        $latest = $snapshots->last();
        $price = (float) $latest->current_price;
        $prevClose = (float) $latest->prev_close;

        // 漲停價附近不進場 — 買不到或追高風險極大
        // 漲停以昨收 ×1.10 為準，現價達漲停的 99.5% 即視為接近漲停
        $limitUpPrice = $prevClose * 1.10;
        if ($limitUpPrice > 0 && $price >= $limitUpPrice * 0.995) {
            return;
        }

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
                "[當沖走弱] %s %s 走弱到價 %.2f，不進場 | 外盤 %.0f%%",
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
        $targetPrice = round($entryPrice * (1 + $avgAmplitude * 0.6 / 100), 2);
        $targetPrice = min($targetPrice, round($entryPrice * 1.08, 2));  // 振幅公式上限

        // 若 AI 校準壓力位更高（≤1.10），以 AI 為準（可超越振幅公式上限）
        $aiResistance = (float) $monitor->current_target;
        if ($aiResistance > $entryPrice && $aiResistance <= $entryPrice * 1.10) {
            $targetPrice = max($targetPrice, $aiResistance);
        }
        $targetPrice = min($targetPrice, round($entryPrice * 1.10, 2));  // 絕對上限

        $stopPrice = round($entryPrice * (1 - $avgAmplitude * 0.55 / 100), 2);
        $stopPrice = max($stopPrice, round($entryPrice * 0.97, 2));

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
            "[當沖進場] %s %s %.2f | 量 %.1fx | 外盤 %.0f%% | 目標 %.2f / 停損 %.2f",
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
     * 回傳緊急原因字串（仍持有且偵測到急殺/崩潰/接近停損），或 null
     */
    private function evaluateHolding(CandidateMonitor $monitor, Candidate $candidate, string $date): ?string
    {
        $stock = $candidate->stock;
        $recentSnapshots = IntradaySnapshot::where('stock_id', $stock->id)
            ->where('trade_date', $date)
            ->orderByDesc('snapshot_time')
            ->limit(3)
            ->get()
            ->sortBy('snapshot_time')
            ->values();

        if ($recentSnapshots->isEmpty()) return null;
        $latest = $recentSnapshots->last();

        $price = (float) $latest->current_price;
        $entryPrice = (float) $monitor->entry_price;
        $target = (float) $monitor->current_target;
        $stop = (float) $monitor->current_stop;

        if ($entryPrice <= 0) return null;

        // 達標出場
        if ($target > 0 && $price >= $target) {
            $this->exitPosition($monitor, $price, 'target_hit', sprintf('達標 %.2f', $target));
            return null;
        }

        // 停損出場
        if ($stop > 0 && $price <= $stop) {
            $this->exitPosition($monitor, $price, 'stop_hit', sprintf('停損 %.2f', $stop));
            return null;
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
                return null;
            }
        }

        // 時間停損：持有 > 90 分鐘且仍虧損中
        if ($monitor->entry_time) {
            $holdingMinutes = $monitor->entry_time->diffInMinutes(now());
            $profitPct = ($price - $entryPrice) / $entryPrice * 100;

            if ($holdingMinutes > 90 && $profitPct < 0) {
                $this->exitPosition($monitor, $price, 'trailing_stop', sprintf('時間停損（持有 %d 分鐘，利潤 %.1f%%）', $holdingMinutes, $profitPct));
                return null;
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

        // ===== 緊急觸發偵測（仍持有中才評估）=====
        return $this->detectEmergency($recentSnapshots, $price, $stop);
    }

    /**
     * 偵測緊急出場條件，回傳原因字串或 null
     */
    private function detectEmergency(Collection $recentSnapshots, float $price, float $stop): ?string
    {
        if ($recentSnapshots->count() < 2) return null;

        $latest = $recentSnapshots->last();

        // 條件 1：最近 2 筆快照急殺 > 1.5%
        $prev = $recentSnapshots[$recentSnapshots->count() - 2];
        $prevPrice = (float) $prev->current_price;
        if ($prevPrice > 0) {
            $priceDrop = ($prevPrice - $price) / $prevPrice * 100;
            if ($priceDrop > 1.5) {
                return sprintf("急殺 %.1f%%（%.2f→%.2f）", $priceDrop, $prevPrice, $price);
            }
        }

        // 條件 2：外盤崩潰且持續下跌中
        if ((float) $latest->external_ratio < 35 && (float) $latest->change_percent < -0.5) {
            return sprintf("外盤崩潰 %.0f%% 跌幅 %.1f%%", $latest->external_ratio, $latest->change_percent);
        }

        // 條件 3：接近停損 1% 以內
        if ($stop > 0 && $price < $stop * 1.01) {
            return sprintf("接近停損（現價 %.2f / 停損 %.2f）", $price, $stop);
        }

        return null;
    }

    /**
     * 套用 AI 滾動建議
     */
    public function applyRollingAdvice(CandidateMonitor $monitor, array $advice): void
    {
        $action = $advice['action'] ?? 'hold';
        $notes = $advice['notes'] ?? '';

        $monitor->logAiAdvice($action, $notes, $advice['adjustments'] ?? null);

        // C 級升格：AI 建議進場且時間 < 11:00 → 升為 B，下次 tick 自動觸發進場
        $candidate = $monitor->candidate;
        if ($action === 'entry' && $candidate->morning_grade === 'C' && now()->hour < 11) {
            $stock = $candidate->stock;
            $candidate->update(['morning_grade' => 'B', 'morning_confirmed' => true]);
            $this->telegram->send(sprintf(
                "[當沖升格 C→B] %s %s | %s",
                $stock->symbol,
                $stock->name,
                $notes
            ));
            $monitor->save();
            return;
        }

        match ($action) {
            'exit' => $this->exitByAiAdvice($monitor, $notes),
            'skip' => $this->skipByAiAdvice($monitor, $notes),
            'hold' => $this->applyAdjustments($monitor, $advice),
            'entry' => null, // 非 C 升格的 entry：由規則式處理，這裡只記錄
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
            'target_hit' => '當沖達標',
            'stop_hit' => '當沖停損',
            'trailing_stop' => '當沖停利',
            'closed' => '當沖收盤',
            default => '當沖出場',
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
        $this->telegram->send(sprintf("[當沖AI放棄] %s %s | %s", $stock->symbol, $stock->name, $notes));
    }

    private function applyAdjustments(CandidateMonitor $monitor, array $advice): void
    {
        $adjustments = $advice['adjustments'] ?? [];
        $updated = [];

        // HOLDING 狀態調整
        if (isset($adjustments['target'])) {
            $monitor->current_target = $adjustments['target'];
            $updated[] = "目標→{$adjustments['target']}";
        }
        if (isset($adjustments['stop'])) {
            $monitor->current_stop = $adjustments['stop'];
            $updated[] = "停損→{$adjustments['stop']}";
        }
        // WATCHING 狀態支撐/壓力調整（更新 current_stop/target 供進場條件使用）
        if (isset($adjustments['support'])) {
            $monitor->current_stop = $adjustments['support'];
            $updated[] = "支撐→{$adjustments['support']}";
        }
        if (isset($adjustments['resistance'])) {
            $monitor->current_target = $adjustments['resistance'];
            $updated[] = "壓力→{$adjustments['resistance']}";
        }

        if (!empty($updated)) {
            $stock = $monitor->candidate->stock;
            $this->telegram->send(sprintf(
                "[當沖AI調整] %s %s %s | %s",
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
            ->slice(-$count)
            ->values();
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
        $recent3 = $snapshots->take(-3)->values();
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

        $recent3 = $snapshots->take(-3)->values();

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

        $recent = $snapshots->take(-5)->values();
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

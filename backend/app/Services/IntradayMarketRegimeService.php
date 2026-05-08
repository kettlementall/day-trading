<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\IntradaySnapshot;
use Illuminate\Support\Collection;

class IntradayMarketRegimeService
{
    public function __construct(private FugleRealtimeClient $fugle)
    {
    }

    /**
     * 偵測 AI 選入候選池的盤中環境，供 rolling AI prompt 使用。
     */
    public function detect(string $date): array
    {
        $candidateSnapshots = $this->latestCandidateSnapshots($date);
        $allCandidateSnapshots = $this->allCandidateSnapshots($date);
        $marketData = $this->fetchMarketSnapshot();

        $metrics = $this->buildMetrics($marketData, $candidateSnapshots, $allCandidateSnapshots);

        return $this->classify($metrics);
    }

    /**
     * 純分類邏輯；測試可直接餵 metrics，不需打 Fugle 或 DB。
     */
    public function classify(array $metrics): array
    {
        $sampleSize = (int) ($metrics['sample_size'] ?? 0);
        $gapFadePct = (int) ($metrics['gap_fade_pct'] ?? 0);
        $breakoutFollowPct = (int) ($metrics['breakout_follow_pct'] ?? 0);
        $belowOpeningLowPct = (int) ($metrics['below_opening_low_pct'] ?? 0);
        $volumeConfirmPct = (int) ($metrics['volume_confirm_pct'] ?? 0);
        $externalSupportPct = (int) ($metrics['external_support_pct'] ?? 0);
        $marketUpAvgPct = (float) ($metrics['market_up_avg_pct'] ?? 0);
        $marketDownAvgPct = (float) ($metrics['market_down_avg_pct'] ?? 0);
        $activePositivePct = (int) ($metrics['active_positive_pct'] ?? 0);

        $regime = 'choppy_day';
        $confidence = 55;
        $entryBias = 'wait_confirmation';
        $riskMode = 'cautious';

        if ($sampleSize === 0) {
            $regime = 'unknown';
            $confidence = 20;
            $entryBias = 'wait_confirmation';
            $riskMode = 'cautious';
        } elseif ($marketDownAvgPct > 0 && $marketDownAvgPct >= max(4.0, $marketUpAvgPct * 1.35) && ($gapFadePct >= 45 || $belowOpeningLowPct >= 35)) {
            $regime = 'selloff_day';
            $confidence = min(90, 65 + (int) (($marketDownAvgPct - $marketUpAvgPct) * 5));
            $entryBias = 'stop_new_entries';
            $riskMode = 'defensive';
        } elseif ($gapFadePct >= 60 || $belowOpeningLowPct >= 45) {
            $regime = 'gap_fade_day';
            $confidence = min(88, 58 + max($gapFadePct - 50, $belowOpeningLowPct - 35));
            $entryBias = 'avoid_chase';
            $riskMode = 'defensive';
        } elseif ($breakoutFollowPct >= 50 && $volumeConfirmPct >= 60 && $externalSupportPct >= 55 && ($activePositivePct >= 50 || ($marketUpAvgPct <= 0 && $marketDownAvgPct <= 0))) {
            $regime = 'trend_day';
            $confidence = min(88, 58 + (int) (($breakoutFollowPct - 45 + $volumeConfirmPct - 55) / 2));
            $entryBias = 'allow_momentum';
            $riskMode = 'normal';
        } elseif ($volumeConfirmPct < 35 && $sampleSize > 0) {
            $regime = 'thin_day';
            $confidence = 65;
            $entryBias = 'wait_confirmation';
            $riskMode = 'defensive';
        }

        return [
            'regime' => $regime,
            'confidence' => $confidence,
            'entry_bias' => $entryBias,
            'risk_mode' => $riskMode,
            'source' => ($metrics['snapshot_enhanced'] ?? false)
                ? 'selected_universe+fugle_snapshot'
                : 'selected_universe',
            'metrics' => $metrics,
            'summary' => $this->buildSummary($regime, $metrics),
        ];
    }

    private function fetchMarketSnapshot(): array
    {
        return [
            'movers_up' => array_merge(
                $this->fugle->fetchMovers('TSE', 'up'),
                $this->fugle->fetchMovers('OTC', 'up'),
            ),
            'movers_down' => array_merge(
                $this->fugle->fetchMovers('TSE', 'down'),
                $this->fugle->fetchMovers('OTC', 'down'),
            ),
            'actives' => array_merge(
                $this->fugle->fetchActives('TSE', 'value'),
                $this->fugle->fetchActives('OTC', 'value'),
            ),
        ];
    }

    private function latestCandidateSnapshots(string $date): Collection
    {
        $stockIds = Candidate::where('trade_date', $date)
            ->where('mode', 'intraday')
            ->where('ai_selected', true)
            ->pluck('stock_id');

        if ($stockIds->isEmpty()) {
            return collect();
        }

        return IntradaySnapshot::whereIn('stock_id', $stockIds)
            ->where('trade_date', $date)
            ->orderByDesc('snapshot_time')
            ->get()
            ->unique('stock_id')
            ->values();
    }

    private function allCandidateSnapshots(string $date): Collection
    {
        $stockIds = Candidate::where('trade_date', $date)
            ->where('mode', 'intraday')
            ->where('ai_selected', true)
            ->pluck('stock_id');

        if ($stockIds->isEmpty()) {
            return collect();
        }

        return IntradaySnapshot::whereIn('stock_id', $stockIds)
            ->where('trade_date', $date)
            ->orderBy('snapshot_time')
            ->get();
    }

    private function buildMetrics(array $marketData, Collection $latestSnapshots, Collection $allSnapshots): array
    {
        $sampleSize = $latestSnapshots->count();
        $openingLows = $this->openingLows($allSnapshots);

        $pct = fn(int $count) => $sampleSize > 0 ? (int) round($count / $sampleSize * 100) : 0;

        $gapFadeCount = $latestSnapshots->filter(fn($s) =>
            (float) $s->open_change_percent > 1
            && (float) $s->current_price < (float) $s->open
        )->count();
        $breakoutFollowCount = $latestSnapshots->filter(function ($s) {
            $high = (float) $s->high;
            $current = (float) $s->current_price;
            $open = (float) $s->open;

            return $current > $open && $high > 0 && (($high - $current) / $high) <= 0.01;
        })->count();
        $belowOpeningLowCount = $latestSnapshots->filter(function ($s) use ($openingLows) {
            $openingLow = $openingLows[$s->stock_id] ?? null;
            return $openingLow !== null && (float) $s->current_price <= $openingLow;
        })->count();

        return [
            'sample_size' => $sampleSize,
            'gap_fade_pct' => $pct($gapFadeCount),
            'breakout_follow_pct' => $pct($breakoutFollowCount),
            'below_opening_low_pct' => $pct($belowOpeningLowCount),
            'volume_confirm_pct' => $pct($latestSnapshots->filter(fn($s) => (float) $s->estimated_volume_ratio >= 1.5)->count()),
            'external_support_pct' => $pct($latestSnapshots->filter(fn($s) => (float) $s->external_ratio >= 55)->count()),
            'market_up_avg_pct' => $this->avgMoverPercent($marketData['movers_up'] ?? [], false),
            'market_down_avg_pct' => $this->avgMoverPercent($marketData['movers_down'] ?? [], true),
            'active_positive_pct' => $this->activePositivePct($marketData['actives'] ?? []),
            'snapshot_enhanced' => !empty($marketData['movers_up'] ?? [])
                || !empty($marketData['movers_down'] ?? [])
                || !empty($marketData['actives'] ?? []),
        ];
    }

    private function openingLows(Collection $snapshots): array
    {
        return $snapshots
            ->groupBy('stock_id')
            ->map(function (Collection $stockSnapshots) {
                $first = $stockSnapshots->first();
                if (!$first) {
                    return null;
                }

                $cutoff = $first->snapshot_time->copy()->addMinutes(5);
                return $stockSnapshots
                    ->filter(fn($s) => $s->snapshot_time <= $cutoff)
                    ->min(fn($s) => (float) $s->current_price);
            })
            ->filter(fn($low) => $low !== null)
            ->all();
    }

    private function avgMoverPercent(array $rows, bool $absolute): float
    {
        $values = collect($rows)
            ->take(20)
            ->map(fn($row) => (float) ($row['changePercent'] ?? 0))
            ->filter(fn($value) => $value !== 0.0)
            ->map(fn($value) => $absolute ? abs($value) : $value);

        return $values->isNotEmpty() ? round($values->avg(), 2) : 0.0;
    }

    private function activePositivePct(array $rows): int
    {
        $activeRows = collect($rows)->take(30);
        if ($activeRows->isEmpty()) {
            return 0;
        }

        $positive = $activeRows->filter(fn($row) => (float) ($row['changePercent'] ?? 0) > 0)->count();

        return (int) round($positive / $activeRows->count() * 100);
    }

    private function buildSummary(string $regime, array $metrics): string
    {
        $snapshotNote = ($metrics['snapshot_enhanced'] ?? false)
            ? '已納入 Fugle snapshot movers/actives'
            : 'Fugle snapshot 未使用，僅代表 AI 選入候選池，不代表全市場大盤';

        $base = sprintf(
            'AI 選入候選池 %d 檔，開高回落 %d%%，突破延續 %d%%，跌破開盤低點 %d%%，量能確認 %d%%，外盤支持 %d%%；Fugle snapshot 漲幅榜均值 %.2f%%、跌幅榜均值 %.2f%%，成交值排行上漲占比 %d%%（%s）。',
            (int) ($metrics['sample_size'] ?? 0),
            (int) ($metrics['gap_fade_pct'] ?? 0),
            (int) ($metrics['breakout_follow_pct'] ?? 0),
            (int) ($metrics['below_opening_low_pct'] ?? 0),
            (int) ($metrics['volume_confirm_pct'] ?? 0),
            (int) ($metrics['external_support_pct'] ?? 0),
            (float) ($metrics['market_up_avg_pct'] ?? 0),
            (float) ($metrics['market_down_avg_pct'] ?? 0),
            (int) ($metrics['active_positive_pct'] ?? 0),
            $snapshotNote,
        );

        $hint = match ($regime) {
            'trend_day' => '今日偏趨勢延續，可允許 momentum，但仍需檢查進場品質。',
            'gap_fade_day' => '今日偏開高走低，不適合追高，策略切換後仍應等待回測或重新站穩。',
            'selloff_day' => '今日偏殺多/普跌，除非個股明顯逆勢，否則應避免新倉。',
            'thin_day' => '今日量能不足，應等待更明確確認，降低追突破衝動。',
            'unknown' => '市場資料不足，降低信心，回到單檔結構與候選池訊號判斷。',
            default => '今日偏震盪，優先等待確認，不把短線突破直接視為可追。',
        };

        return "{$base} {$hint}";
    }
}

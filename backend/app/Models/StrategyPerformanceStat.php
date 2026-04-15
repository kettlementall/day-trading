<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StrategyPerformanceStat extends Model
{
    protected $fillable = [
        'mode', 'dimension_type', 'dimension_value', 'period_days',
        'sample_count', 'target_reach_rate', 'expected_value', 'avg_risk_reward',
        'computed_at',
    ];

    protected $casts = [
        'target_reach_rate' => 'decimal:2',
        'expected_value'    => 'decimal:2',
        'avg_risk_reward'   => 'decimal:2',
        'computed_at'       => 'datetime',
    ];

    /**
     * 取得 AI prompt 用的統計摘要文字
     *
     * @param string $mode     'intraday' | 'overnight'
     * @param int    $minSamples 樣本不足時不顯示
     */
    public static function getPromptSummary(string $mode, int $minSamples = 10): string
    {
        $stats = static::where('mode', $mode)
            ->where('period_days', 60)
            ->where('sample_count', '>=', $minSamples)
            ->orderBy('dimension_type')
            ->orderByDesc('expected_value')
            ->get();

        if ($stats->isEmpty()) {
            return '';
        }

        $sections = [];

        // 策略類型
        $strategyStats = $stats->where('dimension_type', 'strategy');
        if ($strategyStats->isNotEmpty()) {
            $lines = $strategyStats->map(fn($s) =>
                sprintf('  %-20s 達標率%s%%  期望值%s%%  (n=%d)',
                    $s->dimension_value,
                    $s->target_reach_rate,
                    ($s->expected_value >= 0 ? '+' : '') . $s->expected_value,
                    $s->sample_count
                )
            )->implode("\n");
            $sections[] = "策略類型（近60天）：\n{$lines}";
        }

        // 特徵組合
        $featureStats = $stats->where('dimension_type', 'feature');
        if ($featureStats->isNotEmpty()) {
            $lines = $featureStats->map(fn($s) =>
                sprintf('  %-20s 達標率%s%%  期望值%s%%  (n=%d)',
                    $s->dimension_value,
                    $s->target_reach_rate,
                    ($s->expected_value >= 0 ? '+' : '') . $s->expected_value,
                    $s->sample_count
                )
            )->implode("\n");
            $sections[] = "特徵組合（近60天）：\n{$lines}";
        }

        // 大盤環境
        $marketStats = $stats->where('dimension_type', 'market_condition');
        if ($marketStats->isNotEmpty()) {
            $lines = $marketStats->map(fn($s) =>
                sprintf('  %-16s 達標率%s%%  期望值%s%%',
                    $s->dimension_value,
                    $s->target_reach_rate,
                    ($s->expected_value >= 0 ? '+' : '') . $s->expected_value,
                )
            )->implode("\n");
            $sections[] = "大盤環境績效（近60天）：\n{$lines}";
        }

        if (empty($sections)) {
            return '';
        }

        return "## 近期策略績效統計（樣本 < {$minSamples} 筆不顯示）\n\n"
            . implode("\n\n", $sections);
    }
}

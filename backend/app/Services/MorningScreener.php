<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\IntradayQuote;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class MorningScreener
{
    /**
     * 開盤 30 分鐘後的確認規則
     *
     * 基本四規則：
     * 1. 預估量爆發：預估成交量 > 昨量 1.5 倍
     * 2. 開盤開高位階：開盤漲幅介於 2% ~ 5%
     * 3. 現價 > 第一根 5 分 K 線高點
     * 4. 外盤比 > 55%
     *
     * 額外驗證（影響通過判定）：
     * 5. 跳空過大警示：開盤漲幅 > 7% → 隔日沖風險，降級為不通過
     * 6. 支撐回測確認：突破型標的需現價站穩買入價上方
     */
    public function screen(string $tradeDate): Collection
    {
        $candidates = Candidate::with('stock')
            ->where('trade_date', $tradeDate)
            ->get();

        $results = collect();

        foreach ($candidates as $candidate) {
            $intraday = IntradayQuote::where('stock_id', $candidate->stock_id)
                ->where('date', $tradeDate)
                ->first();

            if (!$intraday) {
                Log::warning("盤中資料缺失: {$candidate->stock->symbol}");
                continue;
            }

            $signals = [];
            $morningScore = 0;

            // 規則 1：預估量爆發（最關鍵指標）
            $volumeResult = $this->checkEstimatedVolume($intraday);
            $signals[] = $volumeResult;
            if ($volumeResult['passed']) {
                $morningScore += 30;
            }

            // 規則 2：開盤開高位階（2% ~ 5%）
            $openGapResult = $this->checkOpeningGap($intraday);
            $signals[] = $openGapResult;
            if ($openGapResult['passed']) {
                $morningScore += 25;
            }

            // 規則 3：現價 > 第一根 5 分 K 線高點
            $first5minResult = $this->checkFirst5MinBreakout($intraday);
            $signals[] = $first5minResult;
            if ($first5minResult['passed']) {
                $morningScore += 25;
            }

            // 規則 4：外盤比 > 55%
            $externalResult = $this->checkExternalRatio($intraday);
            $signals[] = $externalResult;
            if ($externalResult['passed']) {
                $morningScore += 20;
            }

            // 規則 5：跳空過大警示（開盤漲 > 7%，隔日沖風險高）
            $gapRiskResult = $this->checkGapUpRisk($intraday);
            $signals[] = $gapRiskResult;

            // 規則 6：支撐回測確認（突破型標的需站穩買入價）
            $supportResult = $this->checkSupportHold($intraday, $candidate);
            $signals[] = $supportResult;

            // 通過條件：基本四規則至少 3 項通過（含預估量必須通過）
            $basicPassedCount = collect(array_slice($signals, 0, 4))->where('passed', true)->count();
            $volumePassed = $signals[0]['passed'];
            $confirmed = $volumePassed && $basicPassedCount >= 3;

            // 額外驗證可否決通過：跳空過大 → 強制不通過
            if ($confirmed && !$gapRiskResult['passed']) {
                $confirmed = false;
            }

            // 支撐回測未通過 → 降級為不確認
            if ($confirmed && !$supportResult['passed']) {
                $confirmed = false;
            }

            // 分級制：依 morningScore 對應 A/B/C/D
            $grade = match (true) {
                !$confirmed => 'D',
                $morningScore >= 85 => 'A',
                $morningScore >= 70 => 'B',
                $morningScore >= 50 => 'C',
                default => 'D',
            };

            $candidate->update([
                'morning_score' => $morningScore,
                'morning_signals' => $signals,
                'morning_confirmed' => in_array($grade, ['A', 'B']),
                'morning_grade' => $grade,
            ]);

            $results->push([
                'candidate_id' => $candidate->id,
                'stock_symbol' => $candidate->stock->symbol,
                'stock_name' => $candidate->stock->name,
                'morning_score' => $morningScore,
                'morning_confirmed' => in_array($grade, ['A', 'B']),
                'morning_grade' => $grade,
                'signals' => $signals,
            ]);
        }

        return $results;
    }

    /**
     * 規則 1：預估量爆發
     * 預估成交量 > 昨量 1.5 倍（2 倍以上更佳）
     */
    private function checkEstimatedVolume(IntradayQuote $intraday): array
    {
        $ratio = (float) $intraday->estimated_volume_ratio;
        $passed = $ratio >= 1.5;
        $level = $ratio >= 2.0 ? '強勢爆量' : ($ratio >= 1.5 ? '量能放大' : '量能不足');

        return [
            'rule' => '預估量爆發',
            'passed' => $passed,
            'value' => $ratio,
            'threshold' => 1.5,
            'detail' => "預估量為昨量 {$ratio} 倍（{$level}）",
        ];
    }

    /**
     * 規則 2：開盤開高位階
     * 開盤漲幅介於 2% ~ 5%
     */
    private function checkOpeningGap(IntradayQuote $intraday): array
    {
        $percent = (float) $intraday->open_change_percent;
        $passed = $percent >= 2.0 && $percent <= 5.0;

        $detail = match (true) {
            $percent > 7.0 => "開盤漲 {$percent}%，過高有隔日沖風險",
            $percent > 5.0 => "開盤漲 {$percent}%，略高",
            $percent >= 2.0 => "開盤漲 {$percent}%，位階理想",
            $percent > 0.0 => "開盤漲 {$percent}%，漲幅偏小",
            default => "開盤跌 {$percent}%，信心不足",
        };

        return [
            'rule' => '開盤開高',
            'passed' => $passed,
            'value' => $percent,
            'threshold' => '2%~5%',
            'detail' => $detail,
        ];
    }

    /**
     * 規則 3：現價 > 第一根 5 分 K 線高點
     * 代表開盤進場的人都賺錢，買盤持續
     */
    private function checkFirst5MinBreakout(IntradayQuote $intraday): array
    {
        $currentPrice = (float) $intraday->current_price;
        $first5minHigh = (float) $intraday->first_5min_high;
        $first5minLow = (float) $intraday->first_5min_low;
        $passed = $first5minHigh > 0 && $currentPrice > $first5minHigh;

        $detail = $passed
            ? "現價 {$currentPrice} > 5分K高 {$first5minHigh}，買盤持續"
            : ($currentPrice < $first5minLow
                ? "現價 {$currentPrice} < 5分K低 {$first5minLow}，走勢轉弱"
                : "現價 {$currentPrice}，尚未突破5分K高 {$first5minHigh}");

        return [
            'rule' => '突破首根5分K',
            'passed' => $passed,
            'value' => $currentPrice,
            'threshold' => $first5minHigh,
            'detail' => $detail,
        ];
    }

    /**
     * 規則 4：外盤比 > 55%
     * 外盤成交代表買方主動追價，買氣旺盛
     */
    private function checkExternalRatio(IntradayQuote $intraday): array
    {
        $ratio = (float) $intraday->external_ratio;
        $passed = $ratio > 55;

        $detail = match (true) {
            $ratio >= 65 => "外盤比 {$ratio}%，買氣極旺",
            $ratio > 55 => "外盤比 {$ratio}%，買方力道強",
            $ratio >= 45 => "外盤比 {$ratio}%，買賣力道均衡",
            default => "外盤比 {$ratio}%，賣壓偏重",
        };

        return [
            'rule' => '外盤比',
            'passed' => $passed,
            'value' => $ratio,
            'threshold' => 55,
            'detail' => $detail,
        ];
    }

    /**
     * 規則 5：跳空過大警示
     * 開盤漲幅 > 7% 有隔日沖風險，視為否決條件
     * passed = true 表示「沒有跳空過大問題」（安全）
     */
    private function checkGapUpRisk(IntradayQuote $intraday): array
    {
        $percent = (float) $intraday->open_change_percent;
        $threshold = 7.0;
        $isRisky = $percent > $threshold;

        return [
            'rule' => '跳空風險',
            'passed' => !$isRisky,
            'value' => $percent,
            'threshold' => $threshold,
            'detail' => $isRisky
                ? "開盤漲 {$percent}%，跳空過大有隔日沖風險"
                : "開盤漲 {$percent}%，跳空幅度正常",
        ];
    }

    /**
     * 規則 6：支撐回測確認
     * 突破型標的需要現價站穩在建議買入價上方，確認支撐有效
     * 非突破型標的直接通過（不適用此規則）
     * passed = true 表示「支撐確認OK」或「非突破型不需檢查」
     */
    private function checkSupportHold(IntradayQuote $intraday, Candidate $candidate): array
    {
        $currentPrice = (float) $intraday->current_price;
        $suggestedBuy = (float) $candidate->suggested_buy;
        $first5minLow = (float) $intraday->first_5min_low;
        $strategyType = $candidate->strategy_type;

        // 非突破型標的，此規則直接通過
        if ($strategyType !== 'breakout') {
            return [
                'rule' => '支撐確認',
                'passed' => true,
                'value' => $currentPrice,
                'threshold' => $suggestedBuy,
                'detail' => '非突破型，不需支撐回測確認',
            ];
        }

        // 突破型：現價需在建議買入價上方，且盤中低點未跌破買入價過多（容許 1%）
        $holdMargin = 0.99; // 允許 1% 的回測空間
        $lowHeld = $first5minLow >= $suggestedBuy * $holdMargin;
        $priceAbove = $currentPrice >= $suggestedBuy;
        $passed = $priceAbove && $lowHeld;

        $detail = match (true) {
            $passed => "現價 {$currentPrice} 站穩買入價 {$suggestedBuy} 上方，支撐有效",
            !$priceAbove => "現價 {$currentPrice} 已跌破買入價 {$suggestedBuy}，支撐失守",
            default => "盤中低點 {$first5minLow} 跌破買入價 {$suggestedBuy}，支撐不穩",
        };

        return [
            'rule' => '支撐確認',
            'passed' => $passed,
            'value' => $currentPrice,
            'threshold' => $suggestedBuy,
            'detail' => $detail,
        ];
    }
}

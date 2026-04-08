<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\IntradayQuote;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class MorningScreener
{
    /**
     * 開盤 30 分鐘後的四大確認規則
     *
     * 1. 預估量爆發：預估成交量 > 昨量 1.5 倍
     * 2. 開盤開高位階：開盤漲幅介於 2% ~ 5%
     * 3. 現價 > 第一根 5 分 K 線高點
     * 4. 外盤比 > 55%
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
                $morningScore += 30; // 最關鍵，給最高分
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

            // 通過條件：至少 3 項規則通過（含預估量必須通過）
            $passedCount = collect($signals)->where('passed', true)->count();
            $volumePassed = $signals[0]['passed'];
            $confirmed = $volumePassed && $passedCount >= 3;

            $candidate->update([
                'morning_score' => $morningScore,
                'morning_signals' => $signals,
                'morning_confirmed' => $confirmed,
            ]);

            $results->push([
                'candidate_id' => $candidate->id,
                'stock_symbol' => $candidate->stock->symbol,
                'stock_name' => $candidate->stock->name,
                'morning_score' => $morningScore,
                'morning_confirmed' => $confirmed,
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
}

<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\DailyQuote;
use App\Models\FormulaSetting;
use App\Models\InstitutionalTrade;
use App\Models\MarginTrade;
use App\Models\NewsIndex;
use App\Models\ScreeningRule;
use App\Models\Stock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class StockScreener
{
    /**
     * 執行選股篩選：只做物理不可能門檻 + 技術資料計算，交由 AI 判斷品質
     */
    public function screen(string $tradeDate, ?int $minScoreOverride = null, ?int $maxCandidatesOverride = null): Collection
    {
        $stocks = Stock::where('is_day_trading', true)->get();
        $rules = ScreeningRule::where('is_active', true)->orderBy('sort_order')->get();
        $buyConfig = FormulaSetting::getConfig('suggested_buy');
        $targetConfig = FormulaSetting::getConfig('target_price');
        $stopConfig = FormulaSetting::getConfig('stop_loss');
        $screenConfig = FormulaSetting::getConfig('screen_thresholds');
        $newsConfig = FormulaSetting::getConfig('news_sentiment');
        $candidates = collect();

        // 取得最新消息面指數
        $newsOverall = NewsIndex::where('scope', 'overall')
            ->where('date', '<=', $tradeDate)
            ->orderByDesc('date')
            ->first();
        $newsIndustries = $newsOverall
            ? NewsIndex::where('scope', 'industry')
                ->where('date', $newsOverall->date)
                ->pluck('sentiment', 'scope_value')
            : collect();

        foreach ($stocks as $stock) {
            $quotes = DailyQuote::where('stock_id', $stock->id)
                ->where('date', '<', $tradeDate)
                ->orderByDesc('date')
                ->limit(60)
                ->get();

            if ($quotes->count() < 20) continue;

            $closes = $quotes->pluck('close')->map(fn ($v) => (float) $v)->toArray();
            $highs = $quotes->pluck('high')->map(fn ($v) => (float) $v)->toArray();
            $lows = $quotes->pluck('low')->map(fn ($v) => (float) $v)->toArray();
            $opens = $quotes->pluck('open')->map(fn ($v) => (float) $v)->toArray();
            $volumes = $quotes->pluck('volume')->toArray();
            $changePcts = $quotes->pluck('change_percent')->map(fn ($v) => (float) $v)->toArray();
            $amplitudes = $quotes->pluck('amplitude')->map(fn ($v) => (float) $v)->toArray();
            $latest = $quotes->first();

            // =========================================
            // 物理不可能門檻（不可當沖的硬性條件）
            // =========================================

            // 最低成交量（單日）
            $minVolume = $screenConfig['min_volume'] ?? 500;
            if ($volumes[0] / 1000 < $minVolume) continue;

            // 最低股價
            $minPrice = $screenConfig['min_price'] ?? 10;
            if ($closes[0] < $minPrice) continue;

            // 5 日均振幅太低（< 0.5%）→ 波動根本不夠當沖
            $recent5Amplitudes = array_slice($amplitudes, 0, 5);
            $avgAmplitude5 = count($recent5Amplitudes) > 0 ? array_sum($recent5Amplitudes) / count($recent5Amplitudes) : 0;
            $minAmplitude = $screenConfig['min_amplitude'] ?? 0.5;
            if ($avgAmplitude5 < $minAmplitude) continue;

            // 5 日均量不足（< 200 張）→ 流動性不足無法進出場
            $recent5Volumes = array_slice($volumes, 0, 5);
            $avgVolume5 = count($recent5Volumes) > 0 ? array_sum($recent5Volumes) / count($recent5Volumes) / 1000 : 0;
            $minDayTradingVolume = $screenConfig['min_day_trading_volume'] ?? 200;
            if ($avgVolume5 < $minDayTradingVolume) continue;

            // =========================================
            // 技術指標計算（供 AI 使用）
            // =========================================

            $ma5 = TechnicalIndicator::sma($closes, 5);
            $ma10 = TechnicalIndicator::sma($closes, 10);
            $ma20 = TechnicalIndicator::sma($closes, 20);
            $rsi = TechnicalIndicator::rsi($closes);
            $kd = TechnicalIndicator::kd($highs, $lows, $closes);
            $atr = TechnicalIndicator::atr($highs, $lows, $closes);
            $bollinger = TechnicalIndicator::bollinger($closes);
            $macd = TechnicalIndicator::macd($closes);

            // 法人及融資資料
            $inst = InstitutionalTrade::where('stock_id', $stock->id)
                ->orderByDesc('date')->limit(5)->get();
            $margin = MarginTrade::where('stock_id', $stock->id)
                ->orderByDesc('date')->limit(5)->get();

            $indicators = compact('ma5', 'ma10', 'ma20', 'rsi', 'kd', 'atr', 'bollinger');

            // =========================================
            // 事實標籤（3–5 個，讓 AI 快速掌握關鍵信號）
            // =========================================
            $reasons = [];
            $avgVol5 = array_sum(array_slice($volumes, 0, 5)) / 5;
            if ($volumes[0] > $avgVol5 * 1.5) $reasons[] = '量放大';
            if ($inst->isNotEmpty() && $inst->first()->foreign_net > 0) $reasons[] = '外資買超';
            if ($inst->isNotEmpty() && $inst->first()->trust_net > 0) $reasons[] = '投信買超';
            $prev5High = count($highs) >= 6 ? max(array_slice($highs, 1, 5)) : 0;
            if ($prev5High > 0 && $closes[0] > $prev5High) $reasons[] = '突破前高';
            if ($margin->isNotEmpty() && $margin->first()->margin_change < 0) $reasons[] = '融資減';

            // 自訂規則匹配 → 加入標籤供 AI 參考（不做硬排除）
            foreach ($rules as $rule) {
                if ($this->matchRule($rule, $latest, $inst->first(), $margin->first())) {
                    $reasons[] = $rule->name;
                }
            }

            // =========================================
            // 參考價格計算（AI 可覆寫）
            // =========================================

            // 消息面修正係數（只影響價格空間，不做評分）
            $newsFactor = $this->calcNewsSentimentFactor(
                $newsOverall, $newsIndustries, $stock, $newsConfig
            );
            $priceFactor = $newsFactor['price_factor'];

            // 漲跌停限制
            $prevClose = $closes[0];
            $limitUp = $this->tickRound($prevClose * 1.10, $prevClose, 'down');
            $limitDown = $this->tickRound($prevClose * 0.90, $prevClose, 'up');

            $suggestedBuy = $this->calcSuggestedBuy(
                $closes, $lows, $highs, $indicators, $buyConfig
            );
            $targetPrice = $this->calcTargetPrice($closes, $highs, $atr, $bollinger, $targetConfig, $suggestedBuy);
            $stopLoss = $this->calcStopLoss($closes, $lows, $atr, $stopConfig);

            // 消息面修正
            if ($priceFactor !== 1.0) {
                $targetPrice = round($suggestedBuy + ($targetPrice - $suggestedBuy) * $priceFactor, 2);
                if ($priceFactor < 1.0) {
                    $stopLoss = round($suggestedBuy - ($suggestedBuy - $stopLoss) * (2.0 - $priceFactor), 2);
                }
            }

            // 限價約束
            $suggestedBuy = max($limitDown, min($limitUp, $suggestedBuy));
            $targetPrice = max($limitDown, min($limitUp, $targetPrice));
            $stopLoss = max($limitDown, min($limitUp, $stopLoss));

            $profitSpace = $targetPrice - $suggestedBuy;
            $lossSpace = $suggestedBuy - $stopLoss;
            $riskReward = $lossSpace > 0 ? round($profitSpace / $lossSpace, 2) : 0;

            // 風報比絕對底線（< 0.8 代表停損空間比獲利還大，不合理）
            $minRR = $screenConfig['min_risk_reward'] ?? 0.8;
            if ($riskReward < $minRR) continue;

            $candidates->push([
                'stock_id'        => $stock->id,
                'trade_date'      => $tradeDate,
                'suggested_buy'   => $suggestedBuy,
                'target_price'    => $targetPrice,
                'stop_loss'       => $stopLoss,
                'risk_reward_ratio' => $riskReward,
                'score'           => 0,  // 由 Haiku 預篩後填入信度分數
                'reasons'         => $reasons,
                'indicators'      => $indicators,
                // 5 日均量（用於排序，數字越大流動性越好）
                '_avg_vol5'       => $avgVolume5,
            ]);
        }

        // 依 5 日均量排序，取前 N 名（流動性好的優先讓 AI 看）
        $maxCandidates = $maxCandidatesOverride ?? ($screenConfig['max_candidates'] ?? 80);
        $candidates = $candidates->sortByDesc('_avg_vol5')->take($maxCandidates);

        // 寫入資料庫
        foreach ($candidates as $data) {
            $dbData = $data;
            unset($dbData['_avg_vol5']);
            Candidate::updateOrCreate(
                ['stock_id' => $dbData['stock_id'], 'trade_date' => $dbData['trade_date']],
                $dbData
            );
        }

        return $candidates;
    }

    /**
     * 策略分類：判斷該股當前適合哪種當沖進場策略
     */
    /**
     * 計算建議買入價
     */
    private function calcSuggestedBuy(
        array $closes, array $lows, array $highs,
        array $indicators, array $cfg
    ): float {
        $close = $closes[0];
        $sources = $cfg['sources'] ?? [];
        $supports = [];

        $recentLow = $sources['recent_low'] ?? ['enabled' => true, 'days' => 5];
        if ($recentLow['enabled'] ?? true) {
            $days = $recentLow['days'] ?? 5;
            $supports[] = min(array_slice($lows, 0, $days));
        }

        $maCfg = $sources['ma'] ?? ['enabled' => true, 'period' => 5];
        if ($maCfg['enabled'] ?? true) {
            $period = $maCfg['period'] ?? 5;
            $ma = TechnicalIndicator::sma($closes, $period);
            if ($ma) {
                $supports[] = $ma;
            }
        }

        $bolCfg = $sources['bollinger_middle'] ?? ['enabled' => true];
        if ($bolCfg['enabled'] ?? true) {
            $bollinger = $indicators['bollinger'] ?? null;
            if ($bollinger) {
                $supports[] = $bollinger['middle'];
            }
        }

        $filterLower = $cfg['filter_lower_pct'] ?? 0.95;
        $filterUpper = $cfg['filter_upper_pct'] ?? 1.05;
        $fallback = $cfg['fallback_pct'] ?? 0.99;
        $fallbackPrice = $close * $fallback;

        $validSupports = array_filter($supports, fn ($s) =>
            $s > $close * $filterLower && $s < $close * $filterUpper
        );

        $bestSupport = !empty($validSupports) ? max($validSupports) : $fallbackPrice;
        return round(max($bestSupport, $fallbackPrice), 2);
    }

    private function calcTargetPrice(array $closes, array $highs, ?float $atr, ?array $bollinger, array $cfg, float $suggestedBuy = 0): float
    {
        $close = $closes[0];
        $sources = $cfg['sources'] ?? [];
        $targets = [];

        $recentHigh = $sources['recent_high'] ?? ['enabled' => true, 'days' => 5];
        if ($recentHigh['enabled'] ?? true) {
            $days = $recentHigh['days'] ?? 5;
            $targets[] = max(array_slice($highs, 0, $days));
        }

        $atrCfg = $sources['atr'] ?? ['enabled' => true, 'multiplier' => 1.5];
        if (($atrCfg['enabled'] ?? true) && $atr) {
            $multiplier = $atrCfg['multiplier'] ?? 1.5;
            $targets[] = $close + $atr * $multiplier;
        }

        $bolCfg = $sources['bollinger_upper'] ?? ['enabled' => true];
        if (($bolCfg['enabled'] ?? true) && $bollinger) {
            $targets[] = $bollinger['upper'];
        }

        $filterUpper = $cfg['filter_upper_pct'] ?? 1.10;
        $fallback = $cfg['fallback_pct'] ?? 1.03;

        $base = $suggestedBuy > 0 ? max($close, $suggestedBuy) : $close;
        $validTargets = array_filter($targets, fn ($t) => $t > $base && $t < $close * $filterUpper);

        if (empty($validTargets)) {
            return round($base * $fallback, 2);
        }

        return round(min($validTargets), 2);
    }

    private function calcStopLoss(array $closes, array $lows, ?float $atr, array $cfg): float
    {
        $close = $closes[0];
        $sources = $cfg['sources'] ?? [];
        $fallback = $cfg['fallback_pct'] ?? 0.985;

        $atrCfg = $sources['atr'] ?? ['enabled' => true, 'multiplier' => 1.0];
        if (($atrCfg['enabled'] ?? true) && $atr) {
            $multiplier = $atrCfg['multiplier'] ?? 1.0;
            return round($close - $atr * $multiplier, 2);
        }

        $recentLow = $sources['recent_low'] ?? ['enabled' => true, 'days' => 5];
        if ($recentLow['enabled'] ?? true) {
            $days = $recentLow['days'] ?? 5;
            $low = min(array_slice($lows, 0, $days));
            return round(max($low, $close * $fallback), 2);
        }

        return round($close * $fallback, 2);
    }

    /**
     * 消息面情緒修正係數（只影響價格空間，不做評分）
     */
    private function calcNewsSentimentFactor(
        ?NewsIndex $overall,
        Collection $industries,
        Stock $stock,
        array $cfg
    ): array {
        $result = [
            'price_factor'       => 1.0,
            'overall_sentiment'  => null,
            'industry_sentiment' => null,
            'panic'              => null,
        ];

        if (!$overall) {
            return $result;
        }

        $sentiment = (float) $overall->sentiment;
        $panic = (float) $overall->panic;
        $result['overall_sentiment'] = round($sentiment);
        $result['panic'] = round($panic);

        $bearishBelow  = $cfg['bearish_below']  ?? 40;
        $bullishAbove  = $cfg['bullish_above']  ?? 65;
        $panicAbove    = $cfg['panic_above']    ?? 60;
        $bearishFactor = $cfg['bearish_factor'] ?? 0.90;
        $bullishFactor = $cfg['bullish_factor'] ?? 1.05;
        $panicFactor   = $cfg['panic_factor']   ?? 0.92;

        $factor = 1.0;

        if ($sentiment < $bearishBelow) {
            $factor *= $bearishFactor;
        } elseif ($sentiment > $bullishAbove) {
            $factor *= $bullishFactor;
        }

        if ($panic > $panicAbove) {
            $factor *= $panicFactor;
        }

        $stockIndustry = $stock->industry ?? null;
        if ($stockIndustry && $industries->has($stockIndustry)) {
            $indSentiment = (float) $industries->get($stockIndustry);
            $result['industry_sentiment'] = round($indSentiment);

            $indBearishBelow = $cfg['industry_bearish_below'] ?? 35;
            $indBullishAbove = $cfg['industry_bullish_above'] ?? 65;
            $indFactor       = $cfg['industry_factor']       ?? 0.05;

            if ($indSentiment < $indBearishBelow) {
                $factor -= $indFactor;
            } elseif ($indSentiment > $indBullishAbove) {
                $factor += $indFactor;
            }
        }

        $result['price_factor'] = round(max(0.85, min(1.10, $factor)), 3);

        return $result;
    }

    /**
     * 台股升降單位（tick size）四捨五入
     */
    private function tickRound(float $price, float $refPrice, string $direction = 'nearest'): float
    {
        $tick = match (true) {
            $refPrice < 10   => 0.01,
            $refPrice < 50   => 0.05,
            $refPrice < 100  => 0.10,
            $refPrice < 500  => 0.50,
            $refPrice < 1000 => 1.00,
            default          => 5.00,
        };

        return match ($direction) {
            'up'    => ceil($price / $tick) * $tick,
            'down'  => floor($price / $tick) * $tick,
            default => round($price / $tick) * $tick,
        };
    }

    private function matchRule(ScreeningRule $rule, $quote, $inst, $margin): bool
    {
        foreach ($rule->conditions as $cond) {
            $value = match ($cond['field'] ?? '') {
                'volume'         => ($quote->volume ?? 0) / 1000,
                'amplitude'      => $quote->amplitude ?? 0,
                'change_percent' => $quote->change_percent ?? 0,
                'foreign_net'    => $inst?->foreign_net ?? 0,
                'trust_net'      => $inst?->trust_net ?? 0,
                'total_net'      => $inst?->total_net ?? 0,
                'margin_change'  => $margin?->margin_change ?? 0,
                default          => 0,
            };

            $target = $cond['value'] ?? 0;
            $pass = match ($cond['operator'] ?? '>') {
                '>'  => $value > $target,
                '>=' => $value >= $target,
                '<'  => $value < $target,
                '<=' => $value <= $target,
                default => false,
            };

            if (!$pass) return false;
        }

        return true;
    }
}

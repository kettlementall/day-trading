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
     * 執行選股篩選，產出隔日候選清單
     */
    public function screen(string $tradeDate): Collection
    {
        $stocks = Stock::where('is_day_trading', true)->get();
        $rules = ScreeningRule::where('is_active', true)->orderBy('sort_order')->get();
        $buyConfig = FormulaSetting::getConfig('suggested_buy');
        $targetConfig = FormulaSetting::getConfig('target_price');
        $stopConfig = FormulaSetting::getConfig('stop_loss');
        $strategyConfig = FormulaSetting::getConfig('strategy');
        $scoringConfig = FormulaSetting::getConfig('scoring');
        $newsConfig = FormulaSetting::getConfig('news_sentiment');
        $candidates = collect();

        // 取得最新消息面指數（當日或前一日）
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

            // 基礎門檻：成交量 > 500 張 & 股價 > 10
            if ($volumes[0] / 1000 < 500 || $closes[0] < 10) continue;

            // 計算技術指標
            $ma5 = TechnicalIndicator::sma($closes, 5);
            $ma10 = TechnicalIndicator::sma($closes, 10);
            $ma20 = TechnicalIndicator::sma($closes, 20);
            $rsi = TechnicalIndicator::rsi($closes);
            $kd = TechnicalIndicator::kd($highs, $lows, $closes);
            $atr = TechnicalIndicator::atr($highs, $lows, $closes);
            $bollinger = TechnicalIndicator::bollinger($closes);
            $macd = TechnicalIndicator::macd($closes);

            // 取法人及融資資料
            $inst = InstitutionalTrade::where('stock_id', $stock->id)
                ->orderByDesc('date')->limit(5)->get();
            $margin = MarginTrade::where('stock_id', $stock->id)
                ->orderByDesc('date')->limit(5)->get();

            // 評分 & 理由
            $score = 0;
            $reasons = [];
            $indicators = compact('ma5', 'ma10', 'ma20', 'rsi', 'kd', 'atr', 'bollinger');

            // =========================================
            // 策略分類：跌深反彈 vs 突破追多
            // =========================================
            $strategy = $this->classifyStrategy(
                $closes, $highs, $lows, $opens, $changePcts, $amplitudes,
                $ma5, $ma10, $strategyConfig
            );
            $strategyType = $strategy['type'];
            $strategyDetail = $strategy['detail'];

            // =========================================
            // 評分項目（全部可配置）
            // =========================================

            $sc = fn (string $key) => $scoringConfig[$key] ?? [];

            // 1. 量能評分
            $cfg = $sc('volume_surge');
            if ($cfg['enabled'] ?? true) {
                $ratio = $cfg['ratio'] ?? 1.5;
                $avgVol5 = array_sum(array_slice($volumes, 0, 5)) / 5;
                if ($volumes[0] > $avgVol5 * $ratio) {
                    $score += $cfg['score'] ?? 15;
                    $reasons[] = '量能放大';
                }
            }

            // 2. 均線多頭排列
            $cfg = $sc('ma_bullish');
            if (($cfg['enabled'] ?? true) && $ma5 && $ma10 && $ma20 && $ma5 > $ma10 && $ma10 > $ma20) {
                $score += $cfg['score'] ?? 15;
                $reasons[] = '均線多頭';
            }

            // 3. 站上5MA
            $cfg = $sc('above_ma5');
            if (($cfg['enabled'] ?? true) && $ma5 && $closes[0] > $ma5) {
                $score += $cfg['score'] ?? 5;
            }

            // 4. KD 黃金交叉
            $cfg = $sc('kd_golden_cross');
            if (($cfg['enabled'] ?? true) && $kd && $kd['k'] > $kd['d'] && $kd['k'] < 80) {
                $score += $cfg['score'] ?? 10;
                $reasons[] = 'KD黃金交叉';
            }

            // 5. RSI 適中
            $cfg = $sc('rsi_moderate');
            if (($cfg['enabled'] ?? true) && $rsi) {
                $rsiMin = $cfg['min'] ?? 40;
                $rsiMax = $cfg['max'] ?? 70;
                if ($rsi >= $rsiMin && $rsi <= $rsiMax) {
                    $score += $cfg['score'] ?? 5;
                }
            }

            // 6. 外資買超
            $cfg = $sc('foreign_buy');
            if (($cfg['enabled'] ?? true) && $inst->isNotEmpty() && $inst->first()->foreign_net > 0) {
                $score += $cfg['score'] ?? 10;
                $reasons[] = '外資買超';
            }

            // 7. 法人連續買超
            $cfg = $sc('consecutive_buy');
            if ($cfg['enabled'] ?? true) {
                $minDays = $cfg['min_days'] ?? 3;
                $consecutiveBuy = 0;
                foreach ($inst as $t) {
                    if ($t->total_net > 0) $consecutiveBuy++;
                    else break;
                }
                if ($consecutiveBuy >= $minDays) {
                    $score += $cfg['score'] ?? 10;
                    $reasons[] = "法人連{$consecutiveBuy}買";
                }
            }

            // 8. 投信買超
            $cfg = $sc('trust_buy');
            if (($cfg['enabled'] ?? true) && $inst->isNotEmpty() && $inst->first()->trust_net > 0) {
                $score += $cfg['score'] ?? 5;
                $reasons[] = '投信買超';
            }

            // 9. 融資減少
            $cfg = $sc('margin_decrease');
            if (($cfg['enabled'] ?? true) && $margin->isNotEmpty() && $margin->first()->margin_change < 0) {
                $score += $cfg['score'] ?? 5;
                $reasons[] = '融資減';
            }

            // 10. 振幅適中
            $cfg = $sc('amplitude_moderate');
            if ($cfg['enabled'] ?? true) {
                $ampMin = $cfg['min'] ?? 2;
                $ampMax = $cfg['max'] ?? 7;
                if ($latest->amplitude >= $ampMin && $latest->amplitude <= $ampMax) {
                    $score += $cfg['score'] ?? 5;
                    $reasons[] = '振幅適中';
                }
            }

            // 11. 突破前高
            $cfg = $sc('break_prev_high');
            if ($cfg['enabled'] ?? true) {
                $prev5High = max(array_slice($highs, 1, 5));
                if ($closes[0] > $prev5High) {
                    $score += $cfg['score'] ?? 10;
                    $reasons[] = '突破前高';
                }
            }

            // 12. 布林通道位置
            $cfg = $sc('bollinger_position');
            if (($cfg['enabled'] ?? true) && $bollinger && $closes[0] > $bollinger['middle'] && $closes[0] < $bollinger['upper']) {
                $score += $cfg['score'] ?? 5;
                $reasons[] = '布林中軌上方';
            }

            // 13. 高波動當沖適性
            $cfg = $sc('high_volatility');
            if ($cfg['enabled'] ?? true) {
                $lookback = $cfg['lookback_days'] ?? 10;
                $minAmp = $cfg['min_amplitude'] ?? 5;
                $avgAmp = count($amplitudes) >= $lookback
                    ? array_sum(array_slice($amplitudes, 0, $lookback)) / $lookback
                    : 0;
                if ($avgAmp >= $minAmp) {
                    $score += $cfg['score'] ?? 10;
                    $reasons[] = '高波動';
                    $strategyDetail['avg_amplitude_10d'] = round($avgAmp, 2);
                }
            }

            // 14. 近期強勢趨勢
            $cfg = $sc('strong_trend');
            if ($cfg['enabled'] ?? true) {
                $lookback = $cfg['lookback_days'] ?? 20;
                $minGain = $cfg['min_gain_pct'] ?? 15;
                if (count($closes) > $lookback && $closes[$lookback] > 0) {
                    $gain = ($closes[0] - $closes[$lookback]) / $closes[$lookback] * 100;
                    if ($gain > $minGain) {
                        $score += $cfg['score'] ?? 10;
                        $reasons[] = '近月強勢';
                        $strategyDetail['gain_20d'] = round($gain, 1);
                    }
                }
            }

            // 15. 跌深反彈型
            $bounceCfg = $strategyConfig['bounce'] ?? [];
            if (($bounceCfg['enabled'] ?? true) && $strategyType === 'bounce') {
                $score += $bounceCfg['score'] ?? 15;
                $reasons[] = '跌深反彈';
            }

            // 16. 突破追多型
            $breakoutCfg = $strategyConfig['breakout'] ?? [];
            if (($breakoutCfg['enabled'] ?? true) && $strategyType === 'breakout') {
                $score += $breakoutCfg['score'] ?? 15;
                $reasons[] = '突破追多';
            }

            // 17. 外資大買
            $cfg = $sc('foreign_big_buy');
            if (($cfg['enabled'] ?? true) && $inst->isNotEmpty()) {
                $ratio = $cfg['volume_ratio'] ?? 0.05;
                $latestInst = $inst->first();
                if ($latestInst->foreign_net > $volumes[0] * $ratio) {
                    $score += $cfg['score'] ?? 5;
                    $reasons[] = '外資大買';
                    $strategyDetail['foreign_net_ratio'] = round($latestInst->foreign_net / $volumes[0] * 100, 1);
                }
            }

            // 18. 自營大買
            $cfg = $sc('dealer_big_buy');
            if (($cfg['enabled'] ?? true) && $inst->isNotEmpty()) {
                $ratio = $cfg['volume_ratio'] ?? 0.03;
                $latestInst = $inst->first();
                if ($latestInst->dealer_net > $volumes[0] * $ratio) {
                    $score += $cfg['score'] ?? 5;
                    $reasons[] = '自營大買';
                    $strategyDetail['dealer_net_ratio'] = round($latestInst->dealer_net / $volumes[0] * 100, 1);
                }
            }

            // 19. 萬張量能
            $cfg = $sc('high_volume');
            if ($cfg['enabled'] ?? true) {
                $minLots = $cfg['min_lots'] ?? 10000;
                if ($volumes[0] / 1000 >= $minLots) {
                    $score += $cfg['score'] ?? 5;
                    $reasons[] = '萬張量能';
                }
            }

            // 20. 消息面情緒修正
            $newsFactor = $this->calcNewsSentimentFactor(
                $newsOverall, $newsIndustries, $stock, $newsConfig
            );
            if ($newsFactor['score_adj'] !== 0) {
                $score += $newsFactor['score_adj'];
                if ($newsFactor['score_adj'] > 0) {
                    $reasons[] = '消息面偏多';
                } else {
                    $reasons[] = '消息面偏空';
                }
            }
            $strategyDetail['news_factor'] = $newsFactor['price_factor'];
            if ($newsFactor['overall_sentiment'] !== null) {
                $strategyDetail['news_sentiment'] = $newsFactor['overall_sentiment'];
            }
            if ($newsFactor['industry_sentiment'] !== null) {
                $strategyDetail['news_industry_sentiment'] = $newsFactor['industry_sentiment'];
            }
            if ($newsFactor['panic'] !== null) {
                $strategyDetail['news_panic'] = $newsFactor['panic'];
            }

            // 套用自訂規則
            foreach ($rules as $rule) {
                if ($this->matchRule($rule, $latest, $inst->first(), $margin->first())) {
                    $score += 5;
                }
            }

            // 最低門檻
            if ($score < 30 || empty($reasons)) continue;

            // 計算建議價格（依策略類型調整）
            $priceFactor = $newsFactor['price_factor'];
            $suggestedBuy = $this->calcSuggestedBuy(
                $closes, $lows, $highs, $indicators, $buyConfig, $strategyType
            );
            $targetPrice = $this->calcTargetPrice($closes, $highs, $atr, $bollinger, $targetConfig);
            $stopLoss = $this->calcStopLoss($closes, $lows, $atr, $stopConfig);

            // 消息面修正：偏空壓低目標、收緊停損；偏多略放寬目���
            if ($priceFactor !== 1.0) {
                $targetPrice = round($suggestedBuy + ($targetPrice - $suggestedBuy) * $priceFactor, 2);
                // 偏空時停損也收緊（空間縮小）
                if ($priceFactor < 1.0) {
                    $stopLoss = round($suggestedBuy - ($suggestedBuy - $stopLoss) * (2.0 - $priceFactor), 2);
                }
            }

            $profitSpace = $targetPrice - $suggestedBuy;
            $lossSpace = $suggestedBuy - $stopLoss;
            $riskReward = $lossSpace > 0 ? round($profitSpace / $lossSpace, 2) : 0;

            // 風報比 < 1.5 不列入
            if ($riskReward < 1.5) continue;

            $candidates->push([
                'stock_id' => $stock->id,
                'trade_date' => $tradeDate,
                'suggested_buy' => $suggestedBuy,
                'target_price' => $targetPrice,
                'stop_loss' => $stopLoss,
                'risk_reward_ratio' => $riskReward,
                'score' => min($score, 100),
                'strategy_type' => $strategyType,
                'strategy_detail' => $strategyDetail,
                'reasons' => $reasons,
                'indicators' => $indicators,
            ]);
        }

        // 依分數排序，取前 20 名
        $candidates = $candidates->sortByDesc('score')->take(20);

        // 寫入資料庫
        foreach ($candidates as $data) {
            Candidate::updateOrCreate(
                ['stock_id' => $data['stock_id'], 'trade_date' => $data['trade_date']],
                $data
            );
        }

        return $candidates;
    }

    /**
     * 策略分類：判斷該股當前適合哪種當沖進場策略
     */
    private function classifyStrategy(
        array $closes, array $highs, array $lows, array $opens,
        array $changePcts, array $amplitudes,
        ?float $ma5, ?float $ma10,
        array $strategyConfig
    ): array {
        $close = $closes[0];
        $detail = [];

        $bounceCfg = $strategyConfig['bounce'] ?? [];
        $breakoutCfg = $strategyConfig['breakout'] ?? [];

        // --- 檢測跌深反彈 ---
        $isBounce = false;
        if ($bounceCfg['enabled'] ?? true) {
            $washoutPct = $bounceCfg['washout_drop_pct'] ?? -5;
            $twoDayPct = $bounceCfg['two_day_drop_pct'] ?? -7;
            $lookback = $bounceCfg['washout_lookback_days'] ?? 3;
            $bounceMinPct = $bounceCfg['bounce_from_low_pct'] ?? 3;

            $recentDrop = $changePcts[0] ?? 0;
            $prevDrop = $changePcts[1] ?? 0;
            $twoDayDrop = $recentDrop + $prevDrop;

            // 前N日有急跌
            $hasWashout = false;
            $washoutDay = null;
            for ($i = 0; $i < $lookback; $i++) {
                if (isset($changePcts[$i]) && $changePcts[$i] <= $washoutPct) {
                    $hasWashout = true;
                    $washoutDay = $i;
                    break;
                }
            }
            if (!$hasWashout && $twoDayDrop <= $twoDayPct) {
                $hasWashout = true;
                $washoutDay = -1;
            }

            $hasBounce = false;
            if ($hasWashout) {
                $isRedCandle = $close > ($closes[1] ?? $close);
                $open = $opens[0] ?? $close;
                $lowerShadow = min($close, $open) - $lows[0];
                $bodySize = abs($close - $open);
                $hasLongLowerShadow = $bodySize > 0 ? ($lowerShadow / $bodySize) > 1.5 : false;

                if ($washoutDay === 0) {
                    $hasBounce = false;
                    $detail['washout_today'] = true;
                } else {
                    $hasBounce = $isRedCandle || $hasLongLowerShadow;
                }
            }

            $bounceFromLow = false;
            if ($hasWashout && count($lows) >= 3) {
                $recentLow = min(array_slice($lows, 0, 3));
                $bounceRatio = $recentLow > 0 ? ($close - $recentLow) / $recentLow * 100 : 0;
                if ($bounceRatio >= $bounceMinPct) {
                    $bounceFromLow = true;
                    $detail['bounce_from_low_pct'] = round($bounceRatio, 1);
                }
            }

            $isBounce = $hasWashout && ($hasBounce || $bounceFromLow);

            if ($hasWashout) {
                $detail['washout'] = true;
                $detail['washout_day'] = $washoutDay;
                if ($washoutDay >= 0) {
                    $detail['washout_drop_pct'] = $changePcts[$washoutDay];
                } else {
                    $detail['two_day_drop_pct'] = round($twoDayDrop, 2);
                }
            }
        }

        // --- 檢測突破追多 ---
        $isBreakout = false;
        if ($breakoutCfg['enabled'] ?? true) {
            $prevDays = $breakoutCfg['prev_high_days'] ?? 5;
            $nearPct = $breakoutCfg['near_breakout_pct'] ?? 0.98;

            $prev5High = count($highs) > $prevDays ? max(array_slice($highs, 1, $prevDays)) : 0;
            $prev10High = count($highs) >= 11 ? max(array_slice($highs, 1, 10)) : 0;
            $nearBreakout = $prev5High > 0 && $close >= $prev5High * $nearPct;
            $aboveMa5 = $ma5 && $close > $ma5;
            $isBreakout = $nearBreakout && $aboveMa5;

            if ($isBreakout) {
                $detail['prev_5d_high'] = $prev5High;
                $detail['prev_10d_high'] = $prev10High;
                $detail['close_vs_prev_high_pct'] = round(($close / $prev5High - 1) * 100, 2);
            }
        }

        if ($isBounce) {
            return ['type' => 'bounce', 'detail' => $detail];
        }
        if ($isBreakout) {
            return ['type' => 'breakout', 'detail' => $detail];
        }

        return ['type' => null, 'detail' => $detail];
    }

    /**
     * 計算建議買入價
     */
    private function calcSuggestedBuy(
        array $closes, array $lows, array $highs,
        array $indicators, array $cfg, ?string $strategyType
    ): float {
        $close = $closes[0];
        $sources = $cfg['sources'] ?? [];

        // === 策略導向定價 ===
        if ($strategyType === 'bounce') {
            $ma10 = $indicators['ma10'] ?? null;
            if ($ma10 && $ma10 > $close * 0.95 && $ma10 < $close * 1.07) {
                return round($ma10, 2);
            }
            $ma5 = $indicators['ma5'] ?? null;
            if ($ma5 && $ma5 > $close * 0.95 && $ma5 < $close * 1.05) {
                return round($ma5, 2);
            }
        }

        if ($strategyType === 'breakout') {
            if (count($highs) >= 6) {
                $prevHigh = max(array_slice($highs, 1, 5));
                if ($prevHigh > $close * 0.98 && $prevHigh < $close * 1.08) {
                    return round($prevHigh, 2);
                }
            }
        }

        // === 通用邏輯 ===
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
        $fallback = $cfg['fallback_pct'] ?? 0.99;

        $validSupports = array_filter($supports, fn ($s) => $s < $close && $s > $close * $filterLower);

        if (empty($validSupports)) {
            return round($close * $fallback, 2);
        }

        return round(max($validSupports), 2);
    }

    private function calcTargetPrice(array $closes, array $highs, ?float $atr, ?array $bollinger, array $cfg): float
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

        $validTargets = array_filter($targets, fn ($t) => $t > $close && $t < $close * $filterUpper);

        if (empty($validTargets)) {
            return round($close * $fallback, 2);
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
     * 消息面情緒修正係數
     *
     * 根據整體情緒、恐慌指標、產業情緒計算：
     * - price_factor: 目標價獲利空間的修正倍率 (0.85 ~ 1.10)
     * - score_adj: 評分加減分 (-10 ~ +10)
     */
    private function calcNewsSentimentFactor(
        ?NewsIndex $overall,
        Collection $industries,
        Stock $stock,
        array $cfg
    ): array {
        $result = [
            'price_factor' => 1.0,
            'score_adj' => 0,
            'overall_sentiment' => null,
            'industry_sentiment' => null,
            'panic' => null,
        ];

        if (!$overall) {
            return $result;
        }

        $sentiment = (float) $overall->sentiment;
        $panic = (float) $overall->panic;
        $result['overall_sentiment'] = round($sentiment);
        $result['panic'] = round($panic);

        // 可配置的閾值，有預設值
        $bearishBelow = $cfg['bearish_below'] ?? 40;
        $bullishAbove = $cfg['bullish_above'] ?? 65;
        $panicAbove = $cfg['panic_above'] ?? 60;
        $bearishFactor = $cfg['bearish_factor'] ?? 0.90;
        $bullishFactor = $cfg['bullish_factor'] ?? 1.05;
        $panicFactor = $cfg['panic_factor'] ?? 0.92;
        $bearishScore = $cfg['bearish_score'] ?? -10;
        $bullishScore = $cfg['bullish_score'] ?? 10;

        $factor = 1.0;
        $scoreAdj = 0;

        // 整體情緒
        if ($sentiment < $bearishBelow) {
            $factor *= $bearishFactor;
            $scoreAdj += $bearishScore;
        } elseif ($sentiment > $bullishAbove) {
            $factor *= $bullishFactor;
            $scoreAdj += $bullishScore;
        }

        // 恐慌指標高 → 額外壓低
        if ($panic > $panicAbove) {
            $factor *= $panicFactor;
            $scoreAdj += round($bearishScore / 2);
        }

        // 產業情緒（若該股有對應產業）
        $stockIndustry = $stock->industry ?? null;
        if ($stockIndustry && $industries->has($stockIndustry)) {
            $indSentiment = (float) $industries->get($stockIndustry);
            $result['industry_sentiment'] = round($indSentiment);

            $indBearishBelow = $cfg['industry_bearish_below'] ?? 35;
            $indBullishAbove = $cfg['industry_bullish_above'] ?? 65;
            $indFactor = $cfg['industry_factor'] ?? 0.05;

            if ($indSentiment < $indBearishBelow) {
                $factor -= $indFactor;
                $scoreAdj -= 5;
            } elseif ($indSentiment > $indBullishAbove) {
                $factor += $indFactor;
                $scoreAdj += 5;
            }
        }

        // 限制修正範圍
        $result['price_factor'] = round(max(0.85, min(1.10, $factor)), 3);
        $result['score_adj'] = max(-15, min(15, $scoreAdj));

        return $result;
    }

    private function matchRule(ScreeningRule $rule, $quote, $inst, $margin): bool
    {
        foreach ($rule->conditions as $cond) {
            $value = match ($cond['field'] ?? '') {
                'volume' => ($quote->volume ?? 0) / 1000,
                'amplitude' => $quote->amplitude ?? 0,
                'change_percent' => $quote->change_percent ?? 0,
                'foreign_net' => $inst?->foreign_net ?? 0,
                'trust_net' => $inst?->trust_net ?? 0,
                'total_net' => $inst?->total_net ?? 0,
                'margin_change' => $margin?->margin_change ?? 0,
                default => 0,
            };

            $target = $cond['value'] ?? 0;
            $pass = match ($cond['operator'] ?? '>') {
                '>' => $value > $target,
                '>=' => $value >= $target,
                '<' => $value < $target,
                '<=' => $value <= $target,
                default => false,
            };

            if (!$pass) return false;
        }

        return true;
    }
}

<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\DailyQuote;
use App\Models\FormulaSetting;
use App\Models\InstitutionalTrade;
use App\Models\MarginTrade;
use App\Models\NewsIndex;
use App\Models\Stock;
use App\Services\MarketContextService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class StockScreener
{
    /**
     * 執行選股篩選：只做物理不可能門檻 + 技術資料計算，交由 AI 判斷品質
     */
    public function screen(
        string $tradeDate,
        ?int $minScoreOverride = null,
        ?int $maxCandidatesOverride = null,
        string $mode = 'intraday'
    ): Collection {
        $stocks = Stock::where('is_day_trading', true)->get();
        $buyConfig = FormulaSetting::getConfig('suggested_buy');
        $targetConfig = FormulaSetting::getConfig('target_price');
        $stopConfig = FormulaSetting::getConfig('stop_loss');

        // 市場情境偵測（僅 intraday）
        $marketContext = $mode === 'intraday' ? MarketContextService::detect($tradeDate) : null;
        $isBullishCatalyst = $marketContext && MarketContextService::isBullishCatalyst($marketContext);
        if ($isBullishCatalyst) {
            Log::info("StockScreener: 利多催化日，將放寬超跌受益產業篩選");
        }
        // overnight 模式使用獨立設定 key，不存在時 fallback 到 intraday 設定
        $screenConfigKey = $mode === 'overnight' ? 'screen_thresholds_overnight' : 'screen_thresholds';
        $screenConfig = FormulaSetting::getConfig($screenConfigKey)
            ?: FormulaSetting::getConfig('screen_thresholds');
        $newsConfig = FormulaSetting::getConfig('news_sentiment');
        $labelConfig = FormulaSetting::getConfig('signal_labels');
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

            // 5 日均振幅太低 → 波動不夠當沖（當沖 2.5%，隔日沖 0.5%）
            $recent5Amplitudes = array_slice($amplitudes, 0, 5);
            $avgAmplitude5 = count($recent5Amplitudes) > 0 ? array_sum($recent5Amplitudes) / count($recent5Amplitudes) : 0;
            $defaultMinAmp = $mode === 'overnight' ? 0.5 : 2.5;
            $minAmplitude = $screenConfig['min_amplitude'] ?? $defaultMinAmp;
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
            // 事實標籤（讓 AI 快速掌握關鍵信號，參數可在設定頁調整）
            // =========================================
            $reasons = [];

            // overnight 專用標籤（法人連買3日、蓄勢整理、強勢排列）
            if ($mode === 'overnight') {
                // 法人連買3日：最近 3 日外資淨買均 > 0
                if ($inst->count() >= 3) {
                    $recentInst = $inst->take(3);
                    if ($recentInst->every(fn($i) => (float)$i->foreign_net > 0)) {
                        $reasons[] = '法人連買3日';
                    }
                }

                // 蓄勢整理：近 3 日振幅均 < 2%，且收盤在 MA5 ±1% 範圍內
                $recent3Amp = array_slice($amplitudes, 0, 3);
                $allLowAmp = !empty($recent3Amp) && max($recent3Amp) < 2.0;
                $nearMa5   = $ma5 && abs($closes[0] - $ma5) / $ma5 < 0.01;
                if ($allLowAmp && $nearMa5) {
                    $reasons[] = '蓄勢整理';
                }

                // 強勢排列：MA5 > MA10 > MA20 且收盤 > MA5
                if ($ma5 && $ma10 && $ma20 && $ma5 > $ma10 && $ma10 > $ma20 && $closes[0] > $ma5) {
                    $reasons[] = '強勢排列';
                }

                // 空頭排列（持有過夜風險高）
                if ($ma5 && $ma10 && $ma20 && $ma5 < $ma10 && $ma10 < $ma20 && $closes[0] < $ma5) {
                    $reasons[] = '空頭排列';
                }

                // 均線糾結（方向不明）
                if ($ma5 && $ma10 && $ma20) {
                    $spread = max($ma5, $ma10, $ma20) - min($ma5, $ma10, $ma20);
                    if ($closes[0] > 0 && ($spread / $closes[0]) < 0.015) {
                        $reasons[] = '均線糾結';
                    }
                }

                // 記錄 MA alignment code 供下游讀取
                $maAlign = TechnicalIndicator::maAlignment($ma5, $ma10, $ma20, $closes[0]);
                if ($maAlign) {
                    $indicators['ma_alignment'] = $maAlign['code'];
                }
            }

            // intraday：MA 排列標籤（幫助 Haiku 快篩 + IntradayAiAdvisor 讀取）
            if ($mode === 'intraday') {
                $maAlign = TechnicalIndicator::maAlignment($ma5, $ma10, $ma20, $closes[0]);
                if ($maAlign) {
                    $reasons[] = $maAlign['label'];
                    $indicators['ma_alignment'] = $maAlign['code'];
                }
            }

            // 量放大
            $cfg = $labelConfig['volume_surge'] ?? [];
            if ($cfg['enabled'] ?? true) {
                $days = $cfg['days'] ?? 5;
                $multiplier = $cfg['multiplier'] ?? 1.5;
                $avgVol = array_sum(array_slice($volumes, 0, $days)) / max($days, 1);
                if ($volumes[0] > $avgVol * $multiplier) $reasons[] = $cfg['label'] ?? '量放大';
            }

            // 外資買超
            $cfg = $labelConfig['foreign_buy'] ?? [];
            if (($cfg['enabled'] ?? true) && $inst->isNotEmpty()) {
                $minNet = $cfg['min_net'] ?? 0;
                if ((float) $inst->first()->foreign_net > $minNet) $reasons[] = $cfg['label'] ?? '外資買超';
            }

            // 投信買超
            $cfg = $labelConfig['trust_buy'] ?? [];
            if (($cfg['enabled'] ?? true) && $inst->isNotEmpty()) {
                $minNet = $cfg['min_net'] ?? 0;
                if ((float) $inst->first()->trust_net > $minNet) $reasons[] = $cfg['label'] ?? '投信買超';
            }

            // 突破前高
            $cfg = $labelConfig['breakout_high'] ?? [];
            if ($cfg['enabled'] ?? true) {
                $days = $cfg['days'] ?? 5;
                $prevHighArr = count($highs) >= $days + 1 ? array_slice($highs, 1, $days) : [];
                $prevHigh = !empty($prevHighArr) ? max($prevHighArr) : 0;
                if ($prevHigh > 0 && $closes[0] > $prevHigh) $reasons[] = $cfg['label'] ?? '突破前高';
            }

            // 融資減
            $cfg = $labelConfig['margin_decrease'] ?? [];
            if (($cfg['enabled'] ?? true) && $margin->isNotEmpty()) {
                $maxChange = $cfg['max_change'] ?? 0;
                if ((float) $margin->first()->margin_change < $maxChange) $reasons[] = $cfg['label'] ?? '融資減';
            }

            // 利多催化日：標記超跌標的為 gap_reversal 候選
            $isGapReversalCandidate = false;
            if ($isBullishCatalyst && $mode === 'intraday') {
                $recent5Chg = array_sum(array_slice($changePcts, 0, 5));
                $industry = $stock->industry ?? '';
                $hasIndustry = !empty($industry);
                $isBeneficiary = MarketContextService::isBeneficiaryIndustry($industry, $marketContext);

                if ($hasIndustry) {
                    // 有產業標籤：超跌（5日跌>8%）+ 受益產業
                    if ($recent5Chg < -8.0 && $isBeneficiary) {
                        $reasons[] = '超跌反彈候選';
                        $isGapReversalCandidate = true;
                    }
                    // 中度超跌（5日跌>5%）+ 受益產業 + 法人買盤
                    elseif ($recent5Chg < -5.0 && $isBeneficiary && $inst->isNotEmpty()
                        && (float) $inst->first()->foreign_net > 0) {
                        $reasons[] = '超跌反彈候選';
                        $isGapReversalCandidate = true;
                    }
                } else {
                    // 無產業標籤：門檻拉高（5日跌>10%），交由 AI 判斷產業關聯
                    if ($recent5Chg < -10.0) {
                        $reasons[] = '超跌反彈候選';
                        $isGapReversalCandidate = true;
                    }
                    // 或 5日跌>7% + 有外資回補
                    elseif ($recent5Chg < -7.0 && $inst->isNotEmpty()
                        && (float) $inst->first()->foreign_net > 0) {
                        $reasons[] = '超跌反彈候選';
                        $isGapReversalCandidate = true;
                    }
                }
            }

            // =========================================
            // 參考價格計算（AI 可覆寫）
            // overnight 模式：Opus 全責設定三個價格，此處不計算
            // =========================================

            if ($mode === 'overnight') {
                // overnight：價格全部留 null，等 Opus 精審時覆寫
                $suggestedBuy = null;
                $targetPrice  = null;
                $stopLoss     = null;
                $riskReward   = null;
            } else {
                // intraday：維持原有公式計算邏輯
                $newsFactor = $this->calcNewsSentimentFactor(
                    $newsOverall, $newsIndustries, $stock, $newsConfig
                );
                $priceFactor = $newsFactor['price_factor'];

                $prevClose = $closes[0];
                $limitUp   = $this->tickRound($prevClose * 1.10, $prevClose, 'down');
                $limitDown = $this->tickRound($prevClose * 0.90, $prevClose, 'up');

                $suggestedBuy = $this->calcSuggestedBuy($closes, $lows, $highs, $indicators, $buyConfig);
                $targetPrice  = $this->calcTargetPrice($closes, $highs, $atr, $bollinger, $targetConfig, $suggestedBuy);
                $stopLoss     = $this->calcStopLoss($closes, $lows, $atr, $stopConfig);

                if ($priceFactor !== 1.0) {
                    $targetPrice = round($suggestedBuy + ($targetPrice - $suggestedBuy) * $priceFactor, 2);
                    if ($priceFactor < 1.0) {
                        $stopLoss = round($suggestedBuy - ($suggestedBuy - $stopLoss) * (2.0 - $priceFactor), 2);
                    }
                }

                $suggestedBuy = max($limitDown, min($limitUp, $suggestedBuy));
                $targetPrice  = max($limitDown, min($limitUp, $targetPrice));
                $stopLoss     = max($limitDown, min($limitUp, $stopLoss));

                $profitSpace = $targetPrice - $suggestedBuy;
                $lossSpace   = $suggestedBuy - $stopLoss;
                $riskReward  = $lossSpace > 0 ? round($profitSpace / $lossSpace, 2) : 0;

                // 風報比絕對底線（overnight 模式跳過此篩選）
                // gap_reversal 候選跳空格局下 RR 公式不準（buy 基於昨收支撐，實際進場會高很多）
                $minRR = $screenConfig['min_risk_reward'] ?? 0.8;
                if ($riskReward < $minRR && !$isGapReversalCandidate) continue;
            }

            $candidates->push([
                'stock_id'          => $stock->id,
                'trade_date'        => $tradeDate,
                'mode'              => $mode,
                'suggested_buy'     => $suggestedBuy,
                'target_price'      => $targetPrice,
                'stop_loss'         => $stopLoss,
                'risk_reward_ratio' => $riskReward,
                'score'             => 0,
                'reasons'           => $reasons,
                'indicators'        => $indicators,
                '_avg_vol5'         => $avgVolume5,
                '_gap_reversal'     => $isGapReversalCandidate,
            ]);
        }

        // 依 5 日均量排序，取前 N 名（流動性好的優先讓 AI 看）
        // gap_reversal 候選保證入選（不被均量排序截斷）
        $maxCandidates = $maxCandidatesOverride ?? ($screenConfig['max_candidates'] ?? 80);
        $gapReversalCandidates = $candidates->filter(fn($c) => $c['_gap_reversal'] ?? false);
        $normalCandidates = $candidates->filter(fn($c) => !($c['_gap_reversal'] ?? false));

        $normalSlots = max(0, $maxCandidates - $gapReversalCandidates->count());
        $selected = $normalCandidates->sortByDesc('_avg_vol5')->take($normalSlots);
        $candidates = $selected->concat($gapReversalCandidates)->values();

        if ($gapReversalCandidates->isNotEmpty()) {
            Log::info("StockScreener: gap_reversal 候選 {$gapReversalCandidates->count()} 檔保證入選");
        }

        // 寫入資料庫
        $stockIds = [];
        foreach ($candidates as $data) {
            $dbData = $data;
            unset($dbData['_avg_vol5'], $dbData['_gap_reversal']);
            Candidate::updateOrCreate(
                ['stock_id' => $dbData['stock_id'], 'trade_date' => $dbData['trade_date'], 'mode' => $dbData['mode']],
                $dbData
            );
            $stockIds[] = $dbData['stock_id'];
        }

        // 回傳 Eloquent 模型（HaikuPreFilterService 需要 ->update() 方法）
        return Candidate::with('stock')
            ->where('trade_date', $tradeDate)
            ->where('mode', $mode)
            ->whereIn('stock_id', $stockIds)
            ->get();
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

}

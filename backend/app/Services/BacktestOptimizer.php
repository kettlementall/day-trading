<?php

namespace App\Services;

use App\Models\BacktestRound;
use App\Models\FormulaSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BacktestOptimizer
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key', '');
        $this->model = config('services.anthropic.model', 'claude-haiku-4-5-20251001');
    }

    /**
     * 執行回測分析並取得 AI 優化建議
     */
    public function analyze(string $from, string $to): BacktestRound
    {
        $backtestService = new BacktestService();
        $metrics = $backtestService->computeMetrics($from, $to);

        // 取得目前公式參數
        $currentSettings = [];
        foreach (['suggested_buy', 'target_price', 'stop_loss', 'strategy', 'scoring', 'news_sentiment', 'screen_thresholds'] as $type) {
            $currentSettings[$type] = FormulaSetting::getConfig($type);
        }

        // 呼叫 AI 取得建議
        $suggestions = $this->getAiSuggestions($metrics, $currentSettings);

        return BacktestRound::create([
            'analyzed_from' => $from,
            'analyzed_to' => $to,
            'sample_count' => $metrics['evaluated'],
            'metrics_before' => $metrics,
            'suggestions' => $suggestions,
        ]);
    }

    /**
     * 套用某次回測的建議到 FormulaSetting
     */
    public function applyRound(BacktestRound $round): void
    {
        $adjustments = $round->suggestions['adjustments'] ?? [];

        // 安全檢查：驗證價格參數不會造成 RR 崩潰
        $adjustments = $this->sanitizePriceAdjustments($adjustments);

        foreach ($adjustments as $type => $changes) {
            $setting = FormulaSetting::where('type', $type)->first();
            if (!$setting) continue;

            $config = $setting->config;
            $config = $this->mergeNestedConfig($config, $changes);
            $setting->update(['config' => $config]);
        }

        $round->update([
            'applied' => true,
            'applied_at' => now(),
        ]);
    }

    /**
     * 帶驗證的優化循環：AI 建議 → 套用 → 重跑 → 比較 → 若退步則回滾重試，最多 maxAttempts 次
     */
    public function optimizeWithValidation(string $from, string $to, int $maxAttempts = 10, ?\Closure $logger = null): array
    {
        $log = $logger ?? function (string $msg) { Log::info($msg); };
        $backtestService = new BacktestService();

        // 1. 保存原始參數
        $originalSettings = $this->snapshotSettings();

        // 2. 用目前參數重跑一次取得 baseline
        $log("▎ 重跑 baseline（{$from} ~ {$to}）...");
        $baselineMetrics = $backtestService->rescreen($from, $to);
        $log($this->formatMetricsSummary('Baseline', $baselineMetrics));

        $bestMetrics = $baselineMetrics;
        $bestSettings = $originalSettings;
        $rounds = [];

        try {
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $log("\n▎ 第 {$attempt}/{$maxAttempts} 次嘗試...");

                // 3. 回滾到最佳參數（確保 AI 基於正確的參數分析）
                $this->restoreSettings($bestSettings);

                // 4. 重跑以取得當前指標（供 AI 分析用）
                $currentMetrics = $backtestService->rescreen($from, $to);

                // 5. AI 分析並建議
                $round = $this->analyze($from, $to);
                $rounds[] = $round;
                $suggestions = $round->suggestions;
                $focus = $suggestions['focus'] ?? 'unknown';
                $log("  AI 建議（{$focus}）：{$suggestions['analysis']}");

                if (empty($suggestions['adjustments'])) {
                    $log("  AI 無建議調整，結束。");
                    break;
                }

                // 6. 套用建議
                $this->applyRound($round);
                $log("  已套用建議，重跑驗證...");

                // 7. 重跑取得新指標
                $newMetrics = $backtestService->rescreen($from, $to);
                $log($this->formatMetricsSummary('新指標', $newMetrics));

                // 8. 更新 round 的 metrics_after
                $round->update(['metrics_after' => $newMetrics]);

                // 9. 比較是否改善
                if ($this->isImproved($bestMetrics, $newMetrics)) {
                    $log("  ✓ 指標改善！採用此調整。");
                    $bestMetrics = $newMetrics;
                    $bestSettings = $this->snapshotSettings();
                } else {
                    $log("  ✗ 指標未改善，回滾。");
                    $round->update(['applied' => false, 'applied_at' => null]);
                }
            }
        } catch (\Exception $e) {
            Log::error("BacktestOptimizer: 優化循環異常，回滾至最佳參數: {$e->getMessage()}");
            $log("\n▎ 錯誤：{$e->getMessage()}，回滾至最佳參數...");
        }

        // 10. 一定會執行：還原最佳參數並重跑，確保資料一致
        $this->restoreSettings($bestSettings);
        $backtestService->rescreen($from, $to);

        $improved = $this->isImproved($baselineMetrics, $bestMetrics);
        $log("\n▎ 最終結果：" . ($improved ? '已改善' : '維持原樣'));
        $log($this->formatMetricsComparison($baselineMetrics, $bestMetrics));

        return [
            'baseline' => $baselineMetrics,
            'final' => $bestMetrics,
            'improved' => $improved,
            'attempts' => count($rounds),
            'rounds' => array_map(fn ($r) => $r->id, $rounds),
        ];
    }

    /**
     * 判斷新指標是否比基準改善（加權評分）
     */
    private function isImproved(array $baseline, array $new): bool
    {
        // 核心指標：期望值、雙達率、買入可達率
        $baseScore = $this->calcOverallScore($baseline);
        $newScore = $this->calcOverallScore($new);

        return $newScore > $baseScore;
    }

    private function calcOverallScore(array $metrics): float
    {
        // 期望值權重最高（獲利能力），雙達率次之（策略可行性），買入可達率再次
        return ($metrics['expected_value'] ?? 0) * 3
             + ($metrics['dual_reach_rate'] ?? 0) * 0.5
             + ($metrics['buy_reach_rate'] ?? 0) * 0.1
             - ($metrics['hit_stop_loss_rate'] ?? 0) * 0.1;
    }

    private function snapshotSettings(): array
    {
        $snapshot = [];
        foreach (['suggested_buy', 'target_price', 'stop_loss', 'strategy', 'scoring', 'news_sentiment', 'screen_thresholds'] as $type) {
            $setting = FormulaSetting::where('type', $type)->first();
            if ($setting) {
                $snapshot[$type] = $setting->config;
            }
        }
        return $snapshot;
    }

    private function restoreSettings(array $snapshot): void
    {
        foreach ($snapshot as $type => $config) {
            FormulaSetting::where('type', $type)->update(['config' => $config]);
        }
    }

    private function formatMetricsSummary(string $label, array $m): string
    {
        return sprintf(
            "  %s: 候選%d | 買入%.1f%% | 目標%.1f%% | 雙達%.1f%% | EV%.2f%% | 停損%.1f%% | RR%.2f",
            $label,
            $m['total_candidates'] ?? 0,
            $m['buy_reach_rate'] ?? 0,
            $m['target_reach_rate'] ?? 0,
            $m['dual_reach_rate'] ?? 0,
            $m['expected_value'] ?? 0,
            $m['hit_stop_loss_rate'] ?? 0,
            $m['avg_risk_reward'] ?? 0
        );
    }

    private function formatMetricsComparison(array $before, array $after): string
    {
        $keys = [
            'buy_reach_rate' => '買入可達率',
            'target_reach_rate' => '目標可達率',
            'dual_reach_rate' => '雙達率',
            'expected_value' => '期望值',
            'hit_stop_loss_rate' => '停損率',
            'avg_risk_reward' => '風報比',
        ];

        $lines = [];
        foreach ($keys as $key => $label) {
            $b = $before[$key] ?? 0;
            $a = $after[$key] ?? 0;
            $arrow = $a > $b ? '↑' : ($a < $b ? '↓' : '=');
            $suffix = in_array($key, ['avg_risk_reward']) ? '' : '%';
            $lines[] = sprintf("  %s: %.2f%s → %.2f%s %s", $label, $b, $suffix, $a, $suffix, $arrow);
        }
        return implode("\n", $lines);
    }

    /**
     * 驗證並修正價格參數，防止 RR 崩潰
     */
    private function sanitizePriceAdjustments(array $adjustments): array
    {
        $buyFallback = $adjustments['suggested_buy']['fallback_pct']
            ?? FormulaSetting::getConfig('suggested_buy')['fallback_pct']
            ?? 0.998;
        $targetFallback = $adjustments['target_price']['fallback_pct']
            ?? FormulaSetting::getConfig('target_price')['fallback_pct']
            ?? 1.025;
        $stopFallback = $adjustments['stop_loss']['fallback_pct']
            ?? FormulaSetting::getConfig('stop_loss')['fallback_pct']
            ?? 0.988;

        // 限制 buy fallback 範圍
        if ($buyFallback > 1.005) {
            Log::warning("BacktestOptimizer: buy fallback {$buyFallback} clamped to 1.005");
            $adjustments['suggested_buy']['fallback_pct'] = 1.005;
            $buyFallback = 1.005;
        }

        // 確保 target - buy >= 0.02
        if ($targetFallback - $buyFallback < 0.02) {
            $newTarget = $buyFallback + 0.025;
            Log::warning("BacktestOptimizer: target fallback {$targetFallback} raised to {$newTarget} (min 2% spread)");
            $adjustments['target_price']['fallback_pct'] = $newTarget;
            $targetFallback = $newTarget;
        }

        // 確保 buy > stop
        if ($stopFallback >= $buyFallback) {
            $newStop = $buyFallback - 0.01;
            Log::warning("BacktestOptimizer: stop fallback {$stopFallback} lowered to {$newStop}");
            $adjustments['stop_loss']['fallback_pct'] = $newStop;
        }

        return $adjustments;
    }

    /**
     * 呼叫 Claude API 分析回測數據
     */
    private function getAiSuggestions(array $metrics, array $currentSettings): array
    {
        if (!$this->apiKey) {
            Log::warning('BacktestOptimizer: ANTHROPIC_API_KEY 未設定，使用規則式分析');
            return $this->ruleBasedSuggestions($metrics, $currentSettings);
        }

        $prompt = $this->buildPrompt($metrics, $currentSettings);

        try {
            $response = Http::timeout(120)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $this->model,
                    'max_tokens' => 4096,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

            if (!$response->successful()) {
                Log::error('BacktestOptimizer API error: ' . $response->body());
                return $this->ruleBasedSuggestions($metrics, $currentSettings);
            }

            $text = $response->json('content.0.text', '');
            return $this->parseResponse($text, $metrics, $currentSettings);
        } catch (\Exception $e) {
            Log::error('BacktestOptimizer: ' . $e->getMessage());
            return $this->ruleBasedSuggestions($metrics, $currentSettings);
        }
    }

    private function buildPrompt(array $metrics, array $currentSettings): string
    {
        // 最近已套用的回測歷史（最多 5 輪）
        $recentRounds = BacktestRound::where('applied', true)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->reverse()
            ->values();

        $historyItems = $recentRounds->map(function ($round) {
            $m = $round->metrics_before ?? [];
            return [
                'round' => $round->id,
                'date' => $round->created_at->format('Y-m-d H:i'),
                'period' => $round->analyzed_from . ' ~ ' . $round->analyzed_to,
                'samples' => $round->sample_count,
                'metrics' => [
                    'buy_reach_rate' => $m['buy_reach_rate'] ?? null,
                    'target_reach_rate' => $m['target_reach_rate'] ?? null,
                    'dual_reach_rate' => $m['dual_reach_rate'] ?? null,
                    'expected_value' => $m['expected_value'] ?? null,
                    'hit_stop_loss_rate' => $m['hit_stop_loss_rate'] ?? null,
                    'avg_risk_reward' => $m['avg_risk_reward'] ?? null,
                ],
                'adjustments' => $round->suggestions['adjustments'] ?? [],
                'analysis' => $round->suggestions['analysis'] ?? '',
            ];
        })->toArray();

        $historyJson = !empty($historyItems)
            ? json_encode($historyItems, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            : '（無歷史記錄）';

        $metricsJson = json_encode([
            'buy_reach_rate' => $metrics['buy_reach_rate'],
            'target_reach_rate' => $metrics['target_reach_rate'],
            'dual_reach_rate' => $metrics['dual_reach_rate'],
            'expected_value' => $metrics['expected_value'],
            'hit_stop_loss_rate' => $metrics['hit_stop_loss_rate'],
            'avg_buy_gap' => $metrics['avg_buy_gap'],
            'avg_target_gap' => $metrics['avg_target_gap'],
            'avg_risk_reward' => $metrics['avg_risk_reward'],
            'evaluated' => $metrics['evaluated'],
            'by_strategy' => $metrics['by_strategy'] ?? [],
            'screening' => $metrics['screening'] ?? [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $settingsJson = json_encode($currentSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // 每日趨勢
        $dailyJson = json_encode($metrics['daily'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // 個股明細（取全部有結果的候選）
        $from = $metrics['period']['from'] ?? now()->subDays(30)->format('Y-m-d');
        $to = $metrics['period']['to'] ?? now()->format('Y-m-d');
        $candidates = \App\Models\Candidate::whereBetween('trade_date', [$from, $to])
            ->whereHas('result')
            ->with(['result', 'stock:id,symbol,name,industry'])
            ->orderBy('trade_date')
            ->get();

        // 用 TSV 精簡格式減少 token 數
        $detailLines = ["date\tsymbol\tind\tstrat\tscore\tbuy\ttarget\tstop\trr\tlow\thigh\tclose\tbuy?\ttgt?\tstop?\tproft%\treasons"];
        foreach ($candidates as $c) {
            $r = $c->result;
            $suggestedBuy = (float) $c->suggested_buy;
            $profit = '-';
            if ($suggestedBuy > 0 && $r->buy_reachable) {
                if ($r->target_reachable) {
                    $profit = round(((float) $c->target_price - $suggestedBuy) / $suggestedBuy * 100, 2);
                } elseif ($r->hit_stop_loss) {
                    $profit = round(-($suggestedBuy - (float) $c->stop_loss) / $suggestedBuy * 100, 2);
                } else {
                    $profit = round(((float) $r->actual_close - $suggestedBuy) / $suggestedBuy * 100, 2);
                }
            }
            $reasons = implode('|', is_array($c->reasons) ? $c->reasons : []);
            $detailLines[] = implode("\t", [
                \Carbon\Carbon::parse($c->trade_date)->format('m/d'),
                $c->stock->symbol,
                $c->stock->industry ?? '-',
                $c->intraday_strategy ?? '-',
                $c->score,
                $suggestedBuy,
                (float) $c->target_price,
                (float) $c->stop_loss,
                (float) $c->risk_reward_ratio,
                (float) $r->actual_low,
                (float) $r->actual_high,
                (float) $r->actual_close,
                $r->buy_reachable ? 'Y' : 'N',
                $r->target_reachable ? 'Y' : 'N',
                $r->hit_stop_loss ? 'Y' : 'N',
                $profit,
                $reasons,
            ]);
        }
        $detailsTsv = implode("\n", $detailLines);

        return <<<PROMPT
你是台股當沖交易系統的優化專家，負責同時優化「價格公式」、「選股邏輯」和「篩選門檻」。

## 歷史調整記錄
以下是過去已套用的回測優化記錄（從舊到新），包含當時的指標和做的調整。請仔細參考，避免重複無效的調整或來回反覆：

{$historyJson}

### 使用歷史記錄的原則
- 如果某個方向的調整在前幾輪已經做過但指標沒改善，不要再往同方向調
- 如果某個調整有效（指標改善），可以沿著同方向微調
- 注意趨勢：指標是在改善還是惡化？找出哪些調整是有效的
- 避免來回震盪（例如一輪調高、下一輪又調低同一個參數）

## 本次回測指標
{$metricsJson}

### 指標說明
- buy_reach_rate: 買入可達率（當日最低價 ≤ 建議買入價的比例）
- target_reach_rate: 目標可達率（當日最高價 ≥ 目標價的比例）
- dual_reach_rate: 雙達率（同時達到買入和目標的比例）
- expected_value: 期望報酬率 %
- hit_stop_loss_rate: 觸及停損率
- avg_buy_gap: 平均買入間距 %（正值=建議價高於最低價，越大越容易買到）
- avg_target_gap: 平均目標間距 %（正值=最高價超過目標價，越大越容易達標）
- avg_risk_reward: 平均風報比

### 選股品質指標說明
- screening.avg_score: 候選股平均評分
- screening.candidates_per_day: 每日平均候選數
- screening.strategy_distribution: 各策略的候選數量（bounce=跌深反彈, breakout=突破追多）
- screening.reason_frequency: 各選股理由的觸發頻率 %（了解哪些評分因子在運作）
- screening.avg_score_by_outcome: 依結果分組的平均分數（win=雙達, loss=觸停損, miss=未買到）
  - 若 win 和 loss 的平均分數接近，代表評分系統無法區分好壞標的
  - 若 miss 平均分數高，代表高分股但買不到，價格公式需調整

## 每日趨勢
{$dailyJson}

觀察每日指標是否穩定、是否有特定日期表現異常（可能受大盤影響）。

## 個股明細
{$detailsTsv}

### 欄位說明（tab 分隔）
date=日期, symbol=股票代號, ind=產業, strat=策略, score=評分, buy/target/stop=建議價, rr=風報比, low/high/close=實際價, buy?/tgt?/stop?=是否達到(Y/N), proft%=報酬率(-=沒買到), reasons=選股理由(|分隔)

請從個股明細觀察：
- 哪些類型的股票（產業、策略）賺錢或虧錢
- 買入價 vs 實際最低價的差距是否有規律（是否系統性偏低）
- 高分但虧損或低分但賺錢的標的，分析原因
- 觸發理由的組合是否與結果相關

## 目前參數設定
{$settingsJson}

### 參數說明

#### 價格公式參數（重要：fallback 作為價格下限/上限機制）
- suggested_buy: 建議買入價計算。系統取所有支撐來源的最高值，但 **fallback_pct 作為買入價的下限**（即 buy >= close * fallback_pct 永遠成立）。因此 fallback_pct > 1.0 會強制買入價高於昨收，大幅壓縮獲利空間。**建議 fallback_pct 維持在 0.99~1.005 之間**。
  - sources 控制取哪些支撐價，filter_lower_pct/filter_upper_pct 過濾有效支撐範圍
- target_price: 目標價計算。系統取有效目標中的最低值，fallback 用於無有效目標時。**target 的基準是 max(close, suggestedBuy)**，所以 target >= buy * fallback_pct。
  - sources 控制取哪些目標價，filter_upper_pct 是上限過濾，fallback_pct 是預設倍率
- stop_loss: 停損價計算。sources.atr.multiplier 控制 ATR 倍率，fallback_pct 是預設倍率

⚠️ 風報比公式：RR = (target - buy) / (buy - stop)。若 buy 接近 close（fallback≈1.0）而 target 的 fallback 僅 1.01，則獲利空間只有 ~1%，配合 stop 在 2% 以下，RR < 0.5 會篩掉所有候選。請確保 target_fallback - buy_fallback >= 0.02（至少 2% 獲利空間）。

#### 選股邏輯參數
- scoring: 各評分因子的設定。每個因子有 enabled（是否啟用）、score（加分值）及各自的閾值參數
  - volume_surge: 量能放大（ratio=倍數門檻）
  - ma_bullish: 均線多頭排列
  - above_ma5: 站上5MA
  - kd_golden_cross: KD黃金交叉
  - rsi_moderate: RSI適中（min/max 定義合理範圍）
  - foreign_buy: 外資買超
  - consecutive_buy: 法人連續買超（min_days=最少天數）
  - trust_buy: 投信買超
  - margin_decrease: 融資減少
  - amplitude_moderate: 振幅適中（min/max 定義範圍）
  - break_prev_high: 突破前高
  - bollinger_position: 布林通道位置
  - high_volatility: 高波動當沖適性（min_amplitude=最低振幅, lookback_days=回看天數）
  - strong_trend: 近期強勢趨勢（min_gain_pct=最低漲幅%, lookback_days=回看天數）
  - foreign_big_buy: 外資大買（volume_ratio=佔成交量比例門檻）
  - dealer_big_buy: 自營大買（volume_ratio=佔成交量比例門檻）
  - high_volume: 萬張量能（min_lots=最低張數）
- strategy: 策略分類參數
  - bounce: 跌深反彈（washout_drop_pct=急跌幅度, two_day_drop_pct=兩日累跌, washout_lookback_days=回看天數, bounce_from_low_pct=反彈幅度, score=策略加分）
  - breakout: 突破追多（prev_high_days=前高回看天數, near_breakout_pct=接近突破比例, score=策略加分）
- news_sentiment: 消息面修正的閾值和係數

#### 篩選門檻參數
- screen_thresholds: 控制候選標的篩選的硬門檻
  - min_volume: 最低成交量（張），低於此量的股票直接跳過
  - min_price: 最低股價，低於此價的股票直接跳過
  - min_score: 最低評分門檻，低於此分數的股票不列入候選
  - min_risk_reward: 最低風報比，低於此值的不列入
  - max_candidates: 每日最大候選數

## 任務

分析回測數據，找出**最需要改善的一類參數**進行調整。

### ⚠️ 核心原則：一次只調一類參數

歷史教訓：同時調整價格公式 + 評分權重 + 篩選門檻會導致結果不可控。改了評分就會改變選哪些股票，連帶使價格指標失去參考意義，多次實驗證明同時調整反而退步。

**你必須從以下三類中選擇一類進行調整，其他類別不動：**

1. **價格公式**（suggested_buy / target_price / stop_loss）— 當買入可達率、目標可達率、停損率明顯異常時
2. **選股評分**（scoring / strategy）— 當 win/loss 平均分數接近（區辨力不足）、某因子觸發率極端時
3. **篩選門檻**（screen_thresholds）— 當每日候選數太多或太少時

選擇依據：哪類問題最嚴重、改善空間最大，就調哪類。在 analysis 中說明為什麼選擇調這一類而不是另外兩類。

### 優化目標（按優先順序）
1. **提高買入可達率**（buy_reach_rate 目標 >= 50%）— 買不到的策略沒意義
2. **提高雙達率**（dual_reach_rate 目標 >= 15%）
3. **維持正期望值**（expected_value > 0.3%，扣手續費後仍有利）
4. **風報比合理**（1.5 ~ 3.0 最佳，過高代表定價太保守）
5. 提升選股品質：讓高分候選股對應更好的交易結果

### 分析方向
- 從個股明細觀察買入價 vs 實際最低價的差距模式，判斷是系統性偏低還是個別問題
- 若某策略（bounce/breakout）表現明顯較差，考慮降低該策略的 score 或調整其門檻
- 若 reason_frequency 中某因子觸發率極高但結果不佳，考慮降低其 score 或收緊門檻
- 若 avg_score_by_outcome 中 win 和 loss 分數接近，代表評分區辨力不足，需調整權重分布
- 若候選數太少（< 5/天），可降低 min_score 或放寬因子門檻；太多（> 15）可收緊
- 若風報比過高（> 4），代表目標太遠或停損太緊，應適度調整

請用 JSON 回覆（不要加其他文字），格式如下：
{
  "focus": "price | scoring | thresholds（本次調整的類別）",
  "analysis": "問題分析摘要（中文，說明三類參數各自狀態，以及為什麼選擇調整這一類）",
  "adjustments": {
    "只包含本次要調整的類別，例如：": "",
    "suggested_buy": { "要修改的參數key": "新值" },
    "target_price": { "要修改的參數key": "新值" },
    "stop_loss": { "要修改的參數key": "新值" }
  },
  "reasoning": {
    "只包含本次調整的類別": "調整原因（引用具體數據）"
  }
}

注意：
- **adjustments 中只能包含一類參數**（價格類 / 評分類 / 門檻類），不要跨類調整
- 只調整有明確數據支持的參數，引用具體數據說明原因
- 每次調整幅度不要太大（每個參數最多調整 10-20%）
- 如果某參數已經表現良好，不需要調整
- adjustments 的 key 使用點分隔路徑（如 "sources.atr.multiplier", "volume_surge.score", "bounce.washout_drop_pct"）
- scoring 和 strategy 的調整也使用點分隔路徑
- screen_thresholds 的 key 直接使用參數名（如 "min_score", "min_risk_reward"）
- score 可以設為負值（-15 ~ 30），負分代表該因子觸發時扣分

⚠️ 硬性約束（違反會造成系統異常或篩掉所有候選）：
- suggested_buy.fallback_pct 必須 < target_price.fallback_pct（買入價必須低於目標價）
- suggested_buy.filter_upper_pct 必須 < target_price.filter_upper_pct
- stop_loss.fallback_pct 必須 < suggested_buy.fallback_pct（停損必須低於買入價）
- target_price.fallback_pct - suggested_buy.fallback_pct >= 0.02（確保至少 2% 獲利空間）
- suggested_buy.fallback_pct 範圍：0.985 ~ 1.005（過高會壓垮風報比，過低會降低買入可達率）
- 調整前請自行驗證：買入價 < 目標價、停損 < 買入價、風報比 >= 1.3，否則不要提交該調整
PROMPT;
    }

    private function parseResponse(string $text, array $metrics, array $currentSettings): array
    {
        $text = trim($text);
        $text = preg_replace('/^```json?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        $data = json_decode($text, true);

        if (!is_array($data) || !isset($data['adjustments'])) {
            Log::warning('BacktestOptimizer: AI 回應格式不正確，使用規則式分析');
            return $this->ruleBasedSuggestions($metrics, $currentSettings);
        }

        return $data;
    }

    /**
     * 無 API key 時的規則式降級分析
     */
    private function ruleBasedSuggestions(array $metrics, array $currentSettings): array
    {
        $adjustments = [];
        $reasoning = [];
        $analysis = [];

        $buyRate = $metrics['buy_reach_rate'];
        $targetRate = $metrics['target_reach_rate'];
        $stopRate = $metrics['hit_stop_loss_rate'];
        $avgBuyGap = $metrics['avg_buy_gap'];
        $avgTargetGap = $metrics['avg_target_gap'];

        // ===== 價格公式調整 =====

        // 買入可達率偏低：提高 fallback_pct 讓建議買入價更接近收盤價
        if ($buyRate < 50) {
            $currentFallback = $currentSettings['suggested_buy']['fallback_pct'] ?? 0.99;
            $newFallback = min(1.0, $currentFallback + 0.005);
            $adjustments['suggested_buy'] = ['fallback_pct' => $newFallback];
            $reasoning['suggested_buy'] = "買入可達率僅 {$buyRate}%，提高 fallback_pct 從 {$currentFallback} 到 {$newFallback}，讓建議買入價更接近市價";
            $analysis[] = "買入可達率偏低（{$buyRate}%）";
        } elseif ($buyRate > 80 && $avgBuyGap > 2) {
            // 買入可達率太高且間距大：可以壓低買入價取得更好入場點
            $currentFallback = $currentSettings['suggested_buy']['fallback_pct'] ?? 0.99;
            $newFallback = max(0.97, $currentFallback - 0.005);
            $adjustments['suggested_buy'] = ['fallback_pct' => $newFallback];
            $reasoning['suggested_buy'] = "買入可達率 {$buyRate}% 且平均間距 {$avgBuyGap}%，略降 fallback_pct 取得更好入場點";
            $analysis[] = "買入可達率高但入場點偏高";
        }

        // 目標可達率偏低：降低 fallback_pct 讓目標價更保守
        if ($targetRate < 40) {
            $currentFallback = $currentSettings['target_price']['fallback_pct'] ?? 1.03;
            $newFallback = max(1.01, $currentFallback - 0.005);
            $adjustments['target_price'] = ['fallback_pct' => $newFallback];
            $reasoning['target_price'] = "目標可達率僅 {$targetRate}%，降低 fallback_pct 從 {$currentFallback} 到 {$newFallback}，設定更務實的目標";
            $analysis[] = "目標可達率偏低（{$targetRate}%）";
        } elseif ($targetRate > 75 && $avgTargetGap > 2) {
            // 目標可達率太高：可以提高目標價爭取更大獲利
            $currentFallback = $currentSettings['target_price']['fallback_pct'] ?? 1.03;
            $newFallback = min(1.06, $currentFallback + 0.005);
            $adjustments['target_price'] = ['fallback_pct' => $newFallback];
            $reasoning['target_price'] = "目標可達率 {$targetRate}% 且平均超過 {$avgTargetGap}%，略升目標爭取更大獲利";
            $analysis[] = "目標可達率高但獲利空間可再放大";
        }

        // 停損率偏高：收緊停損
        if ($stopRate > 50) {
            $currentFallback = $currentSettings['stop_loss']['fallback_pct'] ?? 0.985;
            $newFallback = min(0.995, $currentFallback + 0.003);
            $adjustments['stop_loss'] = ['fallback_pct' => $newFallback];
            $reasoning['stop_loss'] = "停損觸及率 {$stopRate}%，收緊停損幅度";
            $analysis[] = "停損觸及率偏高（{$stopRate}%）";
        }

        // ===== 選股邏輯調整 =====

        $screening = $metrics['screening'] ?? [];
        $scoringChanges = [];
        $scoringReasons = [];
        $strategyChanges = [];
        $strategyReasons = [];

        // 評分區辨力不足：win 和 loss 分數太接近
        $scoreByOutcome = $screening['avg_score_by_outcome'] ?? [];
        $winScore = $scoreByOutcome['win'] ?? 0;
        $lossScore = $scoreByOutcome['loss'] ?? 0;
        if ($winScore > 0 && $lossScore > 0 && abs($winScore - $lossScore) < 5) {
            // 區辨力不足時，提高與趨勢相關的因子權重、降低普遍性因子
            $currentVolSurge = $currentSettings['scoring']['volume_surge']['score'] ?? 15;
            $currentMaBullish = $currentSettings['scoring']['ma_bullish']['score'] ?? 15;
            $scoringChanges['volume_surge.score'] = min(20, $currentVolSurge + 2);
            $scoringChanges['ma_bullish.score'] = min(20, $currentMaBullish + 2);
            $scoringReasons[] = "win/loss 平均分數差距僅 " . round(abs($winScore - $lossScore), 1) . " 分，提高關鍵因子權重增加區辨力";
            $analysis[] = "選股評分區辨力不足";
        }

        // 策略表現差異：某策略明顯劣於另一策略
        $byStrategy = $metrics['by_strategy'] ?? [];
        $bounceEV = $byStrategy['bounce']['expected_value'] ?? null;
        $breakoutEV = $byStrategy['breakout']['expected_value'] ?? null;
        if ($bounceEV !== null && $breakoutEV !== null) {
            if ($bounceEV < -1 && $breakoutEV > $bounceEV + 2) {
                $currentScore = $currentSettings['strategy']['bounce']['score'] ?? 15;
                $newScore = max(5, $currentScore - 3);
                $strategyChanges['bounce.score'] = $newScore;
                $strategyReasons[] = "跌深反彈策略期望值 {$bounceEV}% 明顯偏低，降低策略加分從 {$currentScore} 到 {$newScore}";
                $analysis[] = "跌深反彈策略表現差";
            } elseif ($breakoutEV < -1 && $bounceEV > $breakoutEV + 2) {
                $currentScore = $currentSettings['strategy']['breakout']['score'] ?? 15;
                $newScore = max(5, $currentScore - 3);
                $strategyChanges['breakout.score'] = $newScore;
                $strategyReasons[] = "突破追多策略期望值 {$breakoutEV}% 明顯偏低，降低策略加分從 {$currentScore} 到 {$newScore}";
                $analysis[] = "突破追多策略表現差";
            }
        }

        // 候選數太少或太多
        $candidatesPerDay = $screening['candidates_per_day'] ?? 0;
        if ($candidatesPerDay > 0 && $candidatesPerDay < 5) {
            // 放寬高波動門檻
            $currentMinAmp = $currentSettings['scoring']['high_volatility']['min_amplitude'] ?? 5;
            $newMinAmp = max(3, $currentMinAmp - 0.5);
            $scoringChanges['high_volatility.min_amplitude'] = $newMinAmp;
            $scoringReasons[] = "每日候選數僅 {$candidatesPerDay}，放寬高波動門檻從 {$currentMinAmp}% 到 {$newMinAmp}%";
            $analysis[] = "每日候選數偏少（{$candidatesPerDay}）";
        } elseif ($candidatesPerDay > 15) {
            // 收緊量能門檻
            $currentRatio = $currentSettings['scoring']['volume_surge']['ratio'] ?? 1.5;
            $newRatio = min(2.5, $currentRatio + 0.2);
            $scoringChanges['volume_surge.ratio'] = $newRatio;
            $scoringReasons[] = "每日候選數 {$candidatesPerDay} 過多，收緊量能放大門檻從 {$currentRatio}x 到 {$newRatio}x";
            $analysis[] = "每日候選數過多（{$candidatesPerDay}）";
        }

        if (!empty($scoringChanges)) {
            $adjustments['scoring'] = $scoringChanges;
            $reasoning['scoring'] = implode('；', $scoringReasons);
        }
        if (!empty($strategyChanges)) {
            $adjustments['strategy'] = $strategyChanges;
            $reasoning['strategy'] = implode('；', $strategyReasons);
        }

        return [
            'analysis' => empty($analysis) ? '各項指標表現正常，暫無需調整' : implode('；', $analysis),
            'adjustments' => $adjustments,
            'reasoning' => $reasoning,
        ];
    }

    /**
     * 合併巢狀設定（支援點分隔路徑，如 "sources.atr.multiplier"）
     */
    private function mergeNestedConfig(array $config, array $changes): array
    {
        foreach ($changes as $key => $value) {
            $parts = explode('.', $key);

            if (count($parts) === 1) {
                $config[$key] = $value;
            } else {
                $ref = &$config;
                foreach ($parts as $i => $part) {
                    if ($i === count($parts) - 1) {
                        $ref[$part] = $value;
                    } else {
                        if (!isset($ref[$part]) || !is_array($ref[$part])) {
                            $ref[$part] = [];
                        }
                        $ref = &$ref[$part];
                    }
                }
                unset($ref);
            }
        }

        return $config;
    }
}

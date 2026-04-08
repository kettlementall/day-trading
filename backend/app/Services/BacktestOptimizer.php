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
        foreach (['suggested_buy', 'target_price', 'stop_loss', 'strategy', 'news_sentiment'] as $type) {
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
            $response = Http::timeout(60)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $this->model,
                    'max_tokens' => 2000,
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
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $settingsJson = json_encode($currentSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
你是台股當沖交易系統的公式優化專家。以下是過去一段時間的回測統計數據和目前的公式參數設定。

## 回測指標
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

## 目前公式參數
{$settingsJson}

### 參數說明
- suggested_buy: 建議買入價計算。sources 控制取哪些支撐價，filter_lower_pct 是下限過濾，fallback_pct 是預設倍率
- target_price: 目標價計算。sources 控制取哪些目標價，filter_upper_pct 是上限過濾，fallback_pct 是預設倍率
- stop_loss: 停損價計算。sources.atr.multiplier 控制 ATR 倍率，fallback_pct 是預設倍率
- strategy: 策略分類的參數設定
- news_sentiment: 消息面修正的閾值和係數

## 任務

分析回測數據，找出公式參數的改善空間，目標是：
1. 提高雙達率（dual_reach_rate）
2. 提高期望值（expected_value）
3. 維持合理的風報比（>= 1.5）

請用 JSON 回覆（不要加其他文字），格式如下：
{
  "analysis": "問題分析摘要（中文）",
  "adjustments": {
    "suggested_buy": { "要修改的參數key": 新值 },
    "target_price": { "要修改的參數key": 新值 },
    "stop_loss": { "要修改的參數key": 新值 }
  },
  "reasoning": {
    "suggested_buy": "調整原因",
    "target_price": "調整原因",
    "stop_loss": "調整原因"
  }
}

注意：
- 只調整有明確數據支持的參數，不要亂改
- 每次調整幅度不要太大（每個參數最多調整 10-20%）
- 如果某類參數已經表現良好，可以不調整（不需要在 adjustments 中列出）
- adjustments 的 key 使用點分隔路徑（如 "sources.atr.multiplier"）
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

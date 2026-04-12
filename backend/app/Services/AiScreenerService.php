<?php

namespace App\Services;

use App\Models\AiLesson;
use App\Models\Candidate;
use App\Models\DailyQuote;
use App\Models\InstitutionalTrade;
use App\Models\MarginTrade;
use App\Models\NewsArticle;
use App\Models\NewsIndex;
use App\Models\UsMarketIndex;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiScreenerService
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key', '');
        $this->model = config('services.anthropic.model', 'claude-haiku-4-5-20251001');
    }

    /**
     * AI 審核選股：從寬篩候選池中選出最終名單
     *
     * @param  string      $tradeDate
     * @param  Collection  $candidates  已載入 stock 關聯的候選集合
     * @return Collection  更新後的候選集合
     */
    public function screen(string $tradeDate, Collection $candidates): Collection
    {
        if ($candidates->isEmpty()) {
            return $candidates;
        }

        if (!$this->apiKey) {
            Log::warning('AiScreenerService: ANTHROPIC_API_KEY 未設定，使用 fallback');
            return $this->fallbackScreen($candidates);
        }

        $prompt = $this->buildPrompt($tradeDate, $candidates);

        try {
            $response = Http::timeout(60)
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
                Log::error('AiScreenerService API error: ' . $response->body());
                return $this->fallbackScreen($candidates);
            }

            $text = $response->json('content.0.text', '');
            $aiResult = $this->parseResponse($text);

            if (empty($aiResult)) {
                Log::error('AiScreenerService: 無法解析 AI 回應');
                return $this->fallbackScreen($candidates);
            }

            return $this->applyAiResult($candidates, $aiResult);
        } catch (\Exception $e) {
            Log::error('AiScreenerService: ' . $e->getMessage());
            return $this->fallbackScreen($candidates);
        }
    }

    /**
     * Fallback：API 失敗時，取 top 15 by score + 預設策略
     */
    public function fallbackScreen(Collection $candidates): Collection
    {
        $sorted = $candidates->sortByDesc('score')->values();
        $selectedCount = min(15, $sorted->count());

        foreach ($sorted as $i => $candidate) {
            $selected = $i < $selectedCount;
            $candidate->update([
                'ai_selected' => $selected,
                'ai_score_adjustment' => 0,
                'ai_reasoning' => $selected
                    ? '規則式 fallback（AI 不可用），依分數排名選入'
                    : '規則式 fallback（AI 不可用），分數排名未入選',
                'intraday_strategy' => $selected ? $this->defaultStrategy($candidate) : null,
                'reference_support' => $selected ? $candidate->stop_loss : null,
                'reference_resistance' => $selected ? $candidate->target_price : null,
                'ai_warnings' => null,
            ]);
        }

        return $candidates->fresh();
    }

    private function buildPrompt(string $tradeDate, Collection $candidates): string
    {
        // 建立 TSV 格式候選資料
        $header = "代號\t名稱\t產業\t分數\t策略\t建議買入\t目標\t停損\t風報比\t評分理由";
        $rows = [];

        foreach ($candidates as $c) {
            $stock = $c->stock;
            $reasons = is_array($c->reasons) ? implode('；', $c->reasons) : ($c->reasons ?? '');
            $rows[] = implode("\t", [
                $stock->symbol,
                $stock->name,
                $stock->industry ?? '',
                $c->score,
                $c->strategy_type ?? '',
                $c->suggested_buy,
                $c->target_price,
                $c->stop_loss,
                $c->risk_reward_ratio,
                mb_substr($reasons, 0, 100),
            ]);
        }

        // 取近 5 日 K 線摘要（每檔一行）
        $klineHeader = "代號\t日期\t開\t高\t低\t收\t量(張)\t漲跌%\t振幅%";
        $klineRows = [];

        foreach ($candidates as $c) {
            $quotes = DailyQuote::where('stock_id', $c->stock_id)
                ->where('date', '<=', $tradeDate)
                ->orderByDesc('date')
                ->limit(5)
                ->get()
                ->reverse();

            foreach ($quotes as $q) {
                $klineRows[] = implode("\t", [
                    $c->stock->symbol,
                    $q->date->format('m/d'),
                    $q->open, $q->high, $q->low, $q->close,
                    $q->volume,
                    $q->change_percent ?? '',
                    $q->amplitude ?? '',
                ]);
            }
        }

        // 1. 籌碼面：近 5 日三大法人買賣超
        $chipHeader = "代號\t日期\t外資淨買(張)\t投信淨買(張)\t自營淨買(張)\t合計淨買(張)";
        $chipRows = [];

        foreach ($candidates as $c) {
            $trades = InstitutionalTrade::where('stock_id', $c->stock_id)
                ->where('date', '<=', $tradeDate)
                ->orderByDesc('date')
                ->limit(5)
                ->get()
                ->reverse();

            foreach ($trades as $t) {
                $chipRows[] = implode("\t", [
                    $c->stock->symbol,
                    $t->date->format('m/d'),
                    $t->foreign_net,
                    $t->trust_net,
                    $t->dealer_net,
                    $t->total_net,
                ]);
            }
        }

        // 2. 融資融券：近 5 日變化
        $marginHeader = "代號\t日期\t融資增減(張)\t融資餘額(張)\t融券增減(張)\t融券餘額(張)";
        $marginRows = [];

        foreach ($candidates as $c) {
            $margins = MarginTrade::where('stock_id', $c->stock_id)
                ->where('date', '<=', $tradeDate)
                ->orderByDesc('date')
                ->limit(5)
                ->get()
                ->reverse();

            foreach ($margins as $m) {
                $marginRows[] = implode("\t", [
                    $c->stock->symbol,
                    $m->date->format('m/d'),
                    $m->margin_change,
                    $m->margin_balance,
                    $m->short_change,
                    $m->short_balance,
                ]);
            }
        }

        // 3. 當日新聞標題（最近兩日，與候選產業相關）
        $newsLines = [];
        $news = NewsArticle::where('fetched_date', '>=', now()->subDays(2)->toDateString())
            ->whereNotNull('industry')
            ->orderByDesc('published_at')
            ->limit(30)
            ->get();

        foreach ($news as $n) {
            $label = $n->industry ?? '其他';
            $newsLines[] = "- [{$label}] {$n->title}";
        }

        // 4. 產業消息面指數
        $newsIndexLines = [];
        $latestNewsDate = NewsIndex::where('scope', 'overall')
            ->where('date', '<=', $tradeDate)
            ->orderByDesc('date')
            ->value('date');

        if ($latestNewsDate) {
            $overall = NewsIndex::where('scope', 'overall')->where('date', $latestNewsDate)->first();
            if ($overall) {
                $newsIndexLines[] = "整體情緒: {$overall->sentiment} | 熱度: {$overall->heatmap} | 恐慌: {$overall->panic} | 國際: {$overall->international} (文章數: {$overall->article_count})";
            }

            $industries = NewsIndex::where('scope', 'industry')
                ->where('date', $latestNewsDate)
                ->orderByDesc('sentiment')
                ->get();

            foreach ($industries as $idx) {
                $newsIndexLines[] = "{$idx->scope_value}: 情緒 {$idx->sentiment} | 熱度 {$idx->heatmap} (文章數: {$idx->article_count})";
            }
        }

        $candidatesTsv = $header . "\n" . implode("\n", $rows);
        $klineTsv = $klineHeader . "\n" . implode("\n", $klineRows);
        $chipTsv = $chipHeader . "\n" . implode("\n", $chipRows);
        $marginTsv = $marginHeader . "\n" . implode("\n", $marginRows);
        $newsSection = !empty($newsLines) ? implode("\n", $newsLines) : '（無近期新聞）';
        $newsIndexSection = !empty($newsIndexLines) ? implode("\n", $newsIndexLines) : '（無消息面指數）';
        $totalCount = $candidates->count();

        // 注入近期教訓
        $lessonsSection = AiLesson::getScreeningLessons();

        // 注入美股指數
        $usMarketSection = UsMarketIndex::getSummary($tradeDate);

        return <<<PROMPT
你是台股當沖選股 AI 助手。現在是 {$tradeDate} 盤前（08:00），以下是經規則式寬篩產出的 {$totalCount} 檔候選標的。
注意：K 線資料截至前一交易日收盤，請用日期欄位判斷每根 K 棒的日期，不要用「今日」「昨日」等模糊說法，請用實際日期（如 4/9、4/10）。

{$usMarketSection}

## 候選標的
{$candidatesTsv}

## 近 5 日 K 線
{$klineTsv}

## 近 5 日三大法人買賣超
{$chipTsv}

## 近 5 日融資融券
{$marginTsv}

## 近期新聞
{$newsSection}

## 消息面指數
{$newsIndexSection}

{$lessonsSection}

## 任務
綜合以上所有資訊（K 線型態、籌碼面、融資券、消息面、國際市場），從候選池中選出最適合今日當沖的 10-15 檔標的。你可以：
1. **選入規則分數高的好標的**（確認型態與量能配合）
2. **選入規則分數偏低但型態好的標的**（例如杯柄突破、縮量回測不破前高）
3. **排除規則分數高但有風險的標的**（例如連漲量縮、同類股過多只留最強）
4. **控制同產業標的數量**，同產業最多 2-3 檔
5. **參考籌碼面**：外資/投信連續買超的標的可加分，三大法人同步賣超的應警戒
6. **參考融資券**：融資大增+股價未漲=散戶追高風險；融券大增+股價強=軋空機會
7. **參考消息面**：利多產業的標的可優先，恐慌指數高時整體應保守、減少選入數量
8. **參考國際市場**：台指期夜盤方向是最重要參考，費半走勢影響半導體/AI 類股

每檔標的請給出：
- `intraday_strategy`：盤中策略標籤，選項：breakout_fresh（首次突破）、breakout_retest（突破回測）、gap_pullback（跳空拉回）、bounce（跌深反彈）、momentum（量能動能）
- `suggested_buy`：建議買入價（根據 K 線型態、支撐壓力位、策略特性給出合理的進場價）
- `target_price`：目標獲利價（根據壓力位、近期振幅、型態空間合理設定）
- `stop_loss`：停損價（根據支撐位、ATR、型態破壞點設定）
- `price_reasoning`：一句話解釋三個價格的設定依據（例如：「買入設前高29.0回測位，目標為4/8高點30.5，停損設MA10下方28.5」）
- `reference_support`：參考支撐位
- `reference_resistance`：參考壓力位
- `score_adjustment`：加減分（-30 ~ +30）
- `reasoning`：選入/排除的一句話理由
- `warnings`：注意事項（可為 null）

價格設定原則：
- 買入價應設在合理回測位置（突破型可設前高附近，反彈型設支撐附近）
- 目標價不宜超過收盤價 +10%，停損不宜超過收盤價 -2.5%
- 風報比（目標-買入）/（買入-停損）應 >= 1.5

## 回覆格式
請直接回覆 JSON（不要加 markdown 標記），格式：
{
  "selected": [
    {
      "symbol": "2460",
      "score_adjustment": 8,
      "reasoning": "突破後縮量回測不破前高，型態教科書級",
      "intraday_strategy": "breakout_retest",
      "suggested_buy": 29.5,
      "target_price": 30.5,
      "stop_loss": 29.0,
      "price_reasoning": "買入設前高29.5回測位，目標為4/8高點30.5，停損設MA10下方29.0",
      "reference_support": 29.0,
      "reference_resistance": 30.5,
      "warnings": ["上方 30.5 為 60 日高點壓力"]
    }
  ],
  "rejected": [
    {
      "symbol": "2888",
      "reasoning": "雖然技術面過關但量能連 3 天萎縮"
    }
  ],
  "market_notes": "半導體類股今日過熱，同類只留最強 2 檔"
}
PROMPT;
    }

    private function parseResponse(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```json?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        $data = json_decode($text, true);

        if (!is_array($data) || !isset($data['selected'])) {
            return null;
        }

        return $data;
    }

    /**
     * 將 AI 結果寫入候選標的
     */
    private function applyAiResult(Collection $candidates, array $aiResult): Collection
    {
        // 建立 symbol → AI 結果 mapping
        $selectedMap = collect($aiResult['selected'] ?? [])->keyBy('symbol');
        $rejectedMap = collect($aiResult['rejected'] ?? [])->keyBy('symbol');

        foreach ($candidates as $candidate) {
            $symbol = $candidate->stock->symbol;

            if ($selectedMap->has($symbol)) {
                $ai = $selectedMap->get($symbol);

                // AI 給的價格覆蓋規則式價格
                $updates = [
                    'ai_selected' => true,
                    'ai_score_adjustment' => $ai['score_adjustment'] ?? 0,
                    'ai_reasoning' => $ai['reasoning'] ?? '',
                    'intraday_strategy' => $ai['intraday_strategy'] ?? $this->defaultStrategy($candidate),
                    'reference_support' => $ai['reference_support'] ?? $candidate->stop_loss,
                    'reference_resistance' => $ai['reference_resistance'] ?? $candidate->target_price,
                    'ai_warnings' => $ai['warnings'] ?? null,
                    'ai_price_reasoning' => $ai['price_reasoning'] ?? null,
                ];

                if (!empty($ai['suggested_buy'])) {
                    $updates['suggested_buy'] = $ai['suggested_buy'];
                }
                if (!empty($ai['target_price'])) {
                    $updates['target_price'] = $ai['target_price'];
                }
                if (!empty($ai['stop_loss'])) {
                    $updates['stop_loss'] = $ai['stop_loss'];
                }

                // 重算風報比
                if (isset($updates['suggested_buy'], $updates['target_price'], $updates['stop_loss'])) {
                    $buy = (float) $updates['suggested_buy'];
                    $stop = (float) $updates['stop_loss'];
                    if ($buy > $stop && $stop > 0) {
                        $updates['risk_reward_ratio'] = round(
                            ((float) $updates['target_price'] - $buy) / ($buy - $stop), 2
                        );
                    }
                }

                $candidate->update($updates);
            } else {
                $rejected = $rejectedMap->get($symbol);
                $candidate->update([
                    'ai_selected' => false,
                    'ai_score_adjustment' => 0,
                    'ai_reasoning' => $rejected['reasoning'] ?? 'AI 未選入',
                    'intraday_strategy' => null,
                    'reference_support' => null,
                    'reference_resistance' => null,
                    'ai_warnings' => null,
                ]);
            }
        }

        return $candidates->fresh();
    }

    /**
     * 根據現有 strategy_type 給預設盤中策略
     */
    private function defaultStrategy(Candidate $candidate): string
    {
        return match ($candidate->strategy_type) {
            'bounce' => 'bounce',
            'breakout' => 'breakout_fresh',
            default => 'momentum',
        };
    }
}

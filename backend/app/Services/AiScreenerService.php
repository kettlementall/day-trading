<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\AiLesson;
use App\Models\Candidate;
use App\Models\DailyQuote;
use App\Models\InstitutionalTrade;
use App\Models\IntradaySnapshot;
use App\Models\MarginTrade;
use App\Models\NewsArticle;
use App\Models\NewsIndex;
use App\Models\SectorIndex;
use App\Models\StrategyPerformanceStat;
use App\Models\StockValuation;
use App\Models\UsMarketIndex;
use App\Services\TechnicalIndicator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ContextTooLargeException extends \RuntimeException {}

class AiScreenerService
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key', '');
        $this->model = config('services.anthropic.screening_model', 'claude-opus-4-6');
    }

    /**
     * AI 審核選股：逐支評估候選標的，共用快取的市場背景
     *
     * @param  string      $tradeDate
     * @param  Collection  $candidates  已載入 stock 關聯的候選集合
     * @return Collection  更新後的候選集合
     */
    public function screen(
        string $tradeDate,
        Collection $candidates,
        string $mode = 'intraday',
        ?string $snapshotDate = null
    ): Collection {
        if ($candidates->isEmpty()) {
            return $candidates;
        }

        if (!$this->apiKey) {
            Log::warning('AiScreenerService: ANTHROPIC_API_KEY 未設定，使用 fallback');
            return $this->fallbackScreen($candidates);
        }

        if ($mode === 'overnight') {
            $systemPrompt = $this->buildSystemPromptOvernight($tradeDate, $snapshotDate);
        } else {
            $systemPrompt      = $this->buildSystemPrompt($tradeDate);
            $systemPromptShort = $this->buildSystemPrompt($tradeDate, short: true);
        }

        foreach ($candidates as $candidate) {
            $symbol = $candidate->stock->symbol;
            try {
                $userMessage = $mode === 'overnight'
                    ? $this->buildStockMessageOvernight($tradeDate, $candidate, $snapshotDate)
                    : $this->buildStockMessage($tradeDate, $candidate);
                $maxTokens = $mode === 'overnight' ? 1800 : 1024;
                $result = $this->callApiWithRetry($systemPrompt, $userMessage, $symbol, $maxTokens);
                $mode === 'overnight'
                    ? $this->applyResultOvernight($candidate, $result)
                    : $this->applyResult($candidate, $result);
                Log::info("AiScreenerService [{$mode}] {$symbol}: " . ($result['selected'] ? '選入' : '排除'));
            } catch (ContextTooLargeException $e) {
                if ($mode === 'overnight') {
                    Log::error("AiScreenerService overnight {$symbol}: context 過大，跳過");
                    $candidate->update(['ai_selected' => false, 'ai_reasoning' => 'AI 評估失敗（context 過大）']);
                } else {
                    Log::warning("AiScreenerService {$symbol}: context 過大，改用精簡 prompt 重試");
                    try {
                        $userMessage = $this->buildStockMessage($tradeDate, $candidate, short: true);
                        $result = $this->callApiWithRetry($systemPromptShort, $userMessage, $symbol);
                        $this->applyResult($candidate, $result);
                    } catch (\Exception $e2) {
                        Log::error("AiScreenerService {$symbol}(short): " . $e2->getMessage());
                        $candidate->update(['ai_selected' => false, 'ai_reasoning' => 'AI 評估失敗（context 過大）']);
                    }
                }
            } catch (\Exception $e) {
                Log::error("AiScreenerService [{$mode}] {$symbol}: " . $e->getMessage());
                $candidate->update([
                    'ai_selected'  => false,
                    'ai_reasoning' => 'AI 評估失敗：' . mb_substr($e->getMessage(), 0, 80),
                ]);
            }
            usleep(150_000);
        }

        return $candidates->fresh();
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
                'ai_selected'        => $selected,
                'ai_score_adjustment'=> 0,
                'ai_reasoning'       => $selected
                    ? '規則式 fallback（AI 不可用），依分數排名選入'
                    : '規則式 fallback（AI 不可用），分數排名未入選',
                'intraday_strategy'  => $selected ? $this->defaultStrategy($candidate) : null,
                'reference_support'  => $selected ? $candidate->stop_loss : null,
                'reference_resistance' => $selected ? $candidate->target_price : null,
                'ai_warnings'        => null,
            ]);
        }

        return $candidates->fresh();
    }

    // -------------------------------------------------------------------------
    // 快取 system prompt：市場背景 + 任務說明（所有股票共用）
    // -------------------------------------------------------------------------

    private function buildSystemPrompt(string $tradeDate, bool $short = false): string
    {
        $usMarketSection  = UsMarketIndex::getSummary($tradeDate);
        $lessonsSection   = AiLesson::getScreeningLessons();

        // 新聞標題（近 2 日）：精簡模式只取 15 則
        $newsLimit = $short ? 15 : 30;
        $news = NewsArticle::where('fetched_date', '>=', now()->subDays(2)->toDateString())
            ->whereNotNull('industry')
            ->orderByDesc('published_at')
            ->limit($newsLimit)
            ->get();
        $newsLines = $news->map(fn($n) => "- [{$n->industry}] {$n->title}")->implode("\n");
        $newsSection = $newsLines ?: '（無近期新聞）';

        // 消息面指數
        $latestNewsDate = NewsIndex::where('scope', 'overall')
            ->where('date', '<=', $tradeDate)
            ->orderByDesc('date')
            ->value('date');

        $newsIndexLines = [];
        if ($latestNewsDate) {
            $overall = NewsIndex::where('scope', 'overall')->where('date', $latestNewsDate)->first();
            if ($overall) {
                $newsIndexLines[] = "整體情緒: {$overall->sentiment} | 熱度: {$overall->heatmap} | 恐慌: {$overall->panic} | 國際: {$overall->international} (文章數: {$overall->article_count})";
            }
            NewsIndex::where('scope', 'industry')->where('date', $latestNewsDate)
                ->orderByDesc('sentiment')->get()
                ->each(fn($idx) => $newsIndexLines[] = "{$idx->scope_value}: 情緒 {$idx->sentiment} | 熱度 {$idx->heatmap} (文章數: {$idx->article_count})");
        }
        $newsIndexSection = $newsIndexLines ? implode("\n", $newsIndexLines) : '（無消息面指數）';

        return <<<SYSTEM
你是台股當沖選股 AI 助手。現在是 {$tradeDate} 盤前（08:00）。
我將逐一提交每檔候選標的，請針對每支獨立判斷是否適合今日當沖操作。

{$usMarketSection}

## 近期新聞
{$newsSection}

## 消息面指數
{$newsIndexSection}

{$lessonsSection}

## 評估原則
1. 確認 K 線型態與量能是否配合策略（突破型需量能放大，反彈型需跌深量縮）
2. 籌碼面：外資/投信連續買超加分，三大法人同步賣超警戒
3. 融資券：融資大增+股價未漲=散戶追高風險；融券大增+股價強=軋空機會
4. 消息面：利多產業優先；恐慌指數高時標準從嚴
5. 國際市場：台指期夜盤方向最重要；費半影響半導體/AI 類股

## 回覆格式
請直接回覆 JSON（不要加 markdown 標記），格式：
{
  "selected": true,
  "score_adjustment": 5,
  "reasoning": "一句話選入/排除理由",
  "intraday_strategy": "breakout_fresh|breakout_retest|gap_pullback|bounce|momentum",
  "suggested_buy": 29.5,
  "target_price": 30.5,
  "stop_loss": 29.0,
  "price_reasoning": "買入設前高29.5回測位，目標為4/8高點30.5，停損設MA10下方29.0",
  "reference_support": 29.0,
  "reference_resistance": 30.5,
  "warnings": ["注意事項，可為 null"]
}

若不選入，`intraday_strategy`、`suggested_buy`、`target_price`、`stop_loss`、`price_reasoning`、`reference_support`、`reference_resistance`、`warnings` 均可為 null。

價格設定原則：
- 買入價應設在合理回測位置（突破型設前高附近，反彈型設支撐附近）
- 目標價不超過收盤價 +10%，停損不超過收盤價 -2.5%
- 風報比（目標-買入）/（買入-停損）應 >= 1.5
SYSTEM;
    }

    // -------------------------------------------------------------------------
    // 個股 user message：K 線 / 籌碼 / 評分理由
    // -------------------------------------------------------------------------

    private function buildStockMessage(string $tradeDate, Candidate $candidate, bool $short = false): string
    {
        $stock   = $candidate->stock;
        $reasons = is_array($candidate->reasons)
            ? implode('；', $candidate->reasons)
            : ($candidate->reasons ?? '');

        // K 線：精簡模式只取 5 日
        $klineLimit = $short ? 5 : 10;
        $quotes = DailyQuote::where('stock_id', $candidate->stock_id)
            ->where('date', '<=', $tradeDate)
            ->orderByDesc('date')
            ->limit($klineLimit)
            ->get();

        $klineLines = ['日期  開  高  低  收  量(張)  漲%  振幅%'];
        foreach ($quotes as $q) {
            $klineLines[] = implode('  ', [
                \Carbon\Carbon::parse($q->date)->format('m/d'),
                (float) $q->open,
                (float) $q->high,
                (float) $q->low,
                (float) $q->close,
                round($q->volume / 1000),
                (float) $q->change_percent . '%',
                (float) $q->amplitude . '%',
            ]);
        }
        $klineTsv = implode("\n", $klineLines);

        // 法人近 5 日
        $instTrades = InstitutionalTrade::where('stock_id', $candidate->stock_id)
            ->where('date', '<=', $tradeDate)
            ->orderByDesc('date')
            ->limit(5)
            ->get();

        $fmtLots = fn($v) => ($v >= 0 ? '+' : '') . round($v / 1000) . '張';
        $instLines = $instTrades->map(fn($t) =>
            \Carbon\Carbon::parse($t->date)->format('m/d') . '  ' .
            '外資' . $fmtLots($t->foreign_net) . '  ' .
            '投信' . $fmtLots($t->trust_net) . '  ' .
            '自營' . $fmtLots($t->dealer_net)
        )->implode("\n");
        $instSection = $instLines ?: '（無法人資料）';

        // 融資融券近 5 日
        $margins = MarginTrade::where('stock_id', $candidate->stock_id)
            ->where('date', '<=', $tradeDate)
            ->orderByDesc('date')
            ->limit(5)
            ->get();

        $marginLines = $margins->map(fn($m) =>
            \Carbon\Carbon::parse($m->date)->format('m/d') . '  ' .
            '融資' . $fmtLots($m->margin_change) . '  ' .
            '融券' . $fmtLots($m->short_change)
        )->implode("\n");
        $marginSection = $marginLines ?: '（無融資券資料）';

        // 個股相關新聞（近 3 日，依類股 + 股票名稱/代號）
        $stockNews = NewsArticle::where('fetched_date', '>=', now()->subDays(3)->toDateString())
            ->where(fn($q) =>
                $q->where('industry', $stock->industry)
                  ->orWhere('title', 'like', "%{$stock->name}%")
                  ->orWhere('title', 'like', "%{$stock->symbol}%")
            )
            ->orderByDesc('published_at')
            ->limit($short ? 3 : 5)
            ->get();
        $stockNewsLines = $stockNews->map(fn($n) =>
            '- [' . \Carbon\Carbon::parse($n->published_at)->format('m/d') . '] ' .
            $n->title .
            ($n->sentiment_label ? "（{$n->sentiment_label}）" : '')
        )->implode("\n");
        $stockNewsSection = $stockNewsLines ?: '（近期無相關新聞）';

        return <<<MSG
## 待評估標的：{$stock->symbol} {$stock->name}（{$stock->industry}）

Haiku 信度：{$candidate->score}　Haiku 理由：{$candidate->haiku_reasoning}
建議買入：{$candidate->suggested_buy}　目標：{$candidate->target_price}　停損：{$candidate->stop_loss}　RR：{$candidate->risk_reward_ratio}
評分理由：{$reasons}

### 近 10 日 K 線
{$klineTsv}

### 近 5 日法人籌碼
{$instSection}

### 近 5 日融資融券
{$marginSection}

### 個股相關新聞
{$stockNewsSection}

請依上述資料及市場背景，回覆此標的的 JSON 評估結果。
MSG;
    }

    // -------------------------------------------------------------------------
    // API call（含 prompt caching + retry）
    // -------------------------------------------------------------------------

    /**
     * 帶 retry 的 API call：
     *  - 529 Overloaded：最多 3 次，指數退避 2s/4s/8s
     *  - 連線 timeout：最多 2 次，間隔 3s
     *  - 422 context too large：直接丟 ContextTooLargeException 讓上層換精簡 prompt
     */
    private function callApiWithRetry(string $systemPrompt, string $userMessage, string $symbol = '', int $maxTokens = 1024): array
    {
        $maxAttempts = 3;
        $attempt     = 0;

        while (true) {
            $attempt++;
            try {
                return $this->callApi($systemPrompt, $userMessage, $maxTokens);
            } catch (ContextTooLargeException $e) {
                throw $e; // 直接往上傳，不 retry
            } catch (\RuntimeException $e) {
                $msg = $e->getMessage();

                // 529 Overloaded
                if (str_contains($msg, 'API 529') || str_contains($msg, 'overloaded_error')) {
                    if ($attempt >= $maxAttempts) {
                        Log::error("AiScreenerService {$symbol}: 達最大重試次數（529），放棄");
                        throw $e;
                    }
                    $sleep = (2 ** $attempt); // 2s, 4s, 8s
                    Log::warning("AiScreenerService {$symbol}: 529 Overloaded，{$sleep}s 後重試（第 {$attempt} 次）");
                    sleep($sleep);
                    continue;
                }

                // JSON 解析失敗：最多重試 2 次（AI 偶爾回傳 markdown 或截斷）
                if (str_contains($msg, '無法解析') && $attempt < $maxAttempts) {
                    Log::warning("AiScreenerService {$symbol}: JSON 解析失敗，2s 後重試（第 {$attempt} 次）：" . mb_substr($msg, 0, 100));
                    sleep(2);
                    continue;
                }

                // 其他 RuntimeException（422 以外的 API 錯誤，或重試耗盡）
                throw $e;
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                if ($attempt >= 2) {
                    throw new \RuntimeException('連線逾時（重試後仍失敗）', 0, $e);
                }
                Log::warning("AiScreenerService {$symbol}: 連線 timeout，3s 後重試");
                sleep(3);
            }
        }
    }

    private function callApi(string $systemPrompt, string $userMessage, int $maxTokens = 1024): array
    {
        $response = Http::timeout(60)
            ->withHeaders([
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'anthropic-beta'    => 'prompt-caching-2024-07-31',
                'content-type'      => 'application/json',
            ])
            ->post('https://api.anthropic.com/v1/messages', [
                'model'      => $this->model,
                'max_tokens' => $maxTokens,
                'system'     => [
                    [
                        'type'          => 'text',
                        'text'          => $systemPrompt,
                        'cache_control' => ['type' => 'ephemeral'],
                    ],
                ],
                'messages' => [
                    ['role' => 'user', 'content' => $userMessage],
                ],
            ]);

        if ($response->status() === 422) {
            $body = $response->json();
            if (str_contains($body['error']['message'] ?? '', 'context reduction')) {
                throw new ContextTooLargeException('422 context reduction: ' . ($body['error']['message'] ?? ''));
            }
        }

        if (!$response->successful()) {
            throw new \RuntimeException('API ' . $response->status() . ': ' . $response->body());
        }

        $text = $response->json('content.0.text', '');
        $result = $this->parseSingleResponse($text);

        if ($result === null) {
            throw new \RuntimeException('無法解析 AI 回應：' . mb_substr($text, 0, 200));
        }

        return $result;
    }

    private function parseSingleResponse(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```json?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        $data = json_decode($text, true);

        if (!is_array($data) || !array_key_exists('selected', $data)) {
            return null;
        }

        return $data;
    }

    // -------------------------------------------------------------------------
    // 將單支評估結果寫入 DB
    // -------------------------------------------------------------------------

    private function applyResult(Candidate $candidate, array $result): void
    {
        $selected = (bool) ($result['selected'] ?? false);

        $updates = [
            'ai_selected'         => $selected,
            'ai_score_adjustment' => $result['score_adjustment'] ?? 0,
            'ai_reasoning'        => $result['reasoning'] ?? '',
            'intraday_strategy'   => $selected ? ($result['intraday_strategy'] ?? $this->defaultStrategy($candidate)) : null,
            'reference_support'   => $selected ? ($result['reference_support'] ?? $candidate->stop_loss) : null,
            'reference_resistance'=> $selected ? ($result['reference_resistance'] ?? $candidate->target_price) : null,
            'ai_warnings'         => $result['warnings'] ?? null,
            'ai_price_reasoning'  => $selected ? ($result['price_reasoning'] ?? null) : null,
        ];

        if ($selected) {
            if (!empty($result['suggested_buy']))  $updates['suggested_buy']  = $result['suggested_buy'];
            if (!empty($result['target_price']))   $updates['target_price']   = $result['target_price'];
            if (!empty($result['stop_loss']))      $updates['stop_loss']      = $result['stop_loss'];

            // 重算風報比
            $buy  = (float) ($updates['suggested_buy']  ?? $candidate->suggested_buy);
            $tgt  = (float) ($updates['target_price']   ?? $candidate->target_price);
            $stop = (float) ($updates['stop_loss']      ?? $candidate->stop_loss);
            if ($buy > $stop && $stop > 0) {
                $updates['risk_reward_ratio'] = round(($tgt - $buy) / ($buy - $stop), 2);
            }
        }

        $candidate->update($updates);
    }

    private function defaultStrategy(Candidate $candidate): string
    {
        return 'momentum';
    }

    // -------------------------------------------------------------------------
    // Overnight: system prompt
    // -------------------------------------------------------------------------

    private function buildSystemPromptOvernight(string $tradeDate, ?string $snapshotDate): string
    {
        $today           = $snapshotDate ?? now()->format('Y-m-d');
        $usMarketSection = UsMarketIndex::getSummary($tradeDate);
        $lessonsSection  = AiLesson::getOvernightLessons();
        $sectorSection   = SectorIndex::getSectorSummary($today);
        $statsSection    = StrategyPerformanceStat::getPromptSummary('overnight');

        // 近 3 日新聞（同當沖，供題材面判斷）
        $news = NewsArticle::where('fetched_date', '>=', now()->subDays(3)->toDateString())
            ->whereNotNull('industry')
            ->orderByDesc('published_at')
            ->limit(40)
            ->get();
        $newsLines = $news->map(fn($n) =>
            "- [{$n->industry}] {$n->title}" . ($n->sentiment_label ? "（{$n->sentiment_label}）" : '')
        )->implode("\n");
        $newsSection = $newsLines ?: '（無近期新聞）';

        // 消息面指數
        $latestNewsDate = NewsIndex::where('scope', 'overall')
            ->where('date', '<=', $today)
            ->orderByDesc('date')
            ->value('date');
        $newsIndexLines = [];
        if ($latestNewsDate) {
            $overall = NewsIndex::where('scope', 'overall')->where('date', $latestNewsDate)->first();
            if ($overall) {
                $newsIndexLines[] = "整體情緒: {$overall->sentiment} | 熱度: {$overall->heatmap} | 恐慌: {$overall->panic} | 國際: {$overall->international}";
            }
            NewsIndex::where('scope', 'industry')->where('date', $latestNewsDate)
                ->orderByDesc('sentiment')->get()
                ->each(fn($idx) => $newsIndexLines[] = "{$idx->scope_value}: 情緒 {$idx->sentiment} | 熱度 {$idx->heatmap} (文章數: {$idx->article_count})");
        }
        $newsIndexSection = $newsIndexLines ? implode("\n", $newsIndexLines) : '（無消息面指數）';

        // 持倉天數計算（含週末/假日）
        $holdingDays = Carbon::parse($today)->diffInDays(Carbon::parse($tradeDate));
        $holdingWarning = '';
        if ($holdingDays > 1) {
            $holdingWarning = <<<WARN

⚠️ **注意：本次持倉跨越 {$holdingDays} 個日曆天（{$today} 建倉 → {$tradeDate} 出場），中間包含非交易日。**
跨週末/連假持倉風險顯著高於一般隔日沖：
- 國際盤（美股、歐股、亞股）在此期間仍然交易，跳空風險大幅增加
- 週末/假日期間可能出現突發消息（政策、地緣政治、財報），開盤跳空方向難以預測
- 建議：停損應比一般隔日沖更寬（預留跳空空間）、倉位應更輕、風報比要求應更高（≥ 2.0）
- 非強勢標的不建議選入（只選趨勢明確、法人持續買超、無明顯利空的標的）
WARN;
        }

        return <<<SYSTEM
你是台股隔日沖選股 AI 助手。現在是 {$today} 午盤收盤前（12:50）。
任務：對每檔 Haiku 預篩通過的候選，進行深度分析，判斷今日收盤前建倉、{$tradeDate}（出場日）持有的隔日沖機會。
{$holdingWarning}

你需要設定三個關鍵價格：
- **建議買入價（suggested_buy）**：今日 13:00 附近的合理建倉價位，必須在 per-stock 訊息中標示的現價 ±2% 以內
- **目標價（target_price）**：明日技術面阻力位或延續高點
- **停損價（stop_loss）**：明日技術面支撐位，跌破即出

{$usMarketSection}

## 近期新聞與題材面（{$today}）
{$newsSection}

## 消息面指數
{$newsIndexSection}

## 類股強弱（前一交易日收盤）
{$sectorSection}

{$lessonsSection}

{$statsSection}

## 評估原則（隔日沖視角）
1. 今日盤中走勢：尾盤走強（13:00 附近維持高點）> 高檔整理 > 盤中拉回
2. 量能確認：爆量收紅（今日量 > 5日均量 1.5 倍）代表大資金進駐
3. 技術指標：RSI 50–80 適合隔日沖（不超漲也不弱勢）；K > D 且上升代表動能持續
4. 型態確認：突破近期高點 + 量配合 = 強烈看多；長上影線 + 大量 = 上方賣壓重
5. 類股動能：所屬類股今日漲幅前段加分，領頭羊個股隔日延續機率較高
6. 籌碼面：外資/投信近日淨買超加分；法人連續賣超警戒
7. 風報比要求：(目標-買入)/(買入-停損) >= 1.5

## 價格日期對應說明
- **suggested_buy**：今日（{$today}，建倉日 T+0）13:00 附近合理建倉價
- **target_price**：明日（{$tradeDate}，出場日 T+1）目標賣出價（技術阻力位）
- **stop_loss**：明日（{$tradeDate}，出場日 T+1）停損賣出價（技術支撐位跌破則出）
- **key_levels**：明日（{$tradeDate}）盤中重要支撐/壓力位，含理由，供盤中決策參考

## 各欄位填寫規則
- **reasoning**：技術面一句話摘要（選入或排除原因）
- **news_theme_reason**：**只能**引用下方 per-stock 訊息中「個股相關新聞與題材」及「類股強弱」兩個區塊的內容，說明有哪些利多新聞、題材或類股強勢加持此標的（1–2句）；若無相關新聞或類股落後則填 null
- **fundamental_reason**：**只能**引用「基本面估值」區塊的本益比、殖利率、股價淨值比具體數字，說明估值偏低/合理/偏高及對隔日沖的影響（1句）；若無估值資料則填 null
- **overnight_strategy**：操作須知，含何時建倉、明日關鍵觀察點、預期走勢（2–3句）
- **entry_type**：gap_up_open（跳空高開）｜pullback_entry（拉回建倉）｜open_follow_through（延續開盤）｜limit_up_chase（漲停追強）

## 回覆格式
請直接回覆 JSON（不要加 markdown 標記），格式：
{
  "selected": true,
  "reasoning": "技術面選入/排除一句話",
  "news_theme_reason": "新聞題材/類股強勢說明，或 null",
  "fundamental_reason": "本益比/殖利率/淨值比說明，或 null",
  "overnight_strategy": "操作須知2–3句",
  "entry_type": "gap_up_open|pullback_entry|open_follow_through|limit_up_chase",
  "gap_potential_percent": 1.5,
  "suggested_buy": 788.0,
  "target_price": 802.0,
  "stop_loss": 776.0,
  "price_reasoning": "三個價格設定依據",
  "key_levels": [
    {"price": 780.0, "type": "support", "reason": "MA20 支撐"},
    {"price": 800.0, "type": "resistance", "reason": "近期高點壓力"}
  ],
  "warnings": ["注意事項，可為空陣列"]
}

若不選入，news_theme_reason/fundamental_reason/overnight_strategy/entry_type/gap_potential_percent/suggested_buy/target_price/stop_loss/price_reasoning/key_levels/warnings 均可為 null。
SYSTEM;
    }

    // -------------------------------------------------------------------------
    // Overnight: per-stock user message
    // -------------------------------------------------------------------------

    private function buildStockMessageOvernight(
        string $tradeDate,
        Candidate $candidate,
        ?string $snapshotDate
    ): string {
        $today   = $snapshotDate ?? now()->format('Y-m-d');
        $stock   = $candidate->stock;
        $reasons = is_array($candidate->reasons)
            ? implode('；', $candidate->reasons)
            : ($candidate->reasons ?? '');

        // 近 10 日 K 線（tradeDate = T+1，date < tradeDate 即 T+0 以前）
        $quotes = DailyQuote::where('stock_id', $candidate->stock_id)
            ->where('date', '<', $tradeDate)
            ->orderByDesc('date')
            ->limit(10)
            ->get();

        $klineLines = ['日期  開  高  低  收  量(張)  漲%  振幅%'];
        foreach ($quotes as $q) {
            $klineLines[] = implode('  ', [
                \Carbon\Carbon::parse($q->date)->format('m/d'),
                (float) $q->open,
                (float) $q->high,
                (float) $q->low,
                (float) $q->close,
                round($q->volume / 1000),
                (float) $q->change_percent . '%',
                (float) $q->amplitude . '%',
            ]);
        }
        $klineTsv = implode("\n", $klineLines);

        // 技術指標
        $closes = $quotes->pluck('close')->map(fn($v) => (float) $v)->toArray();
        $highs  = $quotes->pluck('high')->map(fn($v) => (float) $v)->toArray();
        $lows   = $quotes->pluck('low')->map(fn($v) => (float) $v)->toArray();

        $rsi  = TechnicalIndicator::rsi($closes, 14);
        $kd   = TechnicalIndicator::kd($highs, $lows, $closes, 9);
        $ma5  = TechnicalIndicator::sma($closes, 5);
        $ma10 = TechnicalIndicator::sma($closes, 10);
        $atr  = TechnicalIndicator::atr($highs, $lows, $closes, 10);
        $boll = TechnicalIndicator::bollinger($closes, min(count($closes), 20));

        $indicatorParts = array_filter([
            $rsi  !== null ? "RSI(14)={$rsi}" : null,
            $kd   !== null ? "K={$kd['k']} D={$kd['d']}" : null,
            $ma5  !== null ? "MA5={$ma5}" : null,
            $ma10 !== null ? "MA10={$ma10}" : null,
            $atr  !== null ? "ATR(10)={$atr}" : null,
        ]);
        $indicatorLine = implode('　', $indicatorParts) ?: 'N/A';
        $bollLine      = $boll !== null
            ? "布林(20) 上:{$boll['upper']} 中:{$boll['middle']} 下:{$boll['lower']}"
            : '';

        // 今日盤中快照（按時間排序）
        $allSnapshots = IntradaySnapshot::where('stock_id', $candidate->stock_id)
            ->where('trade_date', $today)
            ->orderBy('snapshot_time')
            ->get();

        $intradaySection = '（無盤中快照資料）';
        $snapSummary     = '';
        $todayVolume     = 0;
        $dayHighSnap     = 0.0;
        $dayLowSnap      = 0.0;
        $currentPrice    = 0.0;

        if ($allSnapshots->isEmpty()) {
            // 非監控標的無快照，用 Fugle API 即時補抓報價 + 5分K
            $fugle = app(FugleRealtimeClient::class);
            $fugleQuotes = $fugle->fetchQuotes([$stock]);
            $fq = $fugleQuotes[$stock->symbol] ?? null;
            if ($fq && $fq['current_price'] > 0) {
                $currentPrice = (float) $fq['current_price'];
                $dayHighSnap  = (float) $fq['high'];
                $dayLowSnap   = (float) $fq['low'];
                $todayVolume  = (int) $fq['accumulated_volume'];
                $prevClose    = (float) $fq['prev_close'];
                $changePct    = $prevClose > 0 ? round(($currentPrice - $prevClose) / $prevClose * 100, 2) : 0;
                $openPrice    = (float) $fq['open'];
                $openChg      = $prevClose > 0 ? round(($openPrice - $prevClose) / $prevClose * 100, 2) : 0;
                $extRatio     = ($fq['trade_volume_at_ask'] + $fq['trade_volume_at_bid']) > 0
                    ? round($fq['trade_volume_at_ask'] / ($fq['trade_volume_at_ask'] + $fq['trade_volume_at_bid']) * 100, 1)
                    : 0;

                // 5 分 K 線
                usleep(150000); // rate limit
                $candles = $fugle->fetchCandles($stock->symbol);
                if (!empty($candles)) {
                    $candleLines = ['時間  開  高  低  收  量(張)  漲%'];
                    foreach ($candles as $c) {
                        $cPct = $prevClose > 0 ? round(($c['close'] - $prevClose) / $prevClose * 100, 2) : 0;
                        $candleLines[] = sprintf('%s  %.2f  %.2f  %.2f  %.2f  %d  %+.2f%%',
                            $c['time'], $c['open'], $c['high'], $c['low'], $c['close'], $c['volume'], $cPct
                        );
                    }
                    $intradaySection = implode("\n", $candleLines);
                } else {
                    $intradaySection = sprintf(
                        "Fugle即時  開%.2f  高%.2f  低%.2f  現%.2f  %+.2f%%  外盤%.1f%%  量%d張",
                        $openPrice, $dayHighSnap, $dayLowSnap, $currentPrice,
                        $changePct, $extRatio, round($todayVolume / 1000)
                    );
                }

                $midPrice   = ($dayHighSnap + $dayLowSnap) / 2;
                $trendLabel = match(true) {
                    $changePct > 2.0 && $currentPrice >= $dayHighSnap * 0.99 => '強勢衝高',
                    $changePct > 0.5 && $currentPrice >= $midPrice            => '高檔整理',
                    $changePct > 0 && $currentPrice < $midPrice               => '盤中拉回',
                    $changePct < -2.0                                          => '明顯弱勢',
                    default                                                    => '盤整',
                };
                $snapSummary = sprintf(
                    '現況摘要: 現價%.2f(%+.2f%%) 開盤%+.2f%% 日高%.2f/日低%.2f 外盤%.1f%% 走勢:%s（Fugle即時）',
                    $currentPrice, $changePct, $openChg, $dayHighSnap, $dayLowSnap, $extRatio, $trendLabel
                );
            }
        } elseif ($allSnapshots->isNotEmpty()) {
            $dayHighSnap  = (float) $allSnapshots->max('high');
            $dayLowSnap   = (float) $allSnapshots->min('low');
            $lastSnap     = $allSnapshots->last();
            $currentPrice = (float) $lastSnap->current_price;
            $todayVolume  = (int) $lastSnap->accumulated_volume;

            // 每小時整點快照 + 最新
            $targetTimes = ['09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '12:00', '12:30', '13:00'];
            $intradayLines = ['時間  現價  漲%  外盤%  量比'];
            foreach ($targetTimes as $timeStr) {
                $targetDt = \Carbon\Carbon::parse("{$today} {$timeStr}");
                $snap = $allSnapshots->first(fn($s) =>
                    abs(\Carbon\Carbon::parse($s->snapshot_time)->diffInSeconds($targetDt)) <= 150
                );
                if ($snap) {
                    $intradayLines[] = sprintf('%s  %.2f  %+.2f%%  %.1f%%  %.2fx',
                        \Carbon\Carbon::parse($snap->snapshot_time)->format('H:i'),
                        (float) $snap->current_price,
                        (float) $snap->change_percent,
                        (float) $snap->external_ratio,
                        (float) $snap->estimated_volume_ratio
                    );
                }
            }
            $intradayLines[] = sprintf('%s(最新)  %.2f  %+.2f%%  %.1f%%  %.2fx',
                \Carbon\Carbon::parse($lastSnap->snapshot_time)->format('H:i'),
                $currentPrice,
                (float) $lastSnap->change_percent,
                (float) $lastSnap->external_ratio,
                (float) $lastSnap->estimated_volume_ratio
            );
            $intradaySection = implode("\n", $intradayLines);

            // 走勢標籤
            $changePct  = (float) $lastSnap->change_percent;
            $midPrice   = ($dayHighSnap + $dayLowSnap) / 2;
            $trendLabel = match(true) {
                $changePct > 2.0 && $currentPrice >= $dayHighSnap * 0.99 => '強勢衝高',
                $changePct > 0.5 && $currentPrice >= $midPrice            => '高檔整理',
                $changePct > 0 && $currentPrice < $midPrice               => '盤中拉回',
                $changePct < -2.0                                          => '明顯弱勢',
                default                                                    => '盤整',
            };
            $openChg    = (float) $lastSnap->open_change_percent;
            $snapSummary = sprintf(
                '現況摘要: 現價%.2f(%+.2f%%) 開盤%+.2f%% 日高%.2f/日低%.2f 走勢:%s',
                $currentPrice, $changePct, $openChg, $dayHighSnap, $dayLowSnap, $trendLabel
            );
        }

        // 衍生特徵：連漲天數
        $allChgPcts    = $quotes->pluck('change_percent')->map(fn($v) => (float) $v)->toArray();
        $consecutiveUp = 0;
        foreach ($allChgPcts as $chg) {
            if ($chg > 0) $consecutiveUp++;
            else break;
        }

        // 爆量判斷
        $avg5Vol  = $quotes->take(5)->avg(fn($q) => (float) $q->volume) ?: 1;
        $volMult  = $avg5Vol > 0 ? round($todayVolume / $avg5Vol, 1) : 0;
        $volLabel = match(true) {
            $volMult >= 2.0 => "超級爆量({$volMult}倍均量)",
            $volMult >= 1.5 => "爆量({$volMult}倍均量)",
            $volMult >= 1.0 => "量平({$volMult}倍均量)",
            default         => "縮量({$volMult}倍均量)",
        };

        // 今日 K 線型態（依盤中最新狀態判斷）
        $kPatternLabel = '無法判斷';
        if ($allSnapshots->isNotEmpty()) {
            $lastSnap  = $allSnapshots->last();
            $dayOpen   = (float) $lastSnap->open;
            $bodyAbs   = abs($currentPrice - $dayOpen);
            $range     = $dayHighSnap - $dayLowSnap;
            if ($range > 0) {
                $upperShadow = $dayHighSnap - max($dayOpen, $currentPrice);
                $lowerShadow = min($dayOpen, $currentPrice) - $dayLowSnap;
                $bodyRatio   = $bodyAbs / $range;
                $kPatternLabel = match(true) {
                    $currentPrice > $dayOpen && $bodyRatio > 0.6                    => '強勢長紅',
                    $currentPrice > $dayOpen && $bodyRatio > 0.3                    => '小紅',
                    $currentPrice < $dayOpen && $bodyRatio > 0.6                    => '長黑（注意）',
                    $upperShadow > $bodyAbs * 2 && $upperShadow > $range * 0.3      => '長上影線（上方賣壓）',
                    $lowerShadow > $bodyAbs * 2 && $lowerShadow > $range * 0.3      => '長下影線（下方撐盤）',
                    $bodyRatio < 0.1                                                 => '十字星（方向不明）',
                    default                                                          => '普通K棒',
                };
            }
        }

        // 類股強弱
        $industry   = $stock->industry ?? '';
        $sectorChg  = SectorIndex::getChangeForIndustry($today, $industry);
        $sectorRank = SectorIndex::getRankForIndustry($today, $industry);
        $sectorStr  = $sectorChg !== null
            ? "[{$industry}] 今日" . ($sectorChg >= 0 ? '+' : '') . "{$sectorChg}%"
              . ($sectorRank !== null ? "（類股排名第{$sectorRank}強）" : '')
            : "[{$industry}] 無類股資料";

        // 近 5 日法人籌碼
        $instTrades = InstitutionalTrade::where('stock_id', $candidate->stock_id)
            ->where('date', '<', $tradeDate)
            ->orderByDesc('date')
            ->limit(5)
            ->get();

        $fmtLots    = fn($v) => ($v >= 0 ? '+' : '') . round($v / 1000) . '張';
        $instLines  = $instTrades->map(fn($t) =>
            \Carbon\Carbon::parse($t->date)->format('m/d') . '  ' .
            '外資' . $fmtLots($t->foreign_net) . '  ' .
            '投信' . $fmtLots($t->trust_net) . '  ' .
            '自營' . $fmtLots($t->dealer_net)
        )->implode("\n");
        $instSection = $instLines ?: '（無法人資料）';

        // 近 5 日融資融券
        $margins = MarginTrade::where('stock_id', $candidate->stock_id)
            ->where('date', '<', $tradeDate)
            ->orderByDesc('date')
            ->limit(5)
            ->get();
        $marginLines = $margins->map(fn($m) =>
            \Carbon\Carbon::parse($m->date)->format('m/d') . '  ' .
            '融資增減' . $fmtLots($m->margin_change) . '  餘額' . round($m->margin_balance / 1000) . '張  ' .
            '融券增減' . $fmtLots($m->short_change) . '  餘額' . round($m->short_balance / 1000) . '張'
        )->implode("\n");
        $marginSection = $marginLines ?: '（無融資券資料）';

        // 基本面估值（本益比/殖利率/股價淨值比）
        $valuationSection = StockValuation::getSummaryForStock($candidate->stock_id, $today);

        // 個股相關新聞（近 5 日，依類股 + 股票名稱/代號）
        $stockNews = NewsArticle::where('fetched_date', '>=', now()->subDays(5)->toDateString())
            ->where(fn($q) =>
                $q->where('industry', $stock->industry)
                  ->orWhere('title', 'like', "%{$stock->name}%")
                  ->orWhere('title', 'like', "%{$stock->symbol}%")
            )
            ->orderByDesc('published_at')
            ->limit(6)
            ->get();
        $stockNewsLines = $stockNews->map(fn($n) =>
            '- [' . \Carbon\Carbon::parse($n->published_at)->format('m/d') . '] ' .
            $n->title .
            ($n->sentiment_label ? "（{$n->sentiment_label}）" : '')
        )->implode("\n");
        $stockNewsSection = $stockNewsLines ?: '（近期無相關新聞）';

        // 現價 ±2% 範圍，供 prompt 約束 suggested_buy
        $buyFloor = $currentPrice > 0 ? round($currentPrice * 0.98, 2) : 0;
        $buyCeil  = $currentPrice > 0 ? round($currentPrice * 1.02, 2) : 0;

        return <<<MSG
## 待評估標的：{$stock->symbol} {$stock->name}（{$stock->industry}）

Haiku 信度：{$candidate->score}　Haiku 理由：{$candidate->haiku_reasoning}
選股理由標籤：{$reasons}

### 類股強弱
{$sectorStr}

### 近 10 日 K 線
{$klineTsv}

### 技術指標
{$indicatorLine}
{$bollLine}

### 衍生特徵
連漲 {$consecutiveUp} 天　今日量能：{$volLabel}　今日K型：{$kPatternLabel}

### 今日盤中走勢
{$intradaySection}
{$snapSummary}

### 近 5 日法人籌碼
{$instSection}

### 近 5 日融資融券
{$marginSection}

### 基本面估值
{$valuationSection}

### 個股相關新聞與題材
{$stockNewsSection}

**重要：目前最新現價為 {$currentPrice}。suggested_buy 必須在現價 ±2% 以內（即 {$buyFloor}～{$buyCeil}），超出此範圍代表設定不合理。**

請依上述資料（技術面、籌碼面、基本面、題材面），設定合理的建議買入/目標/停損三個價格，並回覆此標的的隔日沖 JSON 評估結果。
MSG;
    }

    // -------------------------------------------------------------------------
    // Overnight: 將評估結果寫入 DB
    // -------------------------------------------------------------------------

    private function applyResultOvernight(Candidate $candidate, array $result): void
    {
        $selected = (bool) ($result['selected'] ?? false);
        $symbol   = $candidate->stock->symbol;

        $updates = [
            'ai_selected'                   => $selected,
            'ai_reasoning'                  => $result['reasoning'] ?? '',
            'overnight_news_reason'         => $selected ? ($result['news_theme_reason'] ?? null) : null,
            'overnight_fundamental_reason'  => $selected ? ($result['fundamental_reason'] ?? null) : null,
            'overnight_reasoning'           => $selected ? ($result['overnight_strategy'] ?? null) : null,
            'overnight_strategy'            => $selected ? ($result['entry_type'] ?? null) : null,
            'gap_potential_percent'         => $selected ? ($result['gap_potential_percent'] ?? null) : null,
            'ai_price_reasoning'            => $selected ? ($result['price_reasoning'] ?? null) : null,
            'overnight_key_levels'          => $selected ? ($result['key_levels'] ?? null) : null,
            'ai_warnings'                   => $result['warnings'] ?? null,
            'intraday_strategy'             => null, // 隔日沖不設當沖策略
        ];

        if ($selected) {
            $buy  = (float) ($result['suggested_buy'] ?? 0);
            $tgt  = (float) ($result['target_price'] ?? 0);
            $stop = (float) ($result['stop_loss'] ?? 0);

            // 取得現價參考（快照優先，Fugle fallback）
            $snapshotDay = $candidate->trade_date->subDay()->format('Y-m-d');
            $refPrice = IntradaySnapshot::where('stock_id', $candidate->stock_id)
                ->where('trade_date', $snapshotDay)
                ->orderByDesc('snapshot_time')
                ->value('current_price');

            if (!$refPrice) {
                $fugle = app(FugleRealtimeClient::class);
                $fqResult = $fugle->fetchQuotes([$candidate->stock]);
                $refPrice = ($fqResult[$symbol] ?? null) ? $fqResult[$symbol]['current_price'] : null;
            }

            // AI 未回傳價格時用現價自動補
            if ($buy <= 0 && $refPrice && (float) $refPrice > 0) {
                $buy = (float) $refPrice;
                Log::warning("AiScreenerService overnight {$symbol}: AI 未回傳 suggested_buy，以現價 {$refPrice} 補入");
            }

            if ($buy > 0) {
                // 現價防護：suggested_buy 偏離現價超過 3% 則修正為現價
                if ($refPrice && (float) $refPrice > 0) {
                    $deviation = abs($buy - (float) $refPrice) / (float) $refPrice;
                    if ($deviation > 0.03) {
                        Log::warning("AiScreenerService overnight {$symbol}: buy {$buy} 偏離現價 {$refPrice} 達 " . round($deviation * 100, 1) . "%，修正為現價");
                        $buy = (float) $refPrice;
                    }
                }

                // 邊界保護：target > buy > stop
                if ($tgt <= $buy) {
                    $tgt = round($buy * 1.03, 2);
                    Log::warning("AiScreenerService overnight {$symbol}: target <= buy，修正為 {$tgt}");
                }
                if ($stop >= $buy) {
                    $stop = round($buy * 0.97, 2);
                    Log::warning("AiScreenerService overnight {$symbol}: stop >= buy，修正為 {$stop}");
                }

                $updates['suggested_buy'] = $buy;
                $updates['target_price']  = $tgt;
                $updates['stop_loss']     = $stop;

                if ($buy > $stop) {
                    $updates['risk_reward_ratio'] = round(($tgt - $buy) / ($buy - $stop), 2);
                }
            }
        }

        $candidate->update($updates);
    }
}

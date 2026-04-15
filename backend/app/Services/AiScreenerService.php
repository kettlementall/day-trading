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
    public function screen(string $tradeDate, Collection $candidates): Collection
    {
        if ($candidates->isEmpty()) {
            return $candidates;
        }

        if (!$this->apiKey) {
            Log::warning('AiScreenerService: ANTHROPIC_API_KEY 未設定，使用 fallback');
            return $this->fallbackScreen($candidates);
        }

        // 市場背景 system prompt — Anthropic 快取，所有股票共用
        $systemPrompt      = $this->buildSystemPrompt($tradeDate);
        $systemPromptShort = $this->buildSystemPrompt($tradeDate, short: true);

        foreach ($candidates as $candidate) {
            $symbol = $candidate->stock->symbol;
            try {
                $userMessage = $this->buildStockMessage($tradeDate, $candidate);
                $result = $this->callApiWithRetry($systemPrompt, $userMessage, $symbol);
                $this->applyResult($candidate, $result);
                Log::info("AiScreenerService {$symbol}: " . ($result['selected'] ? '選入' : '排除'));
            } catch (ContextTooLargeException $e) {
                // 422 context too large：改用精簡 system prompt 重試一次
                Log::warning("AiScreenerService {$symbol}: context 過大，改用精簡 prompt 重試");
                try {
                    $userMessage = $this->buildStockMessage($tradeDate, $candidate, short: true);
                    $result = $this->callApiWithRetry($systemPromptShort, $userMessage, $symbol);
                    $this->applyResult($candidate, $result);
                    Log::info("AiScreenerService {$symbol}(short): " . ($result['selected'] ? '選入' : '排除'));
                } catch (\Exception $e2) {
                    Log::error("AiScreenerService {$symbol}(short): " . $e2->getMessage());
                    $candidate->update([
                        'ai_selected'  => false,
                        'ai_reasoning' => 'AI 評估失敗（context 過大）',
                    ]);
                }
            } catch (\Exception $e) {
                Log::error("AiScreenerService {$symbol}: " . $e->getMessage());
                $candidate->update([
                    'ai_selected'  => false,
                    'ai_reasoning' => 'AI 評估失敗：' . mb_substr($e->getMessage(), 0, 80),
                ]);
            }
            usleep(150_000); // 150ms，避免觸發 rate limit
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
    private function callApiWithRetry(string $systemPrompt, string $userMessage, string $symbol = ''): array
    {
        $maxAttempts = 3;
        $attempt     = 0;

        while (true) {
            $attempt++;
            try {
                return $this->callApi($systemPrompt, $userMessage);
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

                // 其他 RuntimeException（parse 失敗、422 以外的 API 錯誤）
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

    private function callApi(string $systemPrompt, string $userMessage): array
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
                'max_tokens' => 1024,
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
}

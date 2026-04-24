<?php

namespace App\Services;

use App\Models\AiLesson;
use App\Models\Candidate;
use App\Models\CandidateMonitor;
use App\Models\DailyQuote;
use App\Models\DailyReview;
use App\Models\IntradayQuote;
use App\Models\NewsIndex;
use App\Models\Stock;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DailyReviewService
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key', '');
        $this->model = config('services.anthropic.model', 'claude-opus-4-6');
    }

    /**
     * 產出單日候選標的 AI 檢討報告
     */
    public function review(string $date, ?\Closure $logger = null, ?\Closure $onChunk = null, string $mode = 'intraday'): array
    {
        if ($mode === 'overnight') {
            return $this->reviewOvernight($date, $logger, $onChunk);
        }

        $log = $logger ?? function (string $msg) { Log::info($msg); };

        $log("開始分析 {$date} 的候選標的...");

        // 1. 收集候選標的 + 盤後結果
        $candidates = Candidate::with(['stock', 'result'])
            ->where('trade_date', $date)
            ->where('mode', 'intraday')
            ->orderByDesc('score')
            ->get();

        if ($candidates->isEmpty()) {
            return ['error' => "無 {$date} 的候選標的資料"];
        }

        $log("找到 {$candidates->count()} 檔候選標的");

        // 2. 收集每檔的盤中資料
        $intradayData = IntradayQuote::whereIn('stock_id', $candidates->pluck('stock_id'))
            ->where('date', $date)
            ->get()
            ->keyBy('stock_id');

        // 3. 收集每檔近 20 日 K 線（含當日）
        $klineData = [];
        foreach ($candidates as $c) {
            $quotes = DailyQuote::where('stock_id', $c->stock_id)
                ->where('date', '<=', $date)
                ->orderByDesc('date')
                ->limit(20)
                ->get()
                ->reverse()
                ->values();
            $klineData[$c->stock_id] = $quotes;
        }

        $log("已收集盤中行情與 K 線資料");

        // 4. 收集消息面指數
        $newsOverall = NewsIndex::where('scope', 'overall')
            ->where('date', '<=', $date)
            ->orderByDesc('date')
            ->first();
        $newsIndustries = $newsOverall
            ? NewsIndex::where('scope', 'industry')
                ->where('date', $newsOverall->date)
                ->pluck('sentiment', 'scope_value')
            : collect();

        // 5. 組裝 prompt 資料
        $prompt = $this->buildReviewPrompt($date, $candidates, $intradayData, $klineData, $newsOverall, $newsIndustries);

        $log("資料準備完成，呼叫 AI 分析中...");

        // 6. 呼叫 Claude Streaming API，透過 onChunk 即時推送文字片段
        $report = $this->callApiStreaming($prompt, $onChunk);

        $log("分析完成");

        // 存入 DB（同日覆蓋）
        DailyReview::updateOrCreate(
            ['trade_date' => $date, 'mode' => 'intraday'],
            ['candidates_count' => $candidates->count(), 'report' => $report]
        );

        return [
            'date' => $date,
            'candidates_count' => $candidates->count(),
            'report' => $report,
        ];
    }

    /**
     * 隔日沖模式：產出單日 AI 檢討報告
     */
    public function reviewOvernight(string $date, ?\Closure $logger = null, ?\Closure $onChunk = null): array
    {
        $log = $logger ?? function (string $msg) { Log::info($msg); };

        $log("開始分析 {$date} 的隔日沖候選標的...");

        $candidates = Candidate::with(['stock', 'result'])
            ->where('trade_date', $date)
            ->where('mode', 'overnight')
            ->orderByDesc('score')
            ->get();

        if ($candidates->isEmpty()) {
            return ['error' => "無 {$date} 隔日沖候選標的資料"];
        }

        $log("找到 {$candidates->count()} 檔隔日沖候選標的");

        // 近 10 日 K 線
        $klineData = [];
        foreach ($candidates as $c) {
            $klineData[$c->stock_id] = DailyQuote::where('stock_id', $c->stock_id)
                ->where('date', '<=', $date)
                ->orderByDesc('date')
                ->limit(10)
                ->get()
                ->reverse()
                ->values();
        }

        $log("已收集 K 線資料，呼叫 AI 分析中...");

        $prompt = $this->buildOvernightReviewPrompt($date, $candidates, $klineData);
        $report = $this->callApiStreaming($prompt, $onChunk);

        $log("分析完成");

        DailyReview::updateOrCreate(
            ['trade_date' => $date, 'mode' => 'overnight'],
            ['candidates_count' => $candidates->count(), 'report' => $report]
        );

        return [
            'date'             => $date,
            'mode'             => 'overnight',
            'candidates_count' => $candidates->count(),
            'report'           => $report,
        ];
    }

    private function buildOvernightReviewPrompt(string $date, $candidates, array $klineData): string
    {
        $lines = ["symbol\tname\tind\tai_selected\tentry_type\thaiku_reason\thaiku_conf\tbuy\ttarget\tstop\trr\tgap_potential%\topen\thigh\tlow\tclose\topen_gap%\tgap_ok?\toutcome\tprofit%"];

        foreach ($candidates as $c) {
            $r = $c->result;
            $suggestedBuy = (float) $c->suggested_buy;

            $profit = '-';
            if ($r && $suggestedBuy > 0) {
                if ($r->hit_target) {
                    $profit = round(((float) $c->target_price - $suggestedBuy) / $suggestedBuy * 100, 2);
                } elseif ($r->hit_stop_loss) {
                    $profit = round(-($suggestedBuy - (float) $c->stop_loss) / $suggestedBuy * 100, 2);
                } elseif ($r->actual_close) {
                    $profit = round(((float) $r->actual_close - $suggestedBuy) / $suggestedBuy * 100, 2);
                }
            }

            $lines[] = implode("\t", [
                $c->stock->symbol,
                $c->stock->name,
                $c->stock->industry ?? '-',
                $c->ai_selected ? 'Y' : 'N',
                $c->overnight_strategy ?? '-',
                $c->haiku_reasoning ?? '-',
                $c->score ?? 0,
                $suggestedBuy,
                (float) $c->target_price,
                (float) $c->stop_loss,
                (float) $c->risk_reward_ratio,
                (float) $c->gap_potential_percent,
                $r ? (float) $r->actual_open : '-',
                $r ? (float) $r->actual_high : '-',
                $r ? (float) $r->actual_low : '-',
                $r ? (float) $r->actual_close : '-',
                $r ? (float) $r->open_gap_percent : '-',
                $r ? ($r->gap_predicted_correctly ? 'Y' : 'N') : '-',
                $r ? ($r->overnight_outcome ?? '-') : '-',
                $profit,
            ]);
        }
        $candidatesTsv = implode("\n", $lines);

        // K 線摘要（每檔取最近 5 日 + 今日）
        $klineLines = ["symbol\tdate\topen\thigh\tlow\tclose\tvol(張)\tchange%"];
        foreach ($candidates as $c) {
            $quotes = $klineData[$c->stock_id] ?? collect();
            foreach ($quotes->slice(-6) as $q) {
                $klineLines[] = implode("\t", [
                    $c->stock->symbol,
                    $q->date->format('m/d'),
                    (float) $q->open,
                    (float) $q->high,
                    (float) $q->low,
                    (float) $q->close,
                    round($q->volume / 1000),
                    (float) $q->change_percent,
                ]);
            }
        }
        $klineTsv = implode("\n", $klineLines);

        // 監控系統軌跡（隔日沖）
        $monitorSection = '';
        $monitors = CandidateMonitor::with('candidate.stock')
            ->whereHas('candidate', fn($q) => $q->where('trade_date', $date)->where('mode', 'overnight'))
            ->get();

        if ($monitors->isNotEmpty()) {
            $monitorLines = ["symbol\tstatus\tentry_price\tentry_time\texit_price\texit_time\ttarget\tstop\tai_notes"];
            foreach ($monitors as $m) {
                $c = $m->candidate;
                $lastAdvice = $m->ai_advice_log ? collect($m->ai_advice_log)->pluck('notes')->implode(' → ') : '';

                $monitorLines[] = implode("\t", [
                    $c->stock->symbol,
                    $m->status,
                    $m->entry_price ?? '-',
                    $m->entry_time?->format('H:i') ?? '-',
                    $m->exit_price ?? '-',
                    $m->exit_time?->format('H:i') ?? '-',
                    $m->current_target ?? '-',
                    $m->current_stop ?? '-',
                    mb_substr($lastAdvice, 0, 80),
                ]);
            }
            $monitorTsv = implode("\n", $monitorLines);
            $monitorSection = <<<MONITOR

## AI 監控系統軌跡
{$monitorTsv}

### 欄位說明
status=最終狀態(target_hit/stop_hit/trailing_stop/closed/holding), entry/exit=進出場價與時間, target/stop=最終目標與停損（可能經 AI 調整）, ai_notes=AI 滾動建議摘要
MONITOR;
        }

        $selectedCount = $candidates->where('ai_selected', true)->count();
        $totalCount = $candidates->count();

        return <<<PROMPT
你是台股隔日沖交易檢討分析師。請針對 {$date} 隔日沖候選標的做全面檢討分析。

## 重要背景
本系統經三階段篩選：寬篩 → Haiku 預篩 → Opus 深度審核。
以下 {$totalCount} 檔候選標的中，只有 {$selectedCount} 檔被 AI 最終選入（ai_selected=Y），其餘為被排除的標的。
**請以 ai_selected=Y 的標的為主要評估對象**，被排除的標的僅作為對照參考（驗證排除決策是否正確）。

## 分析目標
1. AI 選入的標的表現如何：成功率、跳空預測準確度、價格設定合理性
2. AI 排除的標的是否確實表現較差（排除決策是否正確）
3. 跳空預測是否準確（gap_potential% 是否與實際 open_gap% 相符）
4. 進場策略（entry_type）與實際開盤表現的匹配度
5. 找出共通的成功/失敗模式

## 候選標的明細
{$candidatesTsv}

### 欄位說明
symbol=股票代號, name=名稱, ind=產業, ai_selected=AI最終選入(Y/N), entry_type=進場策略(gap_up_open/pullback_entry/open_follow_through/limit_up_chase), haiku_conf=Haiku信度, buy/target/stop=建議價, rr=風報比, gap_potential%=預測跳空幅度, open/high/low/close=實際T+1 OHLC, open_gap%=實際開盤跳空%, gap_ok?=跳空方向預測正確(Y/N), outcome=最終結果, profit%=報酬率

## 近 5 日 K 線（含今日）
{$klineTsv}
{$monitorSection}

## 輸出格式
請用繁體中文輸出，Markdown 格式：

### 一、整體表現
分別統計 AI 選入（ai_selected=Y）和排除（ai_selected=N）標的的表現：
- AI 選入標的：成功率、平均報酬率、跳空預測準確率
- AI 排除標的：假如也進場的成功率（驗證排除決策品質）
- 整體策略有效性評估

### 二、AI 選入標的逐檔分析
針對 ai_selected=Y 的標的，逐檔詳細分析：
- 選股邏輯是否合理（為何前一日選擇建倉？）
- 明日實際走勢是否符合預期？
- 三個價格設定（買入/目標/停損）是否合理？
- 監控系統的進出場決策是否恰當？

### 三、排除決策檢討
挑出被排除但事後表現好的標的（最多 5 檔），分析：
- AI 為何排除？排除理由是否合理？
- 是否有遺漏的選股訊號？

### 四、跳空分析
- 跳空方向預測準確率（gap_ok? = Y 的比例，分 ai_selected Y/N 統計）
- 哪類型的標的跳空預測較準確？
- 跳空預測有什麼系統性偏差？

### 五、策略改善建議
具體可改善的選股條件或進場策略調整。
PROMPT;
    }

    public function extractOvernightLessons(string $date, string $report): int
    {
        if (!$this->apiKey || strlen($report) < 100) {
            return 0;
        }

        AiLesson::where('trade_date', $date)
            ->where('mode', 'overnight')
            ->where('source', '!=', 'tip')
            ->delete();

        $prompt = <<<PROMPT
以下是 {$date} 的台股隔日沖交易檢討報告。請從中萃取結構化教訓，供未來 AI 隔日沖選股參考。

## 檢討報告
{$report}

## 萃取規則
- 最多萃取 5 條最重要的教訓，寧精勿多
- 只萃取**跨日通用**的規則，不要針對單一個股的特殊情況
- **禁止在 content 中提到具體股票名稱或代號**，用「該類型標的」「此類個股」等通用描述取代
- 禁止提到具體日期，用「本次」「近期」等取代
- 忽略籠統的建議（例如「要注意風險」）
- 每條教訓 type 分類：
  - `screening`：選股階段的教訓
  - `entry`：進場策略的教訓（建議買入價、進場條件）
  - `exit`：出場策略的教訓（目標價、停損設定）
  - `market`：大盤/產業面對隔日延續的影響

## 回覆格式（JSON array，不要加 markdown 標記）
[
  {
    "type": "screening",
    "category": "volume",
    "content": "連續漲停後第三日追高應排除，本次4檔中3檔停損（75%），獲利回吐壓力在第三日集中釋放"
  }
]
PROMPT;

        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model'      => $this->model,
                    'max_tokens' => 2048,
                    'messages'   => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

            if (!$response->successful()) {
                Log::error('DailyReviewService extractOvernightLessons API error: ' . $response->body());
                return 0;
            }

            // 檢查是否因 max_tokens 截斷
            $stopReason = $response->json('stop_reason', '');
            if ($stopReason === 'max_tokens') {
                Log::warning('DailyReviewService: extractOvernightLessons 回應被截斷，嘗試修復 JSON');
            }

            $text    = trim($response->json('content.0.text', ''));
            $cleaned = preg_replace('/^```json?\s*/i', '', $text);
            $cleaned = preg_replace('/\s*```$/', '', $cleaned);
            $lessons = json_decode(trim($cleaned), true);

            if (!is_array($lessons)) {
                // 找第一個 [ 到最後一個 ] 之間的內容
                if (preg_match('/\[[\s\S]*\]/u', $text, $m)) {
                    $lessons = json_decode($m[0], true);
                }
                // 若仍失敗，嘗試修復被截斷的 JSON
                if (!is_array($lessons) && ($bracketPos = strpos($text, '[')) !== false) {
                    $partial = substr($text, $bracketPos);
                    $lastComplete = strrpos($partial, '},');
                    if ($lastComplete !== false) {
                        $fixed = substr($partial, 0, $lastComplete + 1) . ']';
                        $decoded = json_decode($fixed, true);
                        if (is_array($decoded)) {
                            $lessons = $decoded;
                            Log::info("DailyReviewService: 修復截斷 JSON 成功（overnight），salvaged " . count($decoded) . " 條教訓");
                        }
                    }
                }
            }

            if (!is_array($lessons)) {
                Log::error('DailyReviewService: 無法解析隔日沖教訓 JSON', ['raw' => mb_substr($text, 0, 500)]);
                return 0;
            }

            $expiresAt   = now()->addDays(7)->toDateString();
            $validTypes  = ['screening', 'entry', 'exit', 'market'];
            $count       = 0;

            foreach ($lessons as $lesson) {
                if (empty($lesson['content']) || empty($lesson['type'])) continue;
                if (!in_array($lesson['type'], $validTypes)) continue;
                if ($count >= 5) break;

                AiLesson::create([
                    'trade_date' => $date,
                    'mode'       => 'overnight',
                    'type'       => $lesson['type'],
                    'category'   => $lesson['category'] ?? null,
                    'content'    => $lesson['content'],
                    'expires_at' => $expiresAt,
                ]);
                $count++;
            }

            Log::info("DailyReviewService: 從 {$date} 隔日沖檢討萃取 {$count} 條教訓");
            return $count;
        } catch (\Exception $e) {
            Log::error('DailyReviewService extractOvernightLessons: ' . $e->getMessage());
            return 0;
        }
    }

    private function buildReviewPrompt(
        string $date,
        $candidates,
        $intradayData,
        array $klineData,
        ?NewsIndex $newsOverall,
        $newsIndustries
    ): string {
        // 候選標的明細（TSV 格式）
        $lines = ["symbol\tname\tind\tai_selected\tstrat\thaiku_reason\ttags\tbuy\ttarget\tstop\trr\topen\thigh\tlow\tclose\tbuy?\ttgt?\tstop?\tprofit%\tmorning_grade\tmorning_score"];
        foreach ($candidates as $c) {
            $r = $c->result;
            $suggestedBuy = (float) $c->suggested_buy;

            $profit = '-';
            if ($r && $suggestedBuy > 0 && $r->buy_reachable) {
                if ($r->target_reachable) {
                    $profit = round(((float) $c->target_price - $suggestedBuy) / $suggestedBuy * 100, 2);
                } elseif ($r->hit_stop_loss) {
                    $profit = round(-($suggestedBuy - (float) $c->stop_loss) / $suggestedBuy * 100, 2);
                } else {
                    $profit = round(((float) $r->actual_close - $suggestedBuy) / $suggestedBuy * 100, 2);
                }
            }

            $tags = implode(',', is_array($c->reasons) ? $c->reasons : []);
            $lines[] = implode("\t", [
                $c->stock->symbol,
                $c->stock->name,
                $c->stock->industry ?? '-',
                $c->ai_selected ? 'Y' : 'N',
                $c->intraday_strategy ?? '-',
                $c->haiku_reasoning ?? '-',
                $tags,
                $suggestedBuy,
                (float) $c->target_price,
                (float) $c->stop_loss,
                (float) $c->risk_reward_ratio,
                $r ? (float) $r->actual_open : '-',
                $r ? (float) $r->actual_high : '-',
                $r ? (float) $r->actual_low : '-',
                $r ? (float) $r->actual_close : '-',
                $r ? ($r->buy_reachable ? 'Y' : 'N') : '-',
                $r ? ($r->target_reachable ? 'Y' : 'N') : '-',
                $r ? ($r->hit_stop_loss ? 'Y' : 'N') : '-',
                $profit,
                $c->morning_grade ?? '-',
                $c->morning_score ?? 0,
            ]);
        }
        $candidatesTsv = implode("\n", $lines);

        // 盤中資料
        $intradayLines = ["symbol\test_vol_ratio\topen_chg%\tcurrent\t5min_high\t5min_low\text_ratio%"];
        foreach ($candidates as $c) {
            $intra = $intradayData[$c->stock_id] ?? null;
            if (!$intra) continue;
            $intradayLines[] = implode("\t", [
                $c->stock->symbol,
                (float) $intra->estimated_volume_ratio,
                (float) $intra->open_change_percent,
                (float) $intra->current_price,
                (float) $intra->first_5min_high,
                (float) $intra->first_5min_low,
                (float) $intra->external_ratio,
            ]);
        }
        $intradayTsv = implode("\n", $intradayLines);

        // 近期 K 線摘要（每檔取最近 5 日）
        $klineLines = ["symbol\tdate\topen\thigh\tlow\tclose\tvol(張)\tchange%\tamplitude%"];
        foreach ($candidates as $c) {
            $quotes = $klineData[$c->stock_id] ?? collect();
            foreach ($quotes->slice(-5) as $q) {
                $klineLines[] = implode("\t", [
                    $c->stock->symbol,
                    $q->date->format('m/d'),
                    (float) $q->open,
                    (float) $q->high,
                    (float) $q->low,
                    (float) $q->close,
                    round($q->volume / 1000),
                    (float) $q->change_percent,
                    (float) $q->amplitude,
                ]);
            }
        }
        $klineTsv = implode("\n", $klineLines);

        // 監控系統軌跡
        $monitorSection = '';
        $monitors = CandidateMonitor::with('candidate.stock')
            ->whereHas('candidate', fn($q) => $q->where('trade_date', $date)->where('mode', 'intraday'))
            ->get();

        if ($monitors->isNotEmpty()) {
            $monitorLines = ["symbol\tstatus\tentry_price\tentry_time\texit_price\texit_time\ttarget\tstop\tMFE%\tMAE%\tcalibration\tai_notes"];
            foreach ($monitors as $m) {
                $c = $m->candidate;
                $r = $c->result;
                $calNotes = '';
                if ($m->ai_calibration) {
                    $calNotes = $m->ai_calibration['notes'] ?? ($m->ai_calibration['reason'] ?? '');
                }
                $lastAdvice = $m->ai_advice_log ? collect($m->ai_advice_log)->pluck('notes')->implode(' → ') : '';

                $monitorLines[] = implode("\t", [
                    $c->stock->symbol,
                    $m->status,
                    $m->entry_price ?? '-',
                    $m->entry_time?->format('H:i') ?? '-',
                    $m->exit_price ?? '-',
                    $m->exit_time?->format('H:i') ?? '-',
                    $m->current_target ?? '-',
                    $m->current_stop ?? '-',
                    $r?->mfe_percent ?? '-',
                    $r?->mae_percent ?? '-',
                    mb_substr($calNotes, 0, 50),
                    mb_substr($lastAdvice, 0, 80),
                ]);
            }
            $monitorTsv = implode("\n", $monitorLines);
            $monitorSection = <<<MONITOR
## AI 監控系統軌跡
{$monitorTsv}

### 欄位說明
status=最終狀態(target_hit/stop_hit/trailing_stop/closed/skipped/watching), entry/exit=進出場價與時間, MFE%=持有期間最大有利偏移, MAE%=最大不利偏移, calibration=AI開盤校準備註, ai_notes=AI滾動建議摘要
MONITOR;
        }

        // 消息面
        $newsSection = '（無消息面資料）';
        if ($newsOverall) {
            $newsSection = "整體情緒: {$newsOverall->sentiment}, 恐慌: {$newsOverall->panic}, 國際: {$newsOverall->international}";
            if ($newsIndustries->isNotEmpty()) {
                $indLines = $newsIndustries->map(fn ($s, $ind) => "{$ind}: {$s}")->implode(', ');
                $newsSection .= "\n產業情緒: {$indLines}";
            }
        }

        $selectedCount = $candidates->where('ai_selected', true)->count();
        $totalCount = $candidates->count();

        return <<<PROMPT
你是台股當沖交易檢討分析師。請針對 {$date} 這一天的候選標的做全面檢討分析。

## 重要背景
本系統經三階段篩選：規則式寬篩 → Haiku 批量預篩 → Opus 精審。
以下 {$totalCount} 檔候選標的中，只有 {$selectedCount} 檔被 Opus 最終選入（ai_selected=Y），其餘為被排除的標的。
**請以 ai_selected=Y 的標的為主要評估對象**，被排除的標的僅作為對照參考（驗證排除決策是否正確）。

## 分析目標
1. Opus 選入的標的表現如何：成功率、買入可達率、目標可達率
2. Opus 排除的標的是否確實表現較差（排除決策是否正確）
3. 盤中監控系統（校準、進出場、AI 滾動建議）的決策品質
4. 從盤後走勢反推，建議買入價和目標價的設定是否合理
5. 當天大盤和消息面對結果的影響

## 候選標的明細
{$candidatesTsv}

### 欄位說明
symbol=股票代號, name=名稱, ind=產業, ai_selected=Opus最終選入(Y/N), strat=策略(bounce=跌深反彈/breakout=突破追多), haiku_reason=Haiku預篩理由, tags=選股標籤(|分隔), buy/target/stop=建議價, rr=風報比, open/high/low/close=實際OHLC, buy?=買入可達(Y/N), tgt?=目標可達(Y/N), stop?=觸停損(Y/N), profit%=報酬率(-=未買到), morning_grade=盤前校準等級(A=強力推薦/B=標準進場/C=觀察/D=放棄), morning_score=盤前分數

## 盤中行情（開盤 30 分鐘時的快照）
{$intradayTsv}

### 欄位說明
est_vol_ratio=預估量倍數, open_chg%=開盤漲幅, current=快照時現價, 5min_high/low=第一根5分K高低點, ext_ratio%=外盤比

## 近 5 日 K 線
{$klineTsv}

## 消息面
{$newsSection}

{$monitorSection}

## 輸出格式

請用繁體中文輸出，用 Markdown 格式，包含以下段落：

### 一、當日總覽
簡述當天大盤氛圍、消息面影響，並分別統計 Opus 選入（ai_selected=Y）和排除（ai_selected=N）標的的整體表現：
- Opus 選入標的：成功率、買入可達率、目標可達率、平均報酬率
- Opus 排除標的：假如也進場的成功率（驗證排除決策品質）

### 二、Opus 選入標的逐檔分析
針對 ai_selected=Y 的標的，先用摘要表格列出（代號、策略、結果、一句話評語），然後逐檔分析：
- 選股邏輯是否合理（Opus 為何選入？Haiku 預篩理由是否準確？）
- 盤前設定是否合理（買入價、目標價、停損）
- 盤中表現如何（開盤位置、量能、走勢）
- 為什麼達標/未達標
- 如果重來，最佳進場時機在哪裡

### 三、盤中監控效果
針對有 AI 監控軌跡的標的：
- 開盤校準（morning_grade）是否準確？校準結果與盤中實際走勢的吻合度
- AI 滾動建議的品質：hold/adjust/exit 決策是否恰當？
- 目標/停損的動態調整是否合理（鎖利、移動停損）
- MFE vs 實際出場：有多少利潤留在桌上？
- 整體監控系統對當日損益的貢獻（正面或負面）

### 四、排除決策檢討
挑出被 Opus 排除但事後表現好的標的（最多 5 檔），分析：
- Opus 為何排除？排除理由是否合理？
- 是否有遺漏的選股訊號？

### 五、共通問題
找出系統性的模式問題，例如：
- 買入價是否系統性設太低/太高
- 特定策略類型（突破/反彈）是否有明顯偏差
- 評分高但虧損的標的有什麼共通點

### 六、改善建議
根據今天的觀察，分別針對選股、監控、出場三個環節，列出具體可改善的方向。
PROMPT;
    }

    /**
     * 從每日檢討報告中萃取結構化教訓，存入 ai_lessons
     */
    public function extractLessons(string $date, string $report): int
    {
        if (!$this->apiKey || strlen($report) < 100) {
            return 0;
        }

        // 刪除該日舊教訓，重新萃取（每次檢討都取最新版本）
        $deleted = AiLesson::where('trade_date', $date)->where('source', '!=', 'tip')->delete();
        if ($deleted > 0) {
            Log::info("DailyReviewService: 刪除 {$date} 舊教訓 {$deleted} 條，重新萃取");
        }

        $prompt = <<<PROMPT
以下是 {$date} 的台股當沖交易檢討報告。請從中萃取結構化教訓，供未來 AI 選股和盤中決策參考。

## 檢討報告
{$report}

## 萃取規則
- 最多萃取 5 條最重要的教訓，寧精勿多
- 只萃取**跨日通用**的規則，不要針對單一個股的特殊情況
- **禁止在 content 中提到具體股票名稱或代號**，用「該類型標的」「此類個股」等通用描述取代
- 禁止提到具體日期，用「本次」「近期」等取代
- 忽略籠統的建議（例如「要注意風險」）
- 每條教訓 type 分類：
  - `screening`：選股階段的教訓（哪些該選/不該選）
  - `calibration`：開盤校準的教訓（開盤數據如何判讀）
  - `entry`：進場時機的教訓（買入價設定、進場條件）
  - `exit`：出場時機的教訓（停損停利、出場條件）
  - `market`：大盤/產業面的教訓
- category 可選：breakout, bounce, gap, momentum, sector, volume, price_setting, timing
- 每條 content 一句話，要具體到可以直接影響決策

## 回覆格式（JSON array，不要加 markdown 標記）
[
  {
    "type": "entry",
    "category": "price_setting",
    "content": "突破回測型標的，買入價設前高回測位而非突破價上方，本次三檔因買入價設太高而錯過"
  },
  {
    "type": "screening",
    "category": "volume",
    "content": "連續 3 天量縮的標的即使技術面過關也應降低優先級，本次兩檔量縮標的全部未達標"
  }
]
PROMPT;

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
                Log::error('DailyReviewService extractLessons API error: ' . $response->body());
                return 0;
            }

            // 檢查是否因 max_tokens 截斷
            $stopReason = $response->json('stop_reason', '');
            if ($stopReason === 'max_tokens') {
                Log::warning('DailyReviewService: extractLessons 回應被截斷，嘗試修復 JSON');
            }

            $text = trim($response->json('content.0.text', ''));

            // 嘗試從回應中提取 JSON array（AI 可能在前後加說明文字或 markdown 標記）
            $lessons = null;
            // 先嘗試直接解析
            $cleaned = preg_replace('/^```json?\s*/i', '', $text);
            $cleaned = preg_replace('/\s*```$/', '', $cleaned);
            $decoded = json_decode(trim($cleaned), true);
            if (is_array($decoded)) {
                $lessons = $decoded;
            } else {
                // 找第一個 [ 到最後一個 ] 之間的內容
                if (preg_match('/\[[\s\S]*\]/u', $text, $m)) {
                    $decoded = json_decode($m[0], true);
                    if (is_array($decoded)) {
                        $lessons = $decoded;
                    }
                }
                // 若仍失敗，嘗試修復被截斷的 JSON（去掉最後不完整的物件，補上 ]）
                if (!is_array($lessons) && ($bracketPos = strpos($text, '[')) !== false) {
                    $partial = substr($text, $bracketPos);
                    // 找到最後一個完整的 }, 或 } 並截斷
                    $lastComplete = strrpos($partial, '},');
                    if ($lastComplete !== false) {
                        $fixed = substr($partial, 0, $lastComplete + 1) . ']';
                        $decoded = json_decode($fixed, true);
                        if (is_array($decoded)) {
                            $lessons = $decoded;
                            Log::info("DailyReviewService: 修復截斷 JSON 成功，salvaged " . count($decoded) . " 條教訓");
                        }
                    }
                }
            }

            if (!is_array($lessons)) {
                Log::error('DailyReviewService: 無法解析教訓 JSON', ['raw' => mb_substr($text, 0, 500)]);
                return 0;
            }

            $expiresAt = now()->addDays(7)->toDateString();
            $validTypes = ['screening', 'calibration', 'entry', 'exit', 'market'];
            $count = 0;

            foreach ($lessons as $lesson) {
                if (empty($lesson['content']) || empty($lesson['type'])) continue;
                if (!in_array($lesson['type'], $validTypes)) continue;
                if ($count >= 5) break;

                AiLesson::create([
                    'trade_date' => $date,
                    'type' => $lesson['type'],
                    'category' => $lesson['category'] ?? null,
                    'content' => $lesson['content'],
                    'expires_at' => $expiresAt,
                ]);
                $count++;
            }

            Log::info("DailyReviewService: 從 {$date} 檢討萃取 {$count} 條教訓");
            return $count;
        } catch (\Exception $e) {
            Log::error('DailyReviewService extractLessons: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 分析使用者手動輸入的明牌，從數值找理由並存成高優先教訓
     */
    public function analyzeTip(
        string $date,
        string $symbol,
        string $notes = '',
        ?\Closure $logger = null,
        ?\Closure $onChunk = null,
        string $mode = 'intraday'
    ): array {
        $log = $logger ?? function (string $msg) { Log::info($msg); };

        $stock = Stock::where('symbol', $symbol)->first();
        if (!$stock) {
            return ['error' => "找不到股票代號 {$symbol}"];
        }

        $log("開始分析明牌 {$symbol} {$stock->name} ({$date})...");

        // 近 15 日 K 線
        $klines = DailyQuote::where('stock_id', $stock->id)
            ->where('date', '<=', $date)
            ->orderByDesc('date')
            ->limit(15)
            ->get()
            ->reverse()
            ->values();

        if ($klines->isEmpty()) {
            return ['error' => "找不到 {$symbol} 在 {$date} 的 K 線資料"];
        }

        // 盤中快照（若有）
        $intraday = IntradayQuote::where('stock_id', $stock->id)
            ->where('date', $date)
            ->first();

        // 即時抓 5 分 K
        $log("抓取 {$date} 盤中 5 分 K...");
        $intradayKlines = $this->fetchYahooIntraday($stock, $date);
        if ($intradayKlines) {
            $log("取得 " . count($intradayKlines) . " 根 5 分 K");
        } else {
            $log("無盤中 5 分 K 資料（可能為非交易日或尚未開盤）");
        }

        $log("已收集資料，呼叫 AI 分析中...");

        $prompt = $this->buildTipPrompt($date, $stock, $klines, $intraday, $intradayKlines, $notes, $mode);
        $report = $this->callApiStreaming($prompt, $onChunk);

        $log("AI 分析完成");

        // 從報告中提取 JSON 教訓
        $lesson = null;
        if (preg_match('/\{[\s\S]*?\}/u', $report, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded) && !empty($decoded['content']) && !empty($decoded['type'])) {
                $lesson = $decoded;
            }
        }

        if ($lesson) {
            $validTypes = ['screening', 'calibration', 'entry', 'exit', 'market'];
            $type = in_array($lesson['type'], $validTypes) ? $lesson['type'] : 'screening';

            AiLesson::create([
                'trade_date' => $date,
                'mode'       => $mode,
                'type'       => $type,
                'category'   => $lesson['category'] ?? null,
                'content'    => $lesson['content'],
                'expires_at' => now()->addDays(60)->toDateString(), // 明牌教訓保留較久
                'source'     => 'tip',
                'priority'   => 1,
            ]);

            $log("教訓已儲存（優先級：高，有效期 60 天）");
        } else {
            $log("警告：未能從 AI 回應中提取結構化教訓");
        }

        return [
            'date'   => $date,
            'symbol' => $symbol,
            'name'   => $stock->name,
            'report' => $report,
            'lesson' => $lesson,
        ];
    }

    private function buildTipPrompt(
        string $date,
        Stock $stock,
        $klines,
        ?IntradayQuote $intraday,
        array $intradayKlines,
        string $notes,
        string $mode = 'intraday'
    ): string {
        // K 線表
        $klineLines = ["date\topen\thigh\tlow\tclose\tvol(張)\tchange%\tamplitude%"];
        foreach ($klines as $q) {
            $klineLines[] = implode("\t", [
                $q->date->format('m/d'),
                (float) $q->open,
                (float) $q->high,
                (float) $q->low,
                (float) $q->close,
                round($q->volume / 1000),
                (float) $q->change_percent,
                (float) $q->amplitude,
            ]);
        }
        $klineTsv = implode("\n", $klineLines);
        $industry = $stock->industry ?? '未知';

        // 盤中資料
        $intradaySection = '（無盤中快照資料）';
        if ($intraday) {
            $intradaySection = implode("\n", [
                "預估量倍數: {$intraday->estimated_volume_ratio}",
                "開盤漲幅: {$intraday->open_change_percent}%",
                "第一根5分K: 高={$intraday->first_5min_high} 低={$intraday->first_5min_low}",
                "外盤比: {$intraday->external_ratio}%",
            ]);
        }

        // 5 分 K 走勢
        $intradayKlineSection = '';
        if ($intradayKlines) {
            $lines = ["time\topen\thigh\tlow\tclose\tvol(股)"];
            foreach ($intradayKlines as $k) {
                $lines[] = "{$k['time']}\t{$k['open']}\t{$k['high']}\t{$k['low']}\t{$k['close']}\t{$k['volume']}";
            }
            $intradayKlineSection = "## 盤中 5 分 K 走勢（{$date}）\n" . implode("\n", $lines);
        }

        $notesSection = $notes ? "## 使用者備註\n{$notes}" : '';

        if ($mode === 'overnight') {
            // K 線最後一筆為出場日（T+1=$date），倒數第二筆為建倉日（T+0）
            $lastKline   = $klines->last();
            $entryKline  = $klines->count() >= 2 ? $klines[$klines->count() - 2] : null;
            $entryDate   = $entryKline ? $entryKline->date->format('Y-m-d') : '（建倉日未知）';

            return <<<PROMPT
你是台股隔日沖交易分析師。使用者於 {$entryDate} 收盤前建倉 {$stock->symbol} {$stock->name}（產業：{$industry}），並於 {$date} 出場，而且有賺錢。

請透過以下數值資料，找出最有可能解釋這筆隔日沖交易成功的技術面理由，
並在分析後萃取一條具體可操作的教訓，供未來 AI 隔日沖選股或出場判斷參考。

## 近期 K 線（最後兩筆依序為建倉日 {$entryDate}、出場日 {$date}）
{$klineTsv}

## 出場日盤中快照（{$date} 開盤 30 分鐘）
{$intradaySection}

{$intradayKlineSection}

{$notesSection}

## 分析要求
1. 從建倉日（{$entryDate}）的 K 線形態、量能、收盤位置，解釋為何當天尾盤適合建倉隔日沖
2. 出場日（{$date}）的跳空開盤幅度、量能、盤中走勢是否符合預期？關鍵訊號為何？
3. 這個建倉條件（強勢收盤 / 量增 / 突破 / 其他）是通用規律，還是當天特殊情況？
4. 如果要改善 AI 的隔日沖選股或出場設定，這個案例最重要的一條教訓是什麼？

請用繁體中文分析，最後**單獨**輸出一個 JSON 教訓（包在 {} 內，無其他標記）：
{"type": "screening|calibration|entry|exit|market", "category": "breakout|bounce|gap|volume|momentum|timing|price_setting|sector", "content": "一句具體可操作的規則（隔日沖視角）"}
PROMPT;
        }

        return <<<PROMPT
你是台股當沖交易分析師。使用者今天跟著外部訊號買了 {$stock->symbol} {$stock->name}（產業：{$industry}），而且有賺錢。

請透過以下數值資料，找出最有可能解釋這筆當沖交易成功的技術面理由，
並在分析後萃取一條具體可操作的教訓，供未來 AI 選股或盤中校準參考。

## 近期 K 線（含 {$date} 當日）
{$klineTsv}

## 盤中快照（{$date} 開盤 30 分鐘）
{$intradaySection}

{$intradayKlineSection}

{$notesSection}

## 分析要求
1. 從 K 線形態、量能、振幅等技術面，解釋為何 {$date} 是這檔股票的好進場時機
2. 指出哪些具體數值或形態是關鍵訊號（例如：量能放大倍數、跌深後反彈幅度、突破前高等）
3. 這個訊號是通用規律，還是當天特殊情況？
4. 如果要改善 AI 的選股或校準，這個案例最重要的一條教訓是什麼？

請用繁體中文分析，最後**單獨**輸出一個 JSON 教訓（包在 {} 內，無其他標記）：
{"type": "screening|calibration|entry|exit|market", "category": "breakout|bounce|gap|volume|momentum|timing|price_setting|sector", "content": "一句具體可操作的規則"}
PROMPT;
    }

    /**
     * 向 Yahoo Finance 抓取指定日期的 5 分 K 資料
     * 返回 [['time'=>'09:00','open'=>x,'high'=>x,'low'=>x,'close'=>x,'volume'=>x], ...]
     */
    private function fetchYahooIntraday(Stock $stock, string $date): array
    {
        $suffix = $stock->market === 'twse' ? '.TW' : '.TWO';
        $yahooSymbol = $stock->symbol . $suffix;

        $tz = new \DateTimeZone('Asia/Taipei');
        $start = (new \DateTime("{$date} 00:00:00", $tz))->getTimestamp();
        $end   = (new \DateTime("{$date} 23:59:59", $tz))->getTimestamp();

        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->get("https://query1.finance.yahoo.com/v8/finance/chart/{$yahooSymbol}", [
                    'interval' => '5m',
                    'period1'  => $start,
                    'period2'  => $end,
                ]);

            if (!$response->successful()) {
                return [];
            }

            $result = $response->json('chart.result.0');
            if (!$result) {
                return [];
            }

            $timestamps = $result['timestamp'] ?? [];
            $q = $result['indicators']['quote'][0] ?? [];
            $opens   = $q['open']   ?? [];
            $highs   = $q['high']   ?? [];
            $lows    = $q['low']    ?? [];
            $closes  = $q['close']  ?? [];
            $volumes = $q['volume'] ?? [];

            $klines = [];
            foreach ($timestamps as $i => $ts) {
                if (!isset($closes[$i]) || $closes[$i] === null) {
                    continue;
                }
                $dt = new \DateTime('@' . $ts);
                $dt->setTimezone($tz);
                $hour   = (int) $dt->format('G');
                $minute = (int) $dt->format('i');
                // 只保留台股交易時間 09:00–13:30
                if ($hour < 9 || $hour > 13 || ($hour === 13 && $minute > 30)) {
                    continue;
                }
                $klines[] = [
                    'time'   => $dt->format('H:i'),
                    'open'   => round((float) ($opens[$i]   ?? 0), 2),
                    'high'   => round((float) ($highs[$i]   ?? 0), 2),
                    'low'    => round((float) ($lows[$i]    ?? 0), 2),
                    'close'  => round((float) ($closes[$i]  ?? 0), 2),
                    'volume' => (int) ($volumes[$i] ?? 0),
                ];
            }

            return $klines;
        } catch (\Exception $e) {
            Log::warning("fetchYahooIntraday {$stock->symbol}: " . $e->getMessage());
            return [];
        }
    }

    private function callApiStreaming(string $prompt, ?\Closure $onChunk = null): string
    {
        if (!$this->apiKey) {
            return "**ANTHROPIC_API_KEY 未設定，無法產出 AI 報告。**\n\n請在 .env 中設定 `ANTHROPIC_API_KEY`。";
        }

        try {
            $fullText = '';

            $response = Http::timeout(180)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->withOptions(['stream' => true])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $this->model,
                    'max_tokens' => 16384,
                    'stream' => true,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

            if (!$response->successful()) {
                Log::error('DailyReviewService API error: ' . $response->status());
                return "**AI 分析失敗**：API 回傳錯誤 ({$response->status()})";
            }

            $body = $response->toPsrResponse()->getBody();
            $buffer = '';

            while (!$body->eof()) {
                $chunk = $body->read(8192);
                if ($chunk === '') continue;

                $buffer .= $chunk;

                // SSE 格式：以雙換行分隔事件
                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $event = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);

                    // 解析 data: 行
                    $dataLine = '';
                    foreach (explode("\n", $event) as $line) {
                        if (str_starts_with($line, 'data: ')) {
                            $dataLine = substr($line, 6);
                        }
                    }

                    if (!$dataLine || $dataLine === '[DONE]') continue;

                    $decoded = json_decode($dataLine, true);
                    if (!$decoded) continue;

                    // content_block_delta 事件包含文字片段
                    if (($decoded['type'] ?? '') === 'content_block_delta') {
                        $text = $decoded['delta']['text'] ?? '';
                        if ($text !== '') {
                            $fullText .= $text;
                            if ($onChunk) {
                                $onChunk($text);
                            }
                        }
                    }
                }
            }

            return $fullText ?: '無回應內容';
        } catch (\Exception $e) {
            Log::error('DailyReviewService: ' . $e->getMessage());
            return "**AI 分析失敗**：{$e->getMessage()}";
        }
    }
}

<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\CandidateMonitor;
use App\Models\DailyQuote;
use App\Models\IntradayQuote;
use App\Models\NewsIndex;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DailyReviewService
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key', '');
        $this->model = config('services.anthropic.model', 'claude-haiku-4-5-20251001');
    }

    /**
     * 產出單日候選標的 AI 檢討報告
     */
    public function review(string $date, ?\Closure $logger = null, ?\Closure $onChunk = null): array
    {
        $log = $logger ?? function (string $msg) { Log::info($msg); };

        $log("開始分析 {$date} 的候選標的...");

        // 1. 收集候選標的 + 盤後結果
        $candidates = Candidate::with(['stock', 'result'])
            ->where('trade_date', $date)
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

        return [
            'date' => $date,
            'candidates_count' => $candidates->count(),
            'report' => $report,
        ];
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
        $lines = ["symbol\tname\tind\tstrat\tscore\treasons\tbuy\ttarget\tstop\trr\topen\thigh\tlow\tclose\tbuy?\ttgt?\tstop?\tprofit%\tmorning_ok\tmorning_score"];
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

            $reasons = implode('|', is_array($c->reasons) ? $c->reasons : []);
            $lines[] = implode("\t", [
                $c->stock->symbol,
                $c->stock->name,
                $c->stock->industry ?? '-',
                $c->strategy_type ?? '-',
                $c->score,
                $reasons,
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
                $c->morning_confirmed ? 'Y' : 'N',
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
            ->whereHas('candidate', fn($q) => $q->where('trade_date', $date))
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

        return <<<PROMPT
你是台股當沖交易檢討分析師。請針對 {$date} 這一天的候選標的做全面檢討分析。

## 分析目標
1. 每檔標的為什麼買入可達/不可達、目標可達/不可達
2. 從盤後的走勢反推，建議買入價和目標價的設定是否合理
3. 找出共通的問題模式（例如：是不是某類型標的系統性設太高/太低）
4. 當天大盤和消息面對結果的影響

## 候選標的明細
{$candidatesTsv}

### 欄位說明
symbol=股票代號, name=名稱, ind=產業, strat=策略(bounce=跌深反彈/breakout=突破追多), score=評分, reasons=選股理由(|分隔), buy/target/stop=建議價, rr=風報比, open/high/low/close=實際OHLC, buy?=買入可達(Y/N), tgt?=目標可達(Y/N), stop?=觸停損(Y/N), profit%=報酬率(-=未買到), morning_ok=盤前確認通過(Y/N), morning_score=盤前分數

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
簡述當天大盤氛圍、消息面影響、候選標的整體表現。

### 二、逐檔分析
每檔標的用一個小標題（代號 名稱），分析：
- 盤前設定是否合理（買入價、目標價、停損）
- 盤中表現如何（開盤位置、量能、走勢）
- 為什麼達標/未達標
- 如果重來，最佳進場時機在哪裡
- AI 監控決策是否合理（校準、進場、出場時機）

### 三、共通問題
找出系統性的模式問題，例如：
- 買入價是否系統性設太低/太高
- 特定策略類型（突破/反彈）是否有明顯偏差
- 評分高但虧損的標的有什麼共通點
- AI 決策品質：校準是否準確、進出場時機是否恰當

### 四、AI 決策檢討
- MFE vs 實際出場：有多少利潤留在桌上？
- AI 否決的標的事後表現如何（是否正確否決）
- AI 滾動建議的品質（調整目標/停損是否合理）

### 五、改善建議
根據今天的觀察，列出具體可改善的方向。
PROMPT;
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
                    'max_tokens' => 8192,
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

<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\AiLesson;
use App\Models\Candidate;
use App\Models\DailyQuote;
use App\Models\InstitutionalTrade;
use App\Models\IntradaySnapshot;
use App\Models\NewsArticle;
use App\Models\NewsIndex;
use App\Models\SectorIndex;
use App\Models\UsMarketIndex;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\TelegramService;

class HaikuPreFilterService
{
    private string $apiKey;
    private string $model;
    private int $batchSize;

    public function __construct()
    {
        $this->apiKey    = config('services.anthropic.api_key', '');
        $this->model     = config('services.anthropic.haiku_model', 'claude-haiku-4-5-20251001');
        $this->batchSize = (int) config('services.anthropic.haiku_batch_size', 15);
    }

    /**
     * 批量快速預篩候選股
     *
     * 用 Haiku 對所有物理門檻通過的候選做快速判斷，
     * 更新 score（信度 0–100）、haiku_selected、haiku_reasoning。
     *
     * @param int|null $maxPassThrough 最多放行幾檔給 Opus（取信度最高的 N 檔，null = 不限制）
     */
    public function filter(
        string $tradeDate,
        Collection $candidates,
        ?int $maxPassThrough = null,
        string $mode = 'intraday',
        ?string $snapshotDate = null
    ): Collection {
        if ($candidates->isEmpty()) {
            return $candidates;
        }

        if (!$this->apiKey) {
            Log::warning('HaikuPreFilterService: ANTHROPIC_API_KEY 未設定，全部標記 haiku_selected=true');
            return $this->fallbackAll($candidates);
        }

        $systemPrompt = $mode === 'overnight'
            ? $this->buildSystemPromptOvernight($tradeDate, $snapshotDate)
            : $this->buildSystemPrompt($tradeDate);

        // 分批處理
        $batches = $candidates->chunk($this->batchSize);
        foreach ($batches as $batch) {
            try {
                $userMessage = $mode === 'overnight'
                    ? $this->buildBatchMessageOvernight($tradeDate, $batch, $snapshotDate)
                    : $this->buildBatchMessage($tradeDate, $batch);
                $results     = $this->callApi($systemPrompt, $userMessage);
                $this->applyBatchResults($batch, $results);
            } catch (\Exception $e) {
                Log::error('HaikuPreFilterService batch error: ' . $e->getMessage());
                // 批次失敗時標記排除（避免未經審核的標的進入 Opus）
                foreach ($batch as $candidate) {
                    $candidate->update([
                        'haiku_selected'  => false,
                        'haiku_reasoning' => 'Haiku 批次失敗，預設排除',
                        'score'           => 0,
                    ]);
                }
                app(TelegramService::class)->send(
                    "⚠️ Haiku 預篩批次失敗（{$batch->count()} 檔預設排除）：" . mb_substr($e->getMessage(), 0, 100)
                );
            }
            usleep(200_000); // 200ms，避免 rate limit
        }

        // 若設定了最多放行數，將信度不足的降為 haiku_selected=false
        if ($maxPassThrough !== null) {
            $passed = Candidate::where('trade_date', $tradeDate)
                ->where('mode', $mode)
                ->where('haiku_selected', true)
                ->orderByDesc('score')
                ->pluck('id');

            if ($passed->count() > $maxPassThrough) {
                $toReject = $passed->slice($maxPassThrough);
                Candidate::whereIn('id', $toReject)->update(['haiku_selected' => false]);
                Log::info("HaikuPreFilterService: 放行數限制 {$maxPassThrough}，額外排除 {$toReject->count()} 檔");
            }
        }

        return $candidates->fresh();
    }

    // -------------------------------------------------------------------------
    // System prompt（所有批次共用，Anthropic 快取）
    // -------------------------------------------------------------------------

    private function buildSystemPrompt(string $tradeDate): string
    {
        $usMarketSection = UsMarketIndex::getSummary($tradeDate);
        $lessonsSection  = AiLesson::getScreeningLessons();

        // 近 2 日新聞標題
        $news = NewsArticle::where('fetched_date', '>=', now()->subDays(2)->toDateString())
            ->whereNotNull('industry')
            ->orderByDesc('published_at')
            ->limit(20)
            ->get();
        $newsLines   = $news->map(fn($n) => "- [{$n->industry}] {$n->title}")->implode("\n");
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
                $newsIndexLines[] = "整體情緒:{$overall->sentiment} 熱度:{$overall->heatmap} 恐慌:{$overall->panic}";
            }
            NewsIndex::where('scope', 'industry')->where('date', $latestNewsDate)
                ->orderByDesc('sentiment')->limit(5)->get()
                ->each(fn($idx) => $newsIndexLines[] = "{$idx->scope_value}:情緒{$idx->sentiment}");
        }
        $newsIndexSection = $newsIndexLines ? implode("\n", $newsIndexLines) : '（無消息面指數）';

        return <<<SYSTEM
你是台股當沖選股 AI 助手（快速預篩模式）。現在是 {$tradeDate} 盤前。

我將以批次方式提交候選標的，每批最多 {$this->batchSize} 檔。
請對每檔快速判斷：「這檔股票今日是否值得進一步精審？」

{$usMarketSection}

## 近期新聞
{$newsSection}

## 消息面指數
{$newsIndexSection}

{$lessonsSection}

## 快速評估標準
- 量能：有量放大跡象（前日或今日量 > 5日均量 1.5 倍）優先
- 趨勢：突破型要站上支撐；反彈型要有洗盤訊號後止跌
- 籌碼：外資或投信買超加分；法人連續賣超警戒
- 排除：振幅太小不適合當沖、明顯弱勢型態
- 趨勢排列：注意標籤中的「多頭排列」（順勢）或「空頭排列」（逆勢），作為判斷參考之一

## 回覆格式
請直接回覆 JSON array（不要加 markdown），格式：
[
  {"symbol":"2330","keep":true,"confidence":75,"reason":"一句話理由"},
  {"symbol":"2317","keep":false,"confidence":20,"reason":"一句話理由"}
]

- keep: true = 值得精審，false = 不需要
- confidence: 0–100，代表值得精審的把握度
- reason: 一句話，說明關鍵理由（10–30 字）

每檔都必須回覆，不可省略。
SYSTEM;
    }

    // -------------------------------------------------------------------------
    // Per-batch user message（每批 15 檔的緊湊資料）
    // -------------------------------------------------------------------------

    private function buildBatchMessage(string $tradeDate, Collection $batch): string
    {
        $lines = ["## 批量預篩（{$batch->count()} 檔）：請依序回覆 JSON array\n"];

        foreach ($batch as $candidate) {
            $stock    = $candidate->stock;
            $symbol   = $stock->symbol;
            $name     = $stock->name;
            $industry = $stock->industry ?? '-';

            // 近 5 日 K 線（緊湊格式）
            $quotes = DailyQuote::where('stock_id', $candidate->stock_id)
                ->where('date', '<', $tradeDate)
                ->orderByDesc('date')
                ->limit(5)
                ->get();

            $kParts = [];
            foreach ($quotes as $q) {
                $kParts[] = sprintf(
                    '%s 收%.1f 量%dk 漲%s%%',
                    \Carbon\Carbon::parse($q->date)->format('m/d'),
                    (float) $q->close,
                    round($q->volume / 1000),
                    (float) $q->change_percent
                );
            }
            $kline = implode(' | ', $kParts) ?: '無K線';

            // 近 2 日法人
            $inst = InstitutionalTrade::where('stock_id', $candidate->stock_id)
                ->orderByDesc('date')
                ->limit(2)
                ->get();

            $instParts = [];
            foreach ($inst as $t) {
                $fNet = $t->foreign_net >= 0 ? '+' . round($t->foreign_net / 1000) : round($t->foreign_net / 1000);
                $tNet = $t->trust_net >= 0 ? '+' . round($t->trust_net / 1000) : round($t->trust_net / 1000);
                $instParts[] = sprintf('%s 外資%s張 投信%s張',
                    \Carbon\Carbon::parse($t->date)->format('m/d'),
                    $fNet, $tNet
                );
            }
            $instStr = implode(' | ', $instParts) ?: '無法人資料';

            // 事實標籤
            $tags = implode(',', is_array($candidate->reasons) ? $candidate->reasons : []);

            $lines[] = "{$symbol} {$name}（{$industry}）標籤:{$tags}";
            $lines[] = "  K線: {$kline}";
            $lines[] = "  法人: {$instStr}";
            $lines[] = "  參考買入:{$candidate->suggested_buy} 目標:{$candidate->target_price} 停損:{$candidate->stop_loss} RR:{$candidate->risk_reward_ratio}";
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Haiku API call（含 prompt caching）
    // -------------------------------------------------------------------------

    private function callApi(string $systemPrompt, string $userMessage): array
    {
        $response = Http::timeout(90)
            ->withHeaders([
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'anthropic-beta'    => 'prompt-caching-2024-07-31',
                'content-type'      => 'application/json',
            ])
            ->post('https://api.anthropic.com/v1/messages', [
                'model'      => $this->model,
                'max_tokens' => 4096,
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

        if (!$response->successful()) {
            throw new \RuntimeException('Haiku API ' . $response->status() . ': ' . $response->body());
        }

        $text   = $response->json('content.0.text', '');
        $parsed = $this->parseBatchResponse($text);

        if ($parsed === null) {
            throw new \RuntimeException('無法解析 Haiku 批次回應：' . mb_substr($text, 0, 300));
        }

        return $parsed;
    }

    private function parseBatchResponse(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```json?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        $data = json_decode($text, true);

        if (!is_array($data)) {
            return null;
        }

        // 確認是 array of objects
        foreach ($data as $item) {
            if (!isset($item['symbol'], $item['keep'])) {
                return null;
            }
        }

        return $data;
    }

    // -------------------------------------------------------------------------
    // 將批次結果寫入 DB
    // -------------------------------------------------------------------------

    private function applyBatchResults(Collection $batch, array $results): void
    {
        // 建立 symbol → result 的索引
        $resultMap = [];
        foreach ($results as $r) {
            $resultMap[$r['symbol']] = $r;
        }

        foreach ($batch as $candidate) {
            $symbol = $candidate->stock->symbol;
            $r      = $resultMap[$symbol] ?? null;

            if ($r === null) {
                // Haiku 沒有回覆這檔（異常），預設通過
                $candidate->update([
                    'haiku_selected'  => true,
                    'haiku_reasoning' => 'Haiku 未回覆此標的，預設通過',
                    'score'           => 50,
                ]);
                Log::warning("HaikuPreFilterService: {$symbol} 未在批次回應中，預設通過");
                continue;
            }

            $confidence = max(0, min(100, (int) ($r['confidence'] ?? 50)));
            $keep       = (bool) ($r['keep'] ?? false);
            $reason     = $r['reason'] ?? '';

            $candidate->update([
                'haiku_selected'  => $keep,
                'haiku_reasoning' => $reason,
                'score'           => $confidence,
            ]);

            Log::info("HaikuPreFilterService {$symbol}: " . ($keep ? '通過' : '排除') . " (信度{$confidence}) {$reason}");
        }
    }

    // -------------------------------------------------------------------------
    // Overnight system prompt
    // -------------------------------------------------------------------------

    private function buildSystemPromptOvernight(string $tradeDate, ?string $snapshotDate): string
    {
        $today         = $snapshotDate ?? now()->format('Y-m-d');
        $usMarketSection = UsMarketIndex::getSummary($tradeDate);
        $lessonsSection  = AiLesson::getOvernightLessons();

        $news = NewsArticle::where('fetched_date', '>=', now()->subDays(2)->toDateString())
            ->whereNotNull('industry')
            ->orderByDesc('published_at')
            ->limit(20)
            ->get();
        $newsSection = $news->map(fn($n) => "- [{$n->industry}] {$n->title}")->implode("\n") ?: '（無近期新聞）';

        $latestNewsDate = NewsIndex::where('scope', 'overall')
            ->where('date', '<=', $tradeDate)
            ->orderByDesc('date')
            ->value('date');
        $newsIndexLines = [];
        if ($latestNewsDate) {
            $overall = NewsIndex::where('scope', 'overall')->where('date', $latestNewsDate)->first();
            if ($overall) {
                $newsIndexLines[] = "整體情緒:{$overall->sentiment} 熱度:{$overall->heatmap} 恐慌:{$overall->panic}";
            }
            NewsIndex::where('scope', 'industry')->where('date', $latestNewsDate)
                ->orderByDesc('sentiment')->limit(5)->get()
                ->each(fn($idx) => $newsIndexLines[] = "{$idx->scope_value}:情緒{$idx->sentiment}");
        }
        $newsIndexSection = $newsIndexLines ? implode("\n", $newsIndexLines) : '（無消息面指數）';

        $sectorSection = SectorIndex::getSectorSummary($today);

        // 持倉天數提示
        $holdingDays = Carbon::parse($today)->diffInDays(Carbon::parse($tradeDate));
        $holdingNote = $holdingDays > 1
            ? "\n⚠️ 本次持倉跨 {$holdingDays} 天（含週末/假日），跳空風險顯著增加，非強勢標的應排除。"
            : '';

        return <<<SYSTEM
你是台股隔日沖選股 AI 助手（快速預篩模式）。現在是 {$today} 午盤前（12:50）。
任務：判斷這些股票在今日收盤前建倉，{$tradeDate}（出場日）是否有上漲延續潛力。{$holdingNote}

{$usMarketSection}

## 近期新聞
{$newsSection}

## 消息面指數
{$newsIndexSection}

## 類股強弱（今日 {$today}）
{$sectorSection}

{$lessonsSection}

## 快速評估標準（隔日沖視角）
- 今日盤中走勢：收盤強（尾盤拉高、收在高點附近）優先
- 量能特徵：今日爆量（> 5日均量 1.5 倍）+ 收紅 = 強烈買盤進駐
- 趨勢延續性：連漲 2 日以上 + 強勢排列（MA5 > MA10 > MA20）加分
- 類股動能：所屬類股今日強於大盤，個股明日延續機率更高
- 排除條件：今日跌幅 > 2%、今日爆量長黑、法人連續賣超、融資大幅增加
- 趨勢排列：「強勢排列」者隔日延續機率較高；「空頭排列」者持有過夜風險較大，需其他強力條件配合

## 回覆格式
請直接回覆 JSON array（不要加 markdown），格式：
[
  {"symbol":"2330","keep":true,"confidence":75,"reason":"一句話理由"},
  {"symbol":"2317","keep":false,"confidence":20,"reason":"一句話理由"}
]

- keep: true = 今日收盤前值得建倉，明日有上漲機會
- confidence: 0–100，代表隔日上漲的把握度
- reason: 一句話（強調今日盤中走勢 + 籌碼判斷，10–30 字）

每檔都必須回覆，不可省略。
SYSTEM;
    }

    // -------------------------------------------------------------------------
    // Overnight per-batch user message
    // -------------------------------------------------------------------------

    private function buildBatchMessageOvernight(string $tradeDate, Collection $batch, ?string $snapshotDate): string
    {
        $today = $snapshotDate ?? now()->format('Y-m-d');
        $lines = ["## 批量預篩（{$batch->count()} 檔，隔日沖模式）：請依序回覆 JSON array\n"];

        // 預先批次撈無快照標的的 Fugle 即時報價
        $noSnapStocks = $batch->filter(function ($c) use ($today) {
            return !IntradaySnapshot::where('stock_id', $c->stock_id)
                ->where('trade_date', $today)->exists();
        })->map(fn($c) => $c->stock)->values()->all();

        $fugleQuotes = [];
        if (!empty($noSnapStocks)) {
            $fugle = app(FugleRealtimeClient::class);
            $fugleQuotes = $fugle->fetchQuotes($noSnapStocks);
        }

        foreach ($batch as $candidate) {
            $stock    = $candidate->stock;
            $symbol   = $stock->symbol;
            $name     = $stock->name;
            $industry = $stock->industry ?? '-';

            // 近 5 日 K 線
            $quotes = DailyQuote::where('stock_id', $candidate->stock_id)
                ->where('date', '<', $tradeDate)
                ->orderByDesc('date')
                ->limit(5)
                ->get();

            $kParts = [];
            foreach ($quotes as $q) {
                $kParts[] = sprintf('%s 收%.1f 量%dk 漲%s%%',
                    \Carbon\Carbon::parse($q->date)->format('m/d'),
                    (float) $q->close,
                    round($q->volume / 1000),
                    (float) $q->change_percent
                );
            }
            $kline = implode(' | ', $kParts) ?: '無K線';

            // 近 2 日法人
            $inst = InstitutionalTrade::where('stock_id', $candidate->stock_id)
                ->orderByDesc('date')->limit(2)->get();
            $instParts = [];
            foreach ($inst as $t) {
                $fNet = $t->foreign_net >= 0 ? '+' . round($t->foreign_net / 1000) : round($t->foreign_net / 1000);
                $tNet = $t->trust_net >= 0 ? '+' . round($t->trust_net / 1000) : round($t->trust_net / 1000);
                $instParts[] = sprintf('%s 外資%s張 投信%s張',
                    \Carbon\Carbon::parse($t->date)->format('m/d'), $fNet, $tNet);
            }
            $instStr = implode(' | ', $instParts) ?: '無法人資料';

            // 今日盤中摘要（最新快照）
            $latestSnap = IntradaySnapshot::where('stock_id', $candidate->stock_id)
                ->where('trade_date', $today)
                ->orderByDesc('snapshot_time')
                ->first();

            if ($latestSnap) {
                $dayHigh = IntradaySnapshot::where('stock_id', $candidate->stock_id)
                    ->where('trade_date', $today)
                    ->max('high');
                $dayLow = IntradaySnapshot::where('stock_id', $candidate->stock_id)
                    ->where('trade_date', $today)
                    ->min('low');

                $changePct    = (float) $latestSnap->change_percent;
                $currentPrice = (float) $latestSnap->current_price;
                $volRatio     = (float) $latestSnap->estimated_volume_ratio;
                $extRatio     = (float) $latestSnap->external_ratio;
                $openChg      = (float) $latestSnap->open_change_percent;

                $midPrice  = ($dayHigh + $dayLow) / 2;
                $trendLabel = match(true) {
                    $changePct > 2.0 && $currentPrice >= $dayHigh * 0.99            => '強勢衝高',
                    $changePct > 0.5 && $currentPrice >= $midPrice                  => '高檔整理',
                    $changePct > 0 && $currentPrice < $midPrice                     => '盤中拉回',
                    $changePct < -2.0                                                => '明顯弱勢',
                    default                                                          => '盤整',
                };

                $sign = $changePct >= 0 ? '+' : '';
                $openSign = $openChg >= 0 ? '+' : '';
                $intradaySummary = "今日盤中: 開盤{$openSign}{$openChg}% 現價{$currentPrice}({$sign}{$changePct}%) "
                    . "日高{$dayHigh}/日低{$dayLow} 量比{$volRatio}x 外盤{$extRatio}% 走勢:{$trendLabel}";
            } else {
                // 無快照：使用預先批次撈的 Fugle 即時報價
                $fq = $fugleQuotes[$candidate->stock->symbol] ?? null;
                if ($fq && $fq['current_price'] > 0) {
                    $currentPrice = (float) $fq['current_price'];
                    $prevClose    = (float) $fq['prev_close'];
                    $changePct    = $prevClose > 0 ? round(($currentPrice - $prevClose) / $prevClose * 100, 2) : 0;
                    $openPrice    = (float) $fq['open'];
                    $openChg      = $prevClose > 0 ? round(($openPrice - $prevClose) / $prevClose * 100, 2) : 0;
                    $dayHigh      = (float) $fq['high'];
                    $dayLow       = (float) $fq['low'];
                    $extRatio     = ($fq['trade_volume_at_ask'] + $fq['trade_volume_at_bid']) > 0
                        ? round($fq['trade_volume_at_ask'] / ($fq['trade_volume_at_ask'] + $fq['trade_volume_at_bid']) * 100, 1)
                        : 0;
                    $volLots      = round($fq['accumulated_volume'] / 1000);

                    $midPrice   = ($dayHigh + $dayLow) / 2;
                    $trendLabel = match(true) {
                        $changePct > 2.0 && $currentPrice >= $dayHigh * 0.99 => '強勢衝高',
                        $changePct > 0.5 && $currentPrice >= $midPrice       => '高檔整理',
                        $changePct > 0 && $currentPrice < $midPrice          => '盤中拉回',
                        $changePct < -2.0                                     => '明顯弱勢',
                        default                                               => '盤整',
                    };

                    $sign = $changePct >= 0 ? '+' : '';
                    $openSign = $openChg >= 0 ? '+' : '';
                    $intradaySummary = "今日盤中: 開盤{$openSign}{$openChg}% 現價{$currentPrice}({$sign}{$changePct}%) "
                        . "日高{$dayHigh}/日低{$dayLow} 量{$volLots}張 外盤{$extRatio}% 走勢:{$trendLabel}（Fugle即時）";
                } else {
                    $intradaySummary = '今日盤中: 無快照資料';
                }
            }

            // 類股強弱
            $sectorChange = SectorIndex::getChangeForIndustry($today, $industry);
            $sectorStr = $sectorChange !== null
                ? "類股[{$industry}]: 今日" . ($sectorChange >= 0 ? '+' : '') . "{$sectorChange}%"
                : "類股[{$industry}]: 無資料";

            // 衍生 feature：連漲天數
            $allQuotes = DailyQuote::where('stock_id', $candidate->stock_id)
                ->where('date', '<', $tradeDate)
                ->orderByDesc('date')
                ->limit(10)
                ->pluck('change_percent')
                ->map(fn($v) => (float) $v)
                ->toArray();
            $consecutiveUp = 0;
            foreach ($allQuotes as $chg) {
                if ($chg > 0) $consecutiveUp++;
                else break;
            }

            // 爆量判斷
            $avg5Vol = $quotes->take(5)->avg(fn($q) => $q->volume / 1000) ?: 1;
            $todayVol = $latestSnap ? $latestSnap->accumulated_volume / 1000 : 0;
            $volMult  = $avg5Vol > 0 ? round($todayVol / $avg5Vol, 1) : 0;
            $volLabel = $volMult >= 1.5 ? "爆量({$volMult}倍均量)" : "量比{$volMult}x";

            $tags = implode(',', is_array($candidate->reasons) ? $candidate->reasons : []);

            $lines[] = "{$symbol} {$name}（{$industry}）標籤:{$tags}";
            $lines[] = "  K線: {$kline}";
            $lines[] = "  法人: {$instStr}";
            $lines[] = "  {$intradaySummary}";
            $lines[] = "  {$sectorStr}";
            $lines[] = "  衍生: 連漲{$consecutiveUp}天 {$volLabel}";
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Fallback：API 不可用時，全部標記 haiku_selected=true 讓 Opus 自行判斷
     */
    private function fallbackAll(Collection $candidates): Collection
    {
        foreach ($candidates as $candidate) {
            $candidate->update([
                'haiku_selected'  => true,
                'haiku_reasoning' => 'Haiku 不可用，預設通過（Opus 自行判斷）',
                'score'           => 50,
            ]);
        }
        return $candidates->fresh();
    }
}

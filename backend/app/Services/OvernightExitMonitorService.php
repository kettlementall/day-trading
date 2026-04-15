<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\CandidateMonitor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OvernightExitMonitorService
{
    private string $apiKey;
    private string $model;

    public function __construct(private FugleRealtimeClient $fugle)
    {
        $this->apiKey = config('services.anthropic.api_key', '');
        $this->model  = config('services.anthropic.haiku_model', 'claude-haiku-4-5-20251001');
    }

    /**
     * 指定時段的監控執行（9:30 / 10:00 / 10:30 / 11:00）
     *
     * @param  string  $tradeDate  T+1 出場日（YYYY-MM-DD）
     * @param  string  $slot       時段代碼，如 '930'、'1000'
     */
    public function checkTimeSlot(string $tradeDate, string $slot): array
    {
        $summary = ['slot' => $slot, 'checked' => 0, 'target_hit' => 0, 'stop_hit' => 0, 'adjusted' => 0, 'held' => 0, 'exited' => 0];

        // 取得今日隔日沖、AI 選入、監控尚未終止的候選
        $candidates = Candidate::with(['stock', 'monitor'])
            ->where('mode', 'overnight')
            ->where('trade_date', $tradeDate)
            ->where('ai_selected', true)
            ->whereHas('monitor', fn ($q) => $q->whereNotIn('status', CandidateMonitor::TERMINAL_STATUSES))
            ->get();

        if ($candidates->isEmpty()) {
            Log::info("OvernightExitMonitor [{$slot}] {$tradeDate}：無活躍監控標的");
            return $summary;
        }

        // 批次抓 Fugle 快照
        $stocks  = $candidates->map(fn ($c) => $c->stock)->all();
        $quotes  = $this->fugle->fetchQuotes($stocks);

        foreach ($candidates as $candidate) {
            $symbol  = $candidate->stock->symbol;
            $monitor = $candidate->monitor;

            if (!$monitor) {
                continue;
            }

            $quote = $quotes[$symbol] ?? null;
            $summary['checked']++;

            if (!$quote) {
                Log::warning("OvernightExitMonitor [{$slot}] {$symbol}：無 Fugle 快照，跳過");
                continue;
            }

            $currentTarget = (float) $monitor->current_target;
            $currentStop   = (float) $monitor->current_stop;
            $high          = (float) $quote['high'];
            $low           = (float) $quote['low'];

            // ── 已達目標 ──────────────────────────────────────────────
            if ($currentTarget > 0 && $high >= $currentTarget) {
                $this->transition($monitor, CandidateMonitor::STATUS_TARGET_HIT,
                    "{$slot} 盤中最高 {$high} 達到目標 {$currentTarget}");
                $summary['target_hit']++;
                Log::info("OvernightExitMonitor [{$slot}] {$symbol}：目標達成（high={$high}）");
                continue;
            }

            // ── 觸發停損 ──────────────────────────────────────────────
            if ($currentStop > 0 && $low <= $currentStop) {
                $this->transition($monitor, CandidateMonitor::STATUS_STOP_HIT,
                    "{$slot} 盤中最低 {$low} 觸及停損 {$currentStop}");
                $summary['stop_hit']++;
                Log::info("OvernightExitMonitor [{$slot}] {$symbol}：停損觸發（low={$low}）");
                continue;
            }

            // ── AI 滾動判斷 ───────────────────────────────────────────
            $advice = $this->askHaiku($slot, $candidate, $monitor, $quote);

            match ($advice['action']) {
                'exit' => $this->handleExit($monitor, $slot, $advice, $summary),
                'adjust' => $this->handleAdjust($monitor, $slot, $advice, $summary),
                default => $this->handleHold($monitor, $slot, $advice, $summary),
            };

            Log::info("OvernightExitMonitor [{$slot}] {$symbol}：{$advice['action']} — {$advice['reasoning']}");
        }

        return $summary;
    }

    // -------------------------------------------------------------------------

    private function transition(CandidateMonitor $monitor, string $toStatus, string $reason): void
    {
        $monitor->logTransition($monitor->status, $toStatus, $reason);
        $monitor->status = $toStatus;
        $monitor->save();
    }

    private function handleExit(CandidateMonitor $monitor, string $slot, array $advice, array &$summary): void
    {
        $monitor->logAiAdvice('exit', $advice['reasoning']);
        $this->transition($monitor, CandidateMonitor::STATUS_CLOSED, "{$slot} AI 建議提前出場：{$advice['reasoning']}");
        $summary['exited']++;
    }

    private function handleAdjust(CandidateMonitor $monitor, string $slot, array $advice, array &$summary): void
    {
        $updates = [];

        if (!empty($advice['adjusted_target']) && $advice['adjusted_target'] > 0) {
            $updates['current_target'] = $advice['adjusted_target'];
        }
        if (!empty($advice['adjusted_stop']) && $advice['adjusted_stop'] > 0) {
            $updates['current_stop'] = $advice['adjusted_stop'];
        }

        $monitor->logAiAdvice('adjust', $advice['reasoning'], [
            'adjusted_target' => $advice['adjusted_target'] ?? null,
            'adjusted_stop'   => $advice['adjusted_stop']   ?? null,
            'slot'            => $slot,
        ]);

        if (!empty($updates)) {
            foreach ($updates as $k => $v) {
                $monitor->$k = $v;
            }
        }

        $monitor->save();
        $summary['adjusted']++;
    }

    private function handleHold(CandidateMonitor $monitor, string $slot, array $advice, array &$summary): void
    {
        $monitor->logAiAdvice('hold', $advice['reasoning']);
        $monitor->save();
        $summary['held']++;
    }

    // -------------------------------------------------------------------------
    // Haiku AI 判斷
    // -------------------------------------------------------------------------

    private function askHaiku(string $slot, Candidate $candidate, CandidateMonitor $monitor, array $quote): array
    {
        $fallback = ['action' => 'hold', 'adjusted_target' => null, 'adjusted_stop' => null, 'reasoning' => 'AI 不可用，維持現狀'];

        if (!$this->apiKey) {
            return $fallback;
        }

        $symbol        = $candidate->stock->symbol;
        $name          = $candidate->stock->name;
        $origTarget    = (float) $candidate->target_price;
        $origStop      = (float) $candidate->stop_loss;
        $currentTarget = (float) $monitor->current_target;
        $currentStop   = (float) $monitor->current_stop;
        $suggestedBuy  = (float) $candidate->suggested_buy;
        $prevClose     = (float) $quote['prev_close'];

        $open    = $quote['open'];
        $high    = $quote['high'];
        $low     = $quote['low'];
        $current = $quote['current_price'];
        $volume  = round($quote['accumulated_volume'] / 1000); // 張

        $openGapPct = $prevClose > 0 ? round(($open - $prevClose) / $prevClose * 100, 2) : 0;
        $changeFromBuy = $suggestedBuy > 0 ? round(($current - $suggestedBuy) / $suggestedBuy * 100, 2) : 0;

        // 歷史建議
        $prevAdviceSummary = '';
        $log = $monitor->ai_advice_log ?? [];
        if (!empty($log)) {
            $prevAdviceSummary = "\n## 先前調整紀錄\n";
            foreach (array_slice($log, -3) as $entry) {
                $prevAdviceSummary .= "- [{$entry['time']}] {$entry['action']}: {$entry['notes']}\n";
            }
        }

        $slotLabel = match($slot) {
            '930'  => '09:30',
            '1000' => '10:00',
            '1030' => '10:30',
            '1100' => '11:00',
            default => $slot,
        };

        $prompt = <<<PROMPT
你是台股隔日沖出場管理 AI。現在時間 {$slotLabel}，請根據盤中走勢決定是否調整出場策略。

## 持倉資訊
股票：{$symbol} {$name}
昨日收盤（建倉參考）：{$prevClose}
建議買入（T+0）：{$suggestedBuy}
原始目標：{$origTarget}　原始停損：{$origStop}
當前目標：{$currentTarget}　當前停損：{$currentStop}

## {$slotLabel} 快照
開盤：{$open}（跳空 {$openGapPct}%）
最高：{$high}　最低：{$low}　現價：{$current}
累積量：{$volume} 張
距離買入：{$changeFromBuy}%
{$prevAdviceSummary}

## 你的任務
1. 評估現在盤中走勢
2. 決定策略：hold（維持）/ adjust（調整目標或停損）/ exit（建議提前出場）
3. adjust 時必須給出新的 adjusted_target 或 adjusted_stop（或兩者），且需合理：target > current > stop

請直接回覆 JSON（不要加 markdown）：
{"action":"hold","adjusted_target":null,"adjusted_stop":null,"reasoning":"一句話"}
PROMPT;

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model'      => $this->model,
                    'max_tokens' => 256,
                    'messages'   => [['role' => 'user', 'content' => $prompt]],
                ]);

            if (!$response->successful()) {
                Log::warning("OvernightExitMonitor Haiku {$symbol}: HTTP {$response->status()}");
                return $fallback;
            }

            $text = trim($response->json('content.0.text', ''));
            $text = preg_replace('/^```json?\s*/i', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
            $data = json_decode($text, true);

            if (!is_array($data) || !isset($data['action'])) {
                Log::warning("OvernightExitMonitor Haiku {$symbol}: 無法解析回應");
                return $fallback;
            }

            return [
                'action'          => in_array($data['action'], ['hold', 'adjust', 'exit']) ? $data['action'] : 'hold',
                'adjusted_target' => isset($data['adjusted_target']) ? (float) $data['adjusted_target'] ?: null : null,
                'adjusted_stop'   => isset($data['adjusted_stop'])   ? (float) $data['adjusted_stop']   ?: null : null,
                'reasoning'       => $data['reasoning'] ?? '',
            ];
        } catch (\Exception $e) {
            Log::error("OvernightExitMonitor Haiku {$symbol}: " . $e->getMessage());
            return $fallback;
        }
    }
}

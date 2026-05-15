<?php

namespace App\Services;

use App\Models\DailyQuote;
use App\Models\InstitutionalTrade;
use App\Models\InvestmentThesis;
use App\Models\MarginTrade;
use App\Models\SectorIndex;
use App\Models\StockValuation;
use App\Models\SwingPosition;
use App\Models\SwingPositionSnapshot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SwingPositionUpdateService
{
    private string $apiKey;
    private string $model;

    public function __construct(private StockNewsRiskContextService $newsRiskContext)
    {
        $this->apiKey = config('services.anthropic.api_key', '');
        $this->model = config('services.anthropic.overnight_model', 'claude-sonnet-4-6');
    }

    public function update(string $date): array
    {
        $summary = ['checked' => 0, 'hold' => 0, 'adjust' => 0, 'trim' => 0, 'exit' => 0, 'missing_quote' => 0];

        $positions = SwingPosition::with(['stock', 'candidate'])
            ->whereIn('status', SwingPosition::ACTIVE_STATUSES)
            ->whereDate('entry_date', '<=', $date)
            ->get();

        foreach ($positions as $position) {
            $summary['checked']++;
            $quote = DailyQuote::where('stock_id', $position->stock_id)
                ->where('date', '<=', $date)
                ->orderByDesc('date')
                ->first();

            if (!$quote) {
                $summary['missing_quote']++;
                continue;
            }

            $holdingDays = DailyQuote::where('stock_id', $position->stock_id)
                ->where('date', '>=', $position->entry_date)
                ->where('date', '<=', $quote->date)
                ->count();

            $thesisStatus = $this->resolveThesisStatus($position);
            $technicalContext = $this->buildTechnicalContext($position, $date);
            $chipContext = $this->buildChipContext($position, $date);
            $valuationContext = $this->buildValuationContext($position, $date);
            $sectorContext = $this->buildSectorContext($position, $date);
            $newsRiskContext = $this->newsRiskContext->build($position->stock, $date, 5, 6);
            $recentExitSignal = $this->hasRecentExitSignal($position, $quote);
            $advice = $this->buildAdvice($position, $quote, $holdingDays, $thesisStatus, $technicalContext, $chipContext, $valuationContext, $sectorContext, $newsRiskContext, $recentExitSignal);
            $action = $advice['action'] ?? 'hold';

            if ($action === 'exit') {
                $position->status = SwingPosition::STATUS_EXIT_SUGGESTED;
                $summary['exit']++;
            } elseif ($action === 'trim') {
                $position->status = SwingPosition::STATUS_HOLDING;
                $summary['trim']++;
            } elseif ($action === 'adjust') {
                $position->status = SwingPosition::STATUS_HOLDING;
                $summary['adjust']++;
            } else {
                $position->status = SwingPosition::STATUS_HOLDING;
                $summary['hold']++;
            }

            if (isset($advice['current_stop'])) {
                $position->current_stop = $advice['current_stop'];
            }
            if (isset($advice['current_target'])) {
                $position->current_target = $advice['current_target'];
            }
            $position->appendAdvice($advice);
            $position->save();

            SwingPositionSnapshot::updateOrCreate(
                ['swing_position_id' => $position->id, 'date' => $quote->date->toDateString()],
                [
                    'close' => $quote->close,
                    'unrealized_profit_percent' => $this->profitPercent($position, (float) $quote->close),
                    'current_stop' => $position->current_stop,
                    'current_target' => $position->current_target,
                    'holding_days' => $holdingDays,
                    'advice' => $advice,
                    'thesis_status' => $thesisStatus,
                ]
            );
        }

        return $summary;
    }

    private function buildAdvice(
        SwingPosition $position,
        DailyQuote $quote,
        int $holdingDays,
        array $thesisStatus,
        array $technicalContext,
        array $chipContext,
        array $valuationContext = [],
        array $sectorContext = [],
        array $newsRiskContext = [],
        bool $recentExitSignal = false
    ): array {
        $close = (float) $quote->close;
        $entry = (float) $position->entry_price;
        $stop = (float) ($position->current_stop ?: $entry * 0.92);
        $target = (float) ($position->current_target ?: $entry * 1.12);
        $profitPct = $this->profitPercent($position, $close);
        $stopBreached = $close <= $stop;
        $riskZoneTouched = $recentExitSignal && !$stopBreached;

        // 停損跌破、論點 invalidation 仍交給 AI 完整審查；只有 AI 失敗才走 fallback 規則。
        // stop_breached=true 代表進入停損審查模式，不等於必須出場。
        if ($this->apiKey) {
            $ai = $this->askAi($position, $quote, $holdingDays, $thesisStatus, $technicalContext, $chipContext, $valuationContext, $sectorContext, $newsRiskContext, $recentExitSignal, $stopBreached, $riskZoneTouched);
            if ($ai) {
                $ai['stop_breached'] = $stopBreached;
                $ai['risk_zone_touched'] = $riskZoneTouched;
                $ai['news_risk'] = $newsRiskContext;
                return $this->normalizeAdvice($position, $ai, $profitPct);
            }
        }

        // AI 失敗時的保守退路：已跌破停損但無 AI 可重新審查時，優先保護風險。
        if ($stopBreached) {
            return $this->normalizeAdvice($position, [
                'action' => 'exit',
                'reasoning' => "收盤 {$close} 跌破停損 {$stop}，且 AI 停損審查失敗，建議先出場控風險。請人工檢視技術破位、論點狀態與籌碼是否仍支持續抱。",
                'current_stop' => $stop,
                'current_target' => $target,
                'profit_percent' => $profitPct,
                'stop_breached' => true,
                'risk_zone_touched' => false,
                'stop_review_state' => 'invalidated',
                'stop_review_reasoning' => 'AI 停損審查失敗，無法確認原始進場假設仍成立。',
                'repair_condition' => null,
                'failure_condition' => '已跌破停損且 AI 無法完成審查',
                'market_vs_stock_issue' => 'mixed',
                'decision_summary' => '跌破停損且 AI 審查失敗，先以風控出場。',
                'why_not_exit' => null,
                'why_not_hold' => '已跌破停損且無法確認 thesis、技術與籌碼仍支持續抱。',
                'thesis_health' => $this->thesisHealth($thesisStatus),
                'technical_health' => 'broken',
                'chip_health' => $chipContext['health'],
                'risk_pressure' => 'high',
                'time_pressure' => $this->timePressure($holdingDays, $position),
                'news_risk' => $newsRiskContext,
            ], $profitPct);
        }

        // AI 失敗的保守退路：論點 invalidation_signal + 技術 broken 才強制 exit；
        // 否則就算論點轉弱，只要技術仍 healthy，先標 exit_suggested 等使用者人工確認。
        $invalidationSignal = !empty($thesisStatus['invalidation_signal']);
        $techBroken = ($technicalContext['health'] ?? null) === 'broken';
        if ($invalidationSignal && $techBroken) {
            return $this->normalizeAdvice($position, [
                'action' => 'exit',
                'reasoning' => '論點失效且技術跌破月線/季線，建議出場。',
                'current_stop' => $stop,
                'current_target' => $target,
                'profit_percent' => $profitPct,
                'thesis_invalidated' => true,
                'risk_zone_touched' => $riskZoneTouched,
                'thesis_health' => 'invalidated',
                'technical_health' => 'broken',
                'chip_health' => $chipContext['health'],
                'risk_pressure' => 'high',
                'time_pressure' => $this->timePressure($holdingDays, $position),
                'decision_summary' => '論點與技術同步轉壞，建議出場。',
                'why_not_exit' => null,
                'why_not_hold' => '論點失效且技術結構已破壞。',
                'news_risk' => $newsRiskContext,
            ], $profitPct);
        }
        if ($invalidationSignal) {
            // 論點轉弱但技術未破，給 trim 訊號讓使用者注意；不強迫立刻出場
            return $this->normalizeAdvice($position, [
                'action' => 'trim',
                'reasoning' => '論點信心轉弱（' . ($thesisStatus['invalidation_reason'] ?? 'unknown') . '）但技術尚未破壞，建議部分減倉並上移停損保護獲利。',
                'current_stop' => max($stop, round($close * 0.95, 2)),
                'current_target' => $target,
                'profit_percent' => $profitPct,
                'risk_zone_touched' => $riskZoneTouched,
                'thesis_health' => 'invalidated',
                'technical_health' => $technicalContext['health'],
                'chip_health' => $chipContext['health'],
                'risk_pressure' => 'medium',
                'time_pressure' => $this->timePressure($holdingDays, $position),
                'decision_summary' => '論點轉弱但技術未破，先減碼並保留觀察。',
                'why_not_exit' => '技術結構尚未破壞，仍可用較小部位驗證。',
                'why_not_hold' => '論點已出現 invalidation signal，不適合全倉續抱。',
                'news_risk' => $newsRiskContext,
            ], $profitPct);
        }

        if ($close >= $target) {
            return $this->normalizeAdvice($position, [
                'action' => 'trim',
                'reasoning' => "收盤 {$close} 已達目標 {$target}，建議部分停利或上移停損。",
                'current_stop' => max($stop, round($close * 0.96, 2)),
                'current_target' => $target,
                'profit_percent' => $profitPct,
                'risk_zone_touched' => $riskZoneTouched,
                'thesis_health' => $this->thesisHealth($thesisStatus),
                'technical_health' => $technicalContext['health'],
                'chip_health' => $chipContext['health'],
                'risk_pressure' => 'medium',
                'time_pressure' => 'normal',
                'decision_summary' => '已達目標區，建議部分停利或上移停損。',
                'why_not_exit' => '若 thesis 未失效，可留部分部位延伸獲利。',
                'why_not_hold' => '價格已達原目標，應先鎖定部分風險報酬。',
                'news_risk' => $newsRiskContext,
            ], $profitPct);
        }

        if ($profitPct >= 8) {
            return $this->normalizeAdvice($position, [
                'action' => 'adjust',
                'reasoning' => '已有明顯獲利，建議上調移動停損保護利潤。',
                'current_stop' => max($stop, round($close * 0.95, 2)),
                'current_target' => $target,
                'profit_percent' => $profitPct,
                'risk_zone_touched' => $riskZoneTouched,
                'thesis_health' => $this->thesisHealth($thesisStatus),
                'technical_health' => $technicalContext['health'],
                'chip_health' => $chipContext['health'],
                'risk_pressure' => 'medium',
                'time_pressure' => 'normal',
                'decision_summary' => '已有明顯獲利，上移停損保護利潤。',
                'why_not_exit' => '尚未達到 thesis 或技術失效條件。',
                'why_not_hold' => '已有明顯獲利，停損應跟上避免回吐。',
                'news_risk' => $newsRiskContext,
            ], $profitPct);
        }

        return $this->normalizeAdvice($position, [
            'action' => 'hold',
            'reasoning' => '價格仍在停損與目標區間內，產業論點未失效，續抱觀察。',
            'current_stop' => $stop,
            'current_target' => $target,
            'profit_percent' => $profitPct,
            'risk_zone_touched' => $riskZoneTouched,
            'thesis_health' => $this->thesisHealth($thesisStatus),
            'technical_health' => $technicalContext['health'],
            'chip_health' => $chipContext['health'],
            'risk_pressure' => $chipContext['health'] === 'weak' || $technicalContext['health'] === 'weak' ? 'medium' : 'low',
            'time_pressure' => $this->timePressure($holdingDays, $position),
            'decision_summary' => '價格仍在停損與目標區間，續抱觀察。',
            'why_not_exit' => '尚未跌破停損，且產業論點未失效。',
            'why_not_hold' => null,
            'news_risk' => $newsRiskContext,
        ], $profitPct);
    }

    private function askAi(
        SwingPosition $position,
        DailyQuote $quote,
        int $holdingDays,
        array $thesisStatus,
        array $technicalContext,
        array $chipContext,
        array $valuationContext = [],
        array $sectorContext = [],
        array $newsRiskContext = [],
        bool $recentExitSignal = false,
        bool $stopBreached = false,
        bool $riskZoneTouched = false
    ): ?array {
        $candidate = $position->candidate;
        $thesisJson = json_encode($thesisStatus, JSON_UNESCAPED_UNICODE);
        $technicalJson = json_encode($technicalContext, JSON_UNESCAPED_UNICODE);
        $chipJson = json_encode($chipContext, JSON_UNESCAPED_UNICODE);
        $valuationJson = json_encode($valuationContext, JSON_UNESCAPED_UNICODE);
        $sectorJson = json_encode($sectorContext, JSON_UNESCAPED_UNICODE);
        $newsRiskText = $this->newsRiskContext->toPrompt($newsRiskContext);
        $recentExitText = $recentExitSignal ? 'true（近 7 日測過 stop 或論點失效）' : 'false';
        $riskZoneText = $riskZoneTouched ? 'true（未跌破今日停損，但近期碰過停損附近或出現 exit 壓力）' : 'false';
        $lessonsSection = \App\Models\AiLesson::getSwingAdviceLessons();
        $lessonsBlock = $lessonsSection !== '' ? $lessonsSection . "\n\n" : '';
        $previousStopReview = $this->lastStopReviewAdvice($position);
        $previousStopReviewBlock = '';
        if ($previousStopReview) {
            $prevReasoning = mb_substr((string) ($previousStopReview['reasoning'] ?? ''), 0, 180);
            $previousStopReviewBlock = <<<PREVSTOP

# 上次停損審查追蹤
上次 action: {$previousStopReview['action']}
上次修復條件: {$previousStopReview['repair_condition']}
上次失敗條件: {$previousStopReview['failure_condition']}
上次理由摘要: {$prevReasoning}

若上次已因跌破停損給過 hold/trim/adjust，本次必須先判斷修復條件是否發生。若沒有發生，預設應 trim 或 exit；若仍要 hold，必須指出新的重大正面訊號，不能重複同一套理由。

PREVSTOP;
        }

        $stopReviewBlock = '';
        if ($stopBreached) {
            $stopReviewBlock = <<<STOPREVIEW

# ⚠ 停損審查模式（最高優先）
今日收盤 {$quote->close} ≤ 停損 {$position->current_stop}，代表原交易假設被市場挑戰。跌破停損不是自動出場，但你必須重新審查原始 thesis、技術結構、籌碼、類股/市場背景是否仍支持持有。若核心支柱失效，必須 exit。

停損審查時請先回答：
1. 原始 thesis 是否仍成立？若成立，跌破停損可能是市場同步回檔、類股拖累、洗盤、假跌破、波動比預期大或停損設太近；若不成立，應偏 exit。
2. 跌破停損是個股問題，還是市場/類股一起跌？
3. 資金是否撤退？請用量價、法人、融資或同族群表現判斷。
4. 若不出場，下一次檢討要看到什麼才算修復？若沒有發生什麼就該出場？

停損審查允許 action=hold/trim/adjust/exit：
- exit：原始進場假設失效，或技術/籌碼/論點核心支柱明確破壞。
- trim：假設未完全失效，但風險升高，先降部位。
- adjust：可調整目標、縮短 ETA 或上移停損；不可下修停損。
- hold：只允許在 thesis 仍成立且有清楚 repair_condition 時使用。

停損審查必填欄位：
- stop_review_state: thesis_intact / damaged / invalidated
- stop_review_reasoning: 為什麼可觀察或必須出場
- repair_condition: 若不出場，下次檢討要看到什麼才算修復
- failure_condition: 若出現或持續什麼狀況就該出場
- market_vs_stock_issue: market_drag / sector_drag / stock_specific / mixed


STOPREVIEW;
        }
        $riskZoneBlock = '';
        if ($riskZoneTouched) {
            $riskZoneBlock = <<<RISKZONE

# 風險區觀察模式
今日沒有跌破目前停損，不能使用停損審查結論；stop_review_state 必須輸出 null。
近 7 日 exit 訊號只是風險提醒，不是出場命令。請判斷壓力是否已修復：
- 若 thesis 仍有效、價格已收回關鍵位置、籌碼沒有惡化，可以 hold，但要用 why_not_exit 說清楚。
- 若 thesis 仍有效但量能/籌碼/相對強度不足，優先考慮 trim 或 adjust，而不是直接 exit。
- 若 thesis、技術或籌碼已有核心破壞，才 exit。

風險區觀察可填 repair_condition / failure_condition，但不得填 stop_review_state。

RISKZONE;
        }
        $prompt = <<<PROMPT
你是穩健派短線交易顧問，盤後針對單筆持倉給日建議，僅輸出 JSON。

# 持倉
股票：{$position->stock->symbol} {$position->stock->name}
收盤 {$quote->close} | 成本 {$position->entry_price} | 股數 {$position->shares} | 持有 {$holdingDays} 日
stop {$position->current_stop} | target {$position->current_target}
原由：{$candidate?->swing_reasoning}

# Context
論點：{$thesisJson}
技術：{$technicalJson}
籌碼：{$chipJson}
估值：{$valuationJson}
類股：{$sectorJson}
個股新聞風險：
{$newsRiskText}
近 7 日 exit 訊號：{$recentExitText}
風險區觀察：{$riskZoneText}
{$stopReviewBlock}
{$riskZoneBlock}
{$previousStopReviewBlock}
{$lessonsBlock}# 基礎約束
- action ∈ {hold, adjust, trim, exit}
- current_stop 只能上移或維持（action=exit 例外）
- current_target 可調，但 reasoning 必須說明
- expected_holding_days 與 target_eta_days 必須依今日狀態重估，不可沿用 20 天
- target_price_reasoning 必須引用：壓力區 / 均線通道 / ATR / R:R / 題材催化 其一
- eta_reasoning 必須引用：趨勢斜率 / 波動 / 量能 / 籌碼 / 題材催化窗 其一
- thesis_health、technical_health、chip_health、risk_pressure 必須真實反映 context

# 進階仲裁
- related_stock_context 存在時：判斷此股是否仍符合 benefit_level 與 role，若角色弱化要反映在 thesis_health/risk_pressure/reasoning/target/ETA。
- 個股新聞風險若有 short_term_risk=true 或負面新聞，必須判斷是否破壞原始 thesis；說明它是短線價格風險、獲利品質風險，還是 thesis 失效。不可只用技術面忽略法說/財報/訂單利空。
- thesis_status.invalidation_signal=true：不可單獨 exit。只有「論點失效＋技術 weak/broken」或「論點失效＋瀕臨 stop」雙條件成立才 exit；否則 trim/adjust 上移 stop，給時間驗證。invalidation_reason=title_not_found_in_db 多半只是命名飄移，非基本面壞。
- 近 7 日 exit 訊號=true：這是風險提醒，不是出場命令。若 thesis 仍有效、價格已收回關鍵位置、籌碼沒有惡化，可以 hold；若只是尚未確認修復，才 trim/adjust；若核心支柱破壞才 exit。
- stop_breached=false 時，stop_review_state 與 stop_review_reasoning 必須是 null，只能用 risk_zone_touched 表達近期受壓。

# 輸出 schema
{
  "action": "<hold|adjust|trim|exit>",
  "decision_summary": "<一句話結論>",
  "reasoning": "<理由>",
  "why_not_exit": "<非 exit 時說明為什麼不用出場；exit 時 null>",
  "why_not_hold": "<非 hold 時說明為什麼不能全抱；hold 時 null>",
  "current_stop": <num>, "current_target": <num>,
  "target_price_reasoning": "<引用至少一個依據>",
  "profit_percent": <num>,
  "expected_holding_days": "<range, e.g. 8-15>",
  "target_eta_days": <int>,
  "eta_reasoning": "<引用至少一個依據>",
  "time_pressure": "<normal|delayed|expired>",
  "thesis_health": "<healthy|weak|invalidated|unknown>",
  "technical_health": "<healthy|weak|broken>",
  "chip_health": "<healthy|neutral|weak>",
  "risk_pressure": "<low|medium|high>",
  "chip_risk_notes": ["<原因>"],
  "volume_price_signal": "<量價描述>",
  "stop_breached": <bool>,
  "risk_zone_touched": <bool>,
  "stop_review_state": "<thesis_intact|damaged|invalidated|null>",
  "stop_review_reasoning": "<停損審查理由或 null>",
  "repair_condition": "<下次檢討要看到什麼才算修復或 null>",
  "failure_condition": "<什麼狀況持續/出現就該出場或 null>",
  "market_vs_stock_issue": "<market_drag|sector_drag|stock_specific|mixed|null>"
}
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
                    'max_tokens' => 1600,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);

            if (!$response->successful()) {
                Log::error('SwingPositionUpdate API error: ' . $response->body());
                return null;
            }

            $text = trim($response->json('content.0.text', ''));
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            if ($start === false || $end === false || $end <= $start) {
                return null;
            }
            $data = json_decode(substr($text, $start, $end - $start + 1), true);

            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            Log::error('SwingPositionUpdate: ' . $e->getMessage());
            return null;
        }
    }

    private function normalizeAdvice(SwingPosition $position, array $advice, ?float $profitPercent = null): array
    {
        $previousStop = $position->current_stop !== null ? (float) $position->current_stop : null;
        $previousTarget = $position->current_target !== null ? (float) $position->current_target : null;
        $action = in_array($advice['action'] ?? 'hold', ['hold', 'adjust', 'trim', 'exit'], true)
            ? $advice['action']
            : 'hold';

        $nextStop = isset($advice['current_stop']) ? round((float) $advice['current_stop'], 2) : $previousStop;
        if ($action !== 'exit' && $previousStop !== null && $nextStop !== null) {
            $nextStop = max($previousStop, $nextStop);
        }

        $nextTarget = isset($advice['current_target']) ? round((float) $advice['current_target'], 2) : $previousTarget;
        $advice['action'] = $action;
        $advice['current_stop'] = $nextStop;
        $advice['current_target'] = $nextTarget;
        if ($profitPercent !== null) {
            $advice['profit_percent'] = $profitPercent;
        }
        $advice['previous_stop'] = $previousStop;
        $advice['previous_target'] = $previousTarget;
        $advice['stop_changed'] = $previousStop !== null && $nextStop !== null && abs($nextStop - $previousStop) >= 0.01;
        $advice['target_changed'] = $previousTarget !== null && $nextTarget !== null && abs($nextTarget - $previousTarget) >= 0.01;
        $advice['adjustment_reason'] = $advice['adjustment_reason'] ?? $advice['reasoning'] ?? null;
        $advice['expected_holding_days'] = $advice['expected_holding_days'] ?? $this->expectedHoldingDays($position);
        $advice['target_eta_days'] = isset($advice['target_eta_days']) ? (int) $advice['target_eta_days'] : null;
        $entryPlan = $position->candidate?->swing_entry_plan ?? [];
        $advice['target_price_reasoning'] = $advice['target_price_reasoning'] ?? $entryPlan['target_price_reasoning'] ?? null;
        $advice['eta_reasoning'] = $advice['eta_reasoning'] ?? $entryPlan['eta_reasoning'] ?? null;
        $advice['time_pressure'] = $advice['time_pressure'] ?? 'normal';
        $advice['thesis_health'] = $advice['thesis_health'] ?? 'unknown';
        $advice['technical_health'] = $advice['technical_health'] ?? 'healthy';
        $advice['chip_health'] = $advice['chip_health'] ?? 'neutral';
        $advice['risk_pressure'] = $advice['risk_pressure'] ?? 'low';
        $advice['chip_risk_notes'] = $advice['chip_risk_notes'] ?? [];
        $advice['volume_price_signal'] = $advice['volume_price_signal'] ?? null;
        $advice['stop_breached'] = (bool) ($advice['stop_breached'] ?? false);
        $advice['risk_zone_touched'] = (bool) ($advice['risk_zone_touched'] ?? false);
        $advice['stop_review_state'] = $advice['stop_breached'] && in_array($advice['stop_review_state'] ?? null, ['thesis_intact', 'damaged', 'invalidated'], true)
            ? $advice['stop_review_state']
            : null;
        $advice['stop_review_reasoning'] = $advice['stop_breached'] ? ($advice['stop_review_reasoning'] ?? null) : null;
        $advice['repair_condition'] = $advice['repair_condition'] ?? null;
        $advice['failure_condition'] = $advice['failure_condition'] ?? null;
        $advice['market_vs_stock_issue'] = in_array($advice['market_vs_stock_issue'] ?? null, ['market_drag', 'sector_drag', 'stock_specific', 'mixed'], true)
            ? $advice['market_vs_stock_issue']
            : null;
        $advice['decision_summary'] = $advice['decision_summary'] ?? $advice['adjustment_reason'] ?? $advice['reasoning'] ?? null;
        $advice['why_not_exit'] = $action === 'exit' ? null : ($advice['why_not_exit'] ?? null);
        $advice['why_not_hold'] = $action === 'hold' ? null : ($advice['why_not_hold'] ?? null);
        $advice['previous_stop_review'] = $this->lastStopReviewAdvice($position);

        return $advice;
    }

    private function lastStopReviewAdvice(SwingPosition $position): ?array
    {
        $log = $position->advice_log ?? [];
        if (!is_array($log)) {
            return null;
        }

        for ($i = count($log) - 1; $i >= 0; $i--) {
            $entry = $log[$i];
            if (!is_array($entry) || empty($entry['stop_breached']) || empty($entry['stop_review_state'])) {
                continue;
            }

            return [
                'time' => $entry['time'] ?? null,
                'action' => $entry['action'] ?? null,
                'stop_review_state' => $entry['stop_review_state'] ?? null,
                'repair_condition' => $entry['repair_condition'] ?? null,
                'failure_condition' => $entry['failure_condition'] ?? null,
                'reasoning' => $entry['reasoning'] ?? null,
            ];
        }

        return null;
    }

    private function buildValuationContext(SwingPosition $position, string $date): array
    {
        $v = StockValuation::where('stock_id', $position->stock_id)
            ->where('date', '<=', $date)
            ->orderByDesc('date')
            ->first();

        if (!$v) {
            return ['available' => false];
        }

        $pe = $v->pe_ratio !== null ? (float) $v->pe_ratio : null;
        $yield = $v->dividend_yield !== null ? (float) $v->dividend_yield : null;

        // 粗略的估值健康度（無歷史標準，僅供 AI 參考）
        $level = 'normal';
        if ($pe !== null) {
            if ($pe >= 40) {
                $level = 'expensive';
            } elseif ($pe > 0 && $pe < 12) {
                $level = 'cheap';
            }
        }

        return [
            'available' => true,
            'pe_ratio' => $pe,
            'pb_ratio' => $v->pb_ratio !== null ? (float) $v->pb_ratio : null,
            'dividend_yield' => $yield,
            'eps_ttm' => $v->eps_ttm !== null ? (float) $v->eps_ttm : null,
            'as_of' => $v->date?->format('Y-m-d'),
            'level' => $level,
        ];
    }

    private function buildSectorContext(SwingPosition $position, string $date): array
    {
        // ETF 沒有對應的單一類股，且 DB 內舊 ETF 的 industry 欄位被誤填成塑膠/紡織等
        // 避免對錯誤的類股強弱餵給 AI 造成誤導
        if ($this->isLikelyEtf($position->stock)) {
            return ['available' => false, 'reason' => 'etf_no_single_sector'];
        }

        $industry = $position->stock->industry;
        if (!$industry) {
            return ['available' => false];
        }

        $change = SectorIndex::getChangeForIndustry($date, $industry);
        $rank = SectorIndex::getRankForIndustry($date, $industry);

        if ($change === null) {
            return ['available' => false, 'industry' => $industry];
        }

        $strength = 'neutral';
        if ($change >= 1.5) {
            $strength = 'strong';
        } elseif ($change <= -1.5) {
            $strength = 'weak';
        }

        return [
            'available' => true,
            'industry' => $industry,
            'change_percent' => $change,
            'rank' => $rank,
            'strength' => $strength,
        ];
    }

    /**
     * 偵測此持倉近 7 個自然日內（不含今日）是否曾觸發 exit 訊號。
     * 用於提醒 AI「已被測試過的停損」這個重要脈絡，避免 V 反後簡單給 hold 而忽略再測風險。
     */
    private function hasRecentExitSignal(SwingPosition $position, DailyQuote $quote): bool
    {
        $todayDate = $quote->date->toDateString();
        $sevenDaysAgo = $quote->date->copy()->subDays(7)->toDateString();

        return SwingPositionSnapshot::where('swing_position_id', $position->id)
            ->where('date', '>=', $sevenDaysAgo)
            ->where('date', '<', $todayDate)
            ->get()
            ->contains(fn ($s) => is_array($s->advice ?? null) && ($s->advice['action'] ?? null) === 'exit');
    }

    private function describeRecentExit(bool $recentExitSignal): string
    {
        return $recentExitSignal
            ? 'true（過去 7 日內曾觸發 exit，請偏向 trim 保護而非簡單 hold）'
            : 'false';
    }

    private function isLikelyEtf(\App\Models\Stock $stock): bool
    {
        if (str_starts_with($stock->symbol ?? '', '00')) {
            return true;
        }
        $name = $stock->name ?? '';
        return mb_stripos($name, 'ETF') !== false
            || mb_stripos($name, '指數') !== false
            || mb_stripos($name, '基金') !== false;
    }

    private function buildTechnicalContext(SwingPosition $position, string $date): array
    {
        $quotes = DailyQuote::where('stock_id', $position->stock_id)
            ->where('date', '<=', $date)
            ->orderByDesc('date')
            ->limit(80)
            ->get();

        $closes = $quotes->pluck('close')->map(fn ($v) => (float) $v)->toArray();
        $highs = $quotes->pluck('high')->map(fn ($v) => (float) $v)->toArray();
        $lows = $quotes->pluck('low')->map(fn ($v) => (float) $v)->toArray();
        $volumes = $quotes->pluck('volume')->map(fn ($v) => (int) $v)->toArray();
        $close = $closes[0] ?? null;
        $ma10 = TechnicalIndicator::sma($closes, 10);
        $ma20 = TechnicalIndicator::sma($closes, 20);
        $ma60 = TechnicalIndicator::sma($closes, 60);
        $atr = TechnicalIndicator::atr($highs, $lows, $closes);
        $avgVolume20 = count($volumes) >= 20 ? array_sum(array_slice($volumes, 0, 20)) / 20 : null;
        $volumeRatio = $avgVolume20 ? round(($volumes[0] ?? 0) / max(1, $avgVolume20), 2) : null;
        $health = 'healthy';
        if ($close && $ma20 && $close < $ma20) {
            $health = 'weak';
        }
        if ($close && $ma60 && $close < $ma60) {
            $health = 'broken';
        }

        return [
            'close' => $close,
            'ma10' => $ma10,
            'ma20' => $ma20,
            'ma60' => $ma60,
            'atr' => $atr,
            'volume_ratio_20d' => $volumeRatio,
            'health' => $health,
        ];
    }

    private function buildChipContext(SwingPosition $position, string $date): array
    {
        $inst = InstitutionalTrade::where('stock_id', $position->stock_id)
            ->where('date', '<=', $date)
            ->orderByDesc('date')
            ->limit(5)
            ->get();
        $margin = MarginTrade::where('stock_id', $position->stock_id)
            ->where('date', '<=', $date)
            ->orderByDesc('date')
            ->limit(5)
            ->get();

        $inst1 = (int) ($inst->first()?->total_net ?? 0);
        $inst3 = (int) $inst->take(3)->sum('total_net');
        $inst5 = (int) $inst->sum('total_net');
        $margin1 = (int) ($margin->first()?->margin_change ?? 0);
        $margin3 = (int) $margin->take(3)->sum('margin_change');
        $margin5 = (int) $margin->sum('margin_change');
        $notes = [];
        if ($inst3 < 0) {
            $notes[] = '法人近 3 日賣超';
        }
        if ($margin3 > 0 && $inst3 < 0) {
            $notes[] = '融資增加但法人轉賣';
        }

        $health = 'neutral';
        if ($inst3 > 0 && $inst5 > 0) {
            $health = 'healthy';
        }
        if ($inst3 < 0 || ($margin3 > 0 && $inst3 <= 0)) {
            $health = 'weak';
        }

        return [
            'institutional_1d' => $inst1,
            'institutional_3d' => $inst3,
            'institutional_5d' => $inst5,
            'foreign_5d' => (int) $inst->sum('foreign_net'),
            'trust_5d' => (int) $inst->sum('trust_net'),
            'dealer_5d' => (int) $inst->sum('dealer_net'),
            'margin_change_1d' => $margin1,
            'margin_change_3d' => $margin3,
            'margin_change_5d' => $margin5,
            'health' => $health,
            'risk_notes' => $notes,
        ];
    }

    private function thesisHealth(array $thesisStatus): string
    {
        if (($thesisStatus['status'] ?? null) === InvestmentThesis::STATUS_INACTIVE || ($thesisStatus['confidence_score'] ?? 100) < 35) {
            return 'invalidated';
        }
        if (($thesisStatus['confidence_score'] ?? 100) < 55) {
            return 'weak';
        }
        return ($thesisStatus['status'] ?? null) === InvestmentThesis::STATUS_ACTIVE ? 'healthy' : 'unknown';
    }

    private function timePressure(int $holdingDays, SwingPosition $position): string
    {
        $max = max(1, (int) ($position->max_holding_days ?: 20));
        if ($holdingDays >= $max) {
            return 'expired';
        }
        return $holdingDays >= (int) floor($max * 0.75) ? 'delayed' : 'normal';
    }

    private function expectedHoldingDays(SwingPosition $position): string
    {
        $plan = $position->candidate?->swing_entry_plan ?? [];
        if (!empty($plan['expected_holding_days'])) {
            return (string) $plan['expected_holding_days'];
        }
        $max = max(5, (int) ($position->max_holding_days ?: 20));
        return '5-' . $max;
    }

    private function resolveThesisStatus(SwingPosition $position): array
    {
        $title = $position->candidate?->swing_thesis['title'] ?? null;
        if (!$title) {
            return ['status' => 'unknown', 'confidence_score' => null, 'invalidation_signal' => false];
        }

        $thesis = InvestmentThesis::where('title', $title)->first();
        if (!$thesis) {
            // title 不見 = 命名飄移或被人工刪除；標 signal 但不直接 exit，讓 AI 仲裁
            return [
                'title' => $title,
                'status' => 'missing',
                'confidence_score' => null,
                'invalidation_signal' => true,
                'invalidation_reason' => 'title_not_found_in_db',
            ];
        }

        $confidence = (int) $thesis->confidence_score;
        $invalid = $thesis->status === InvestmentThesis::STATUS_INACTIVE || $confidence < 35;
        $reason = null;
        if ($invalid) {
            $reason = $thesis->status === InvestmentThesis::STATUS_INACTIVE
                ? 'status_inactive'
                : 'confidence_below_35';
        }

        return [
            'title' => $thesis->title,
            'status' => $thesis->status,
            'confidence_score' => $confidence,
            'research_date' => $thesis->research_date?->format('Y-m-d'),
            'last_evaluated_at' => $thesis->last_evaluated_at?->format('Y-m-d H:i'),
            'risk_factors' => $thesis->risk_factors ?? [],
            'related_stock_context' => $this->resolveRelatedStockContext($thesis, $position),
            'invalidation_signal' => $invalid,
            'invalidation_reason' => $reason,
        ];
    }

    private function resolveRelatedStockContext(InvestmentThesis $thesis, SwingPosition $position): ?array
    {
        foreach (($thesis->related_stocks ?? []) as $related) {
            if (!is_array($related)) {
                continue;
            }
            if ((string) ($related['symbol'] ?? '') !== (string) $position->stock->symbol) {
                continue;
            }

            return [
                'benefit_level' => $related['benefit_level'] ?? 'watch',
                'role' => $related['role'] ?? null,
                'reasoning' => $related['reasoning'] ?? null,
                'confidence' => $related['confidence'] ?? null,
                'risks' => $related['risks'] ?? [],
            ];
        }

        return null;
    }

    private function profitPercent(SwingPosition $position, float $price): float
    {
        $entry = (float) $position->entry_price;
        return $entry > 0 ? round(($price - $entry) / $entry * 100, 2) : 0.0;
    }
}

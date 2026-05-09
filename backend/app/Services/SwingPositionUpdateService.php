<?php

namespace App\Services;

use App\Models\DailyQuote;
use App\Models\InstitutionalTrade;
use App\Models\InvestmentThesis;
use App\Models\MarginTrade;
use App\Models\SwingPosition;
use App\Models\SwingPositionSnapshot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SwingPositionUpdateService
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key', '');
        $this->model = config('services.anthropic.overnight_model', 'claude-sonnet-4-6');
    }

    public function update(string $date): array
    {
        $summary = ['checked' => 0, 'hold' => 0, 'adjust' => 0, 'trim' => 0, 'exit' => 0, 'missing_quote' => 0];

        $positions = SwingPosition::with(['stock', 'candidate'])
            ->whereIn('status', SwingPosition::ACTIVE_STATUSES)
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
            $advice = $this->buildAdvice($position, $quote, $holdingDays, $thesisStatus, $technicalContext, $chipContext);
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
        array $chipContext
    ): array {
        $close = (float) $quote->close;
        $entry = (float) $position->entry_price;
        $stop = (float) ($position->current_stop ?: $entry * 0.92);
        $target = (float) ($position->current_target ?: $entry * 1.12);
        $profitPct = $this->profitPercent($position, $close);

        if ($close <= $stop) {
            return $this->normalizeAdvice($position, [
                'action' => 'exit',
                'reasoning' => "收盤 {$close} 跌破停損 {$stop}，建議出場。",
                'current_stop' => $stop,
                'current_target' => $target,
                'profit_percent' => $profitPct,
                'thesis_health' => $this->thesisHealth($thesisStatus),
                'technical_health' => 'broken',
                'chip_health' => $chipContext['health'],
                'risk_pressure' => 'high',
                'time_pressure' => $this->timePressure($holdingDays, $position),
            ]);
        }

        if ($thesisStatus['status'] === InvestmentThesis::STATUS_INACTIVE || ($thesisStatus['confidence_score'] ?? 100) < 35) {
            return $this->normalizeAdvice($position, [
                'action' => 'exit',
                'reasoning' => '核心產業論點信心轉弱或失效，建議檢討出場。',
                'current_stop' => $stop,
                'current_target' => $target,
                'profit_percent' => $profitPct,
                'thesis_invalidated' => true,
                'thesis_health' => 'invalidated',
                'technical_health' => $technicalContext['health'],
                'chip_health' => $chipContext['health'],
                'risk_pressure' => 'high',
                'time_pressure' => $this->timePressure($holdingDays, $position),
            ]);
        }

        if ($this->apiKey) {
            $ai = $this->askAi($position, $quote, $holdingDays, $thesisStatus, $technicalContext, $chipContext);
            if ($ai) {
                return $this->normalizeAdvice($position, $ai);
            }
        }

        if ($close >= $target) {
            return $this->normalizeAdvice($position, [
                'action' => 'trim',
                'reasoning' => "收盤 {$close} 已達目標 {$target}，建議部分停利或上移停損。",
                'current_stop' => max($stop, round($close * 0.96, 2)),
                'current_target' => $target,
                'profit_percent' => $profitPct,
                'thesis_health' => $this->thesisHealth($thesisStatus),
                'technical_health' => $technicalContext['health'],
                'chip_health' => $chipContext['health'],
                'risk_pressure' => 'medium',
                'time_pressure' => 'normal',
            ]);
        }

        if ($profitPct >= 8) {
            return $this->normalizeAdvice($position, [
                'action' => 'adjust',
                'reasoning' => '已有明顯獲利，建議上調移動停損保護利潤。',
                'current_stop' => max($stop, round($close * 0.95, 2)),
                'current_target' => $target,
                'profit_percent' => $profitPct,
                'thesis_health' => $this->thesisHealth($thesisStatus),
                'technical_health' => $technicalContext['health'],
                'chip_health' => $chipContext['health'],
                'risk_pressure' => 'medium',
                'time_pressure' => 'normal',
            ]);
        }

        return $this->normalizeAdvice($position, [
            'action' => 'hold',
            'reasoning' => '價格仍在停損與目標區間內，產業論點未失效，續抱觀察。',
            'current_stop' => $stop,
            'current_target' => $target,
            'profit_percent' => $profitPct,
            'thesis_health' => $this->thesisHealth($thesisStatus),
            'technical_health' => $technicalContext['health'],
            'chip_health' => $chipContext['health'],
            'risk_pressure' => $chipContext['health'] === 'weak' || $technicalContext['health'] === 'weak' ? 'medium' : 'low',
            'time_pressure' => $this->timePressure($holdingDays, $position),
        ]);
    }

    private function askAi(
        SwingPosition $position,
        DailyQuote $quote,
        int $holdingDays,
        array $thesisStatus,
        array $technicalContext,
        array $chipContext
    ): ?array {
        $candidate = $position->candidate;
        $thesisJson = json_encode($thesisStatus, JSON_UNESCAPED_UNICODE);
        $technicalJson = json_encode($technicalContext, JSON_UNESCAPED_UNICODE);
        $chipJson = json_encode($chipContext, JSON_UNESCAPED_UNICODE);
        $prompt = <<<PROMPT
你是穩健型理財專員，請根據短線持倉狀態給每日盤後建議，只輸出 JSON。

股票：{$position->stock->symbol} {$position->stock->name}
收盤：{$quote->close}
成本：{$position->entry_price}
股數：{$position->shares}
持有交易日：{$holdingDays}
目前停損：{$position->current_stop}
目前目標：{$position->current_target}
原始短線理由：{$candidate?->swing_reasoning}
論點狀態：{$thesisJson}
技術/量價狀態：{$technicalJson}
籌碼狀態：{$chipJson}

規則：
- action 只能是 hold / adjust / trim / exit。
- current_stop 不可低於目前停損，除非 action=exit；停損只能上移或維持。
- current_target 可以上修或下修，但 reasoning 必須說明原因。
- expected_holding_days 與 target_eta_days 必須依今日狀態重新估，不要沿用固定 20 天。
- thesis_health / technical_health / chip_health / risk_pressure 必須明確反映論點、技術、籌碼與風險。

格式：
{"action":"hold/adjust/trim/exit","reasoning":"原因","current_stop":數字,"current_target":數字,"profit_percent":數字,"expected_holding_days":"8-15","target_eta_days":8,"time_pressure":"normal/delayed/expired","thesis_health":"healthy/weak/invalidated/unknown","technical_health":"healthy/weak/broken","chip_health":"healthy/neutral/weak","risk_pressure":"low/medium/high","chip_risk_notes":["原因"],"volume_price_signal":"量價描述"}
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

    private function normalizeAdvice(SwingPosition $position, array $advice): array
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
        $advice['previous_stop'] = $previousStop;
        $advice['previous_target'] = $previousTarget;
        $advice['stop_changed'] = $previousStop !== null && $nextStop !== null && abs($nextStop - $previousStop) >= 0.01;
        $advice['target_changed'] = $previousTarget !== null && $nextTarget !== null && abs($nextTarget - $previousTarget) >= 0.01;
        $advice['adjustment_reason'] = $advice['adjustment_reason'] ?? $advice['reasoning'] ?? null;
        $advice['expected_holding_days'] = $advice['expected_holding_days'] ?? $this->expectedHoldingDays($position);
        $advice['target_eta_days'] = isset($advice['target_eta_days']) ? (int) $advice['target_eta_days'] : null;
        $advice['time_pressure'] = $advice['time_pressure'] ?? 'normal';
        $advice['thesis_health'] = $advice['thesis_health'] ?? 'unknown';
        $advice['technical_health'] = $advice['technical_health'] ?? 'healthy';
        $advice['chip_health'] = $advice['chip_health'] ?? 'neutral';
        $advice['risk_pressure'] = $advice['risk_pressure'] ?? 'low';
        $advice['chip_risk_notes'] = $advice['chip_risk_notes'] ?? [];
        $advice['volume_price_signal'] = $advice['volume_price_signal'] ?? null;

        return $advice;
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
            return ['status' => 'unknown', 'confidence_score' => null];
        }

        $thesis = InvestmentThesis::where('title', $title)->first();
        if (!$thesis) {
            return ['title' => $title, 'status' => 'missing', 'confidence_score' => null];
        }

        return [
            'title' => $thesis->title,
            'status' => $thesis->status,
            'confidence_score' => $thesis->confidence_score,
            'research_date' => $thesis->research_date?->format('Y-m-d'),
            'risk_factors' => $thesis->risk_factors ?? [],
        ];
    }

    private function profitPercent(SwingPosition $position, float $price): float
    {
        $entry = (float) $position->entry_price;
        return $entry > 0 ? round(($price - $entry) / $entry * 100, 2) : 0.0;
    }
}

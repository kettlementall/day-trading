<?php

namespace App\Services;

use App\Models\DailyQuote;
use App\Models\InvestmentThesis;
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
        $summary = ['checked' => 0, 'hold' => 0, 'adjust' => 0, 'exit' => 0, 'missing_quote' => 0];

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
            $advice = $this->buildAdvice($position, $quote, $holdingDays, $thesisStatus);
            $action = $advice['action'] ?? 'hold';

            if ($action === 'exit') {
                $position->status = SwingPosition::STATUS_EXIT_SUGGESTED;
                $summary['exit']++;
            } elseif ($action === 'adjust') {
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

    private function buildAdvice(SwingPosition $position, DailyQuote $quote, int $holdingDays, array $thesisStatus): array
    {
        $close = (float) $quote->close;
        $entry = (float) $position->entry_price;
        $stop = (float) ($position->current_stop ?: $entry * 0.92);
        $target = (float) ($position->current_target ?: $entry * 1.12);
        $profitPct = $this->profitPercent($position, $close);

        if ($close <= $stop) {
            return [
                'action' => 'exit',
                'reasoning' => "收盤 {$close} 跌破停損 {$stop}，建議出場。",
                'current_stop' => $stop,
                'current_target' => $target,
                'profit_percent' => $profitPct,
            ];
        }

        if ($thesisStatus['status'] === InvestmentThesis::STATUS_INACTIVE || ($thesisStatus['confidence_score'] ?? 100) < 35) {
            return [
                'action' => 'exit',
                'reasoning' => '核心產業論點信心轉弱或失效，建議檢討出場。',
                'current_stop' => $stop,
                'current_target' => $target,
                'profit_percent' => $profitPct,
                'thesis_invalidated' => true,
            ];
        }

        if ($holdingDays >= (int) $position->max_holding_days) {
            return [
                'action' => 'exit',
                'reasoning' => "持有 {$holdingDays} 個交易日已達短線上限，建議強制檢討出場。",
                'current_stop' => max($stop, round($close * 0.97, 2)),
                'current_target' => $target,
                'profit_percent' => $profitPct,
            ];
        }

        if ($this->apiKey) {
            $ai = $this->askAi($position, $quote, $holdingDays, $thesisStatus);
            if ($ai) {
                return $ai;
            }
        }

        if ($close >= $target) {
            return [
                'action' => 'exit',
                'reasoning' => "收盤 {$close} 已達目標 {$target}，建議停利。",
                'current_stop' => max($stop, round($close * 0.96, 2)),
                'current_target' => $target,
                'profit_percent' => $profitPct,
            ];
        }

        if ($profitPct >= 8) {
            return [
                'action' => 'adjust',
                'reasoning' => '已有明顯獲利，建議上調移動停損保護利潤。',
                'current_stop' => max($stop, round($close * 0.95, 2)),
                'current_target' => $target,
                'profit_percent' => $profitPct,
            ];
        }

        return [
            'action' => 'hold',
            'reasoning' => '價格仍在停損與目標區間內，產業論點未失效，續抱觀察。',
            'current_stop' => $stop,
            'current_target' => $target,
            'profit_percent' => $profitPct,
        ];
    }

    private function askAi(SwingPosition $position, DailyQuote $quote, int $holdingDays, array $thesisStatus): ?array
    {
        $candidate = $position->candidate;
        $thesisJson = json_encode($thesisStatus, JSON_UNESCAPED_UNICODE);
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

格式：
{"action":"hold/adjust/exit","reasoning":"原因","current_stop":數字,"current_target":數字,"profit_percent":數字}
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
                    'max_tokens' => 900,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);

            if (!$response->successful()) {
                Log::error('SwingPositionUpdate API error: ' . $response->body());
                return null;
            }

            $text = trim($response->json('content.0.text', ''));
            $text = preg_replace('/^```json?\s*/i', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
            $data = json_decode($text, true);

            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            Log::error('SwingPositionUpdate: ' . $e->getMessage());
            return null;
        }
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
        ];
    }

    private function profitPercent(SwingPosition $position, float $price): float
    {
        $entry = (float) $position->entry_price;
        return $entry > 0 ? round(($price - $entry) / $entry * 100, 2) : 0.0;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Candidate;
use App\Models\MarketHoliday;
use App\Services\AiScreenerService;
use App\Services\StockScreener;
use App\Services\TelegramService;
use Illuminate\Console\Command;

class AiScreenCandidates extends Command
{
    protected $signature = 'stock:ai-screen {date?}';
    protected $description = '規則式寬篩 + AI 審核選股，產出最終候選名單';

    public function handle(StockScreener $screener, AiScreenerService $aiScreener, TelegramService $telegram): int
    {
        $date = $this->argument('date') ?? now()->format('Y-m-d');

        if (MarketHoliday::isHoliday($date)) {
            $this->info("今日（{$date}）休市，跳過選股");
            return self::SUCCESS;
        }

        // Step 1: 規則式寬篩（門檻 35，池子 20，負面因子已加強鑑別力）
        $this->info("Step 1: 規則式寬篩（min_score=60, max=40），交易日: {$date}");
        $screener->screen($date, minScoreOverride: 35, maxCandidatesOverride: 20);

        // 取出剛寫入的候選
        $candidates = Candidate::with('stock')
            ->where('trade_date', $date)
            ->orderByDesc('score')
            ->get();

        $this->info("寬篩完成，共 {$candidates->count()} 檔候選");

        if ($candidates->isEmpty()) {
            $this->warn('無候選標的，跳過 AI 審核');
            return self::SUCCESS;
        }

        // Step 2: AI 審核選股
        $this->info("Step 2: AI 審核選股...");
        $aiScreener->screen($date, $candidates);

        // 重新載入結果
        $candidates = Candidate::with('stock')
            ->where('trade_date', $date)
            ->orderByDesc('ai_selected')
            ->orderByDesc('score')
            ->get();

        $selected = $candidates->where('ai_selected', true);
        $rejected = $candidates->where('ai_selected', false);

        $this->info("AI 選股完成：選入 {$selected->count()} 檔 / 排除 {$rejected->count()} 檔");
        $this->newLine();

        // 輸出選入標的
        foreach ($selected as $c) {
            $adj = $c->ai_score_adjustment > 0 ? "+{$c->ai_score_adjustment}" : $c->ai_score_adjustment;
            $this->line(sprintf(
                "  ✓ %s %s | 分數 %d(%s) | %s | 支撐 %s / 壓力 %s | %s",
                $c->stock->symbol,
                $c->stock->name,
                $c->score,
                $adj,
                $c->intraday_strategy ?? '-',
                $c->reference_support ?? '-',
                $c->reference_resistance ?? '-',
                $c->ai_reasoning ?? ''
            ));
        }

        // Telegram 通知
        $lines = ["📊 *AI 選股完成* ({$date})"];
        $lines[] = "寬篩 {$candidates->count()} 檔 → AI 選入 {$selected->count()} 檔";
        $lines[] = '';

        foreach ($selected as $c) {
            $lines[] = sprintf(
                "• %s %s | %s | S %.1f / R %.1f",
                $c->stock->symbol,
                $c->stock->name,
                $c->intraday_strategy ?? '-',
                $c->reference_support ?? 0,
                $c->reference_resistance ?? 0
            );
        }

        $telegram->send(implode("\n", $lines));

        return self::SUCCESS;
    }
}

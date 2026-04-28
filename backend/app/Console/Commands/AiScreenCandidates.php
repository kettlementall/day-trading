<?php

namespace App\Console\Commands;

use App\Models\Candidate;
use App\Models\MarketHoliday;
use App\Services\AiScreenerService;
use App\Services\HaikuPreFilterService;
use App\Services\MarketContextService;
use App\Services\StockScreener;
use App\Services\TelegramService;
use Illuminate\Console\Command;

class AiScreenCandidates extends Command
{
    protected $signature = 'stock:ai-screen {date?}';
    protected $description = '物理門檻寬篩 → Haiku 批量預篩 → Opus 精審，產出最終候選名單';

    public function handle(
        StockScreener $screener,
        HaikuPreFilterService $haiku,
        AiScreenerService $aiScreener,
        TelegramService $telegram
    ): int {
        $date = $this->argument('date') ?? now()->format('Y-m-d');

        if (MarketHoliday::isHoliday($date)) {
            $this->info("今日（{$date}）休市，跳過選股");
            return self::SUCCESS;
        }

        // 市場情境偵測
        $marketContext = MarketContextService::detect($date);
        $contextLabel = match ($marketContext['label']) {
            MarketContextService::CONTEXT_BULLISH_CATALYST => '🔥 利多催化日',
            MarketContextService::CONTEXT_BEARISH_PANIC => '⚠️ 利空恐慌日',
            default => '常態',
        };
        $this->info("市場情境: {$contextLabel}" . ($marketContext['triggers'] ? ' (' . implode(', ', $marketContext['triggers']) . ')' : ''));

        // Step 1: 物理門檻篩選（寬篩，依 5 日均量排序，取前 80）
        $this->info("Step 1: 物理門檻篩選，交易日: {$date}");
        $screener->screen($date);

        $allCandidates = Candidate::with('stock')
            ->where('trade_date', $date)
            ->where('mode', 'intraday')
            ->get();

        $gapReversalCount = $allCandidates->filter(fn($c) => in_array('超跌反彈候選', $c->reasons ?? []))->count();
        $this->info("物理篩選完成，共 {$allCandidates->count()} 檔候選" . ($gapReversalCount > 0 ? "（含 {$gapReversalCount} 檔超跌反彈候選）" : ''));

        if ($allCandidates->isEmpty()) {
            $this->warn('無候選標的，跳過後續篩選');
            return self::SUCCESS;
        }

        // Step 2: Haiku 批量預篩（最多放行 30 檔給 Opus）
        $this->info("Step 2: Haiku 批量預篩（每批 15 檔，上限 30 檔送 Opus）...");
        $haiku->filter($date, $allCandidates, maxPassThrough: 30, marketContext: $marketContext);

        $haikuApproved = Candidate::with('stock')
            ->where('trade_date', $date)
            ->where('mode', 'intraday')
            ->where('haiku_selected', true)
            ->orderByDesc('score')
            ->get();

        $haikuRejected = Candidate::with('stock')
            ->where('trade_date', $date)
            ->where('mode', 'intraday')
            ->where('haiku_selected', false)
            ->orderBy('score')
            ->get();

        $this->info("Haiku 預篩完成：通過 {$haikuApproved->count()} 檔 / 排除 {$haikuRejected->count()} 檔");
        $this->newLine();

        // 輸出 Haiku 排除的標的（供參考）
        foreach ($haikuRejected as $c) {
            $this->line(sprintf(
                "  ✗ Haiku 排除 %s %s（信度%d）：%s",
                $c->stock->symbol,
                $c->stock->name,
                $c->score,
                $c->haiku_reasoning ?? ''
            ));
        }

        if ($haikuApproved->isEmpty()) {
            $this->warn('Haiku 無通過標的，跳過 Opus 精審');
            return self::SUCCESS;
        }

        $this->newLine();

        // Step 3: Opus 精審（只看 haiku_selected=true）
        $this->info("Step 3: Opus 精審（{$haikuApproved->count()} 檔）...");
        $aiScreener->screen($date, $haikuApproved, marketContext: $marketContext);

        // 重新載入結果
        $finalCandidates = Candidate::with('stock')
            ->where('trade_date', $date)
            ->where('mode', 'intraday')
            ->where('haiku_selected', true)
            ->orderByDesc('ai_selected')
            ->orderByDesc('score')
            ->get();

        $selected = $finalCandidates->where('ai_selected', true);
        $rejected = $finalCandidates->where('ai_selected', false);

        $this->info("Opus 精審完成：選入 {$selected->count()} 檔 / 排除 {$rejected->count()} 檔");
        $this->newLine();

        // 輸出選入標的
        foreach ($selected as $c) {
            $adj = $c->ai_score_adjustment > 0 ? "+{$c->ai_score_adjustment}" : $c->ai_score_adjustment;
            $this->line(sprintf(
                "  ✓ %s %s | Haiku信度 %d(%s) | %s | 支撐 %s / 壓力 %s | %s",
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
        $total = $allCandidates->count();
        $haikuCount = $haikuApproved->count();
        $selectedCount = $selected->count();

        $lines = ["📊 *當沖 AI 選股完成* ({$date})"];
        $lines[] = "寬篩 {$total} 檔 → Haiku {$haikuCount} 檔 → Opus 選入 {$selectedCount} 檔";
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

        $telegram->broadcast(implode("\n", $lines));

        return self::SUCCESS;
    }
}

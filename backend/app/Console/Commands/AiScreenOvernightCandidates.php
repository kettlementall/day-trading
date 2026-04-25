<?php

namespace App\Console\Commands;

use App\Models\Candidate;
use App\Models\CandidateMonitor;
use App\Models\MarketHoliday;
use App\Models\SectorIndex;
use App\Services\AiScreenerService;
use App\Services\HaikuPreFilterService;
use App\Services\StockScreener;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AiScreenOvernightCandidates extends Command
{
    protected $signature = 'stock:ai-screen-overnight {date?}';
    protected $description = '隔日沖選股（12:50 執行）：Screener → Haiku → Opus，供今日收盤前下單';

    public function handle(
        StockScreener $screener,
        HaikuPreFilterService $haiku,
        AiScreenerService $ai
    ): int {
        $snapshotDate = $this->argument('date') ?? now()->format('Y-m-d');
        $tradeDate    = $this->getNextTradingDay($snapshotDate);

        $this->info("隔日沖選股：盤中日 {$snapshotDate}，目標交易日 {$tradeDate}");

        // 等待類股指數就緒（依賴 12:45 的 stock:fetch-sector-indices）
        $maxWait = 10; // 最多等 10 次 × 30 秒 = 5 分鐘
        for ($i = 0; $i < $maxWait; $i++) {
            if (SectorIndex::where('date', $snapshotDate)->exists()) {
                break;
            }
            if ($i === 0) {
                $this->warn("類股指數尚未就緒，等待中...");
            }
            sleep(30);
        }
        if (!SectorIndex::where('date', $snapshotDate)->exists()) {
            $this->warn("類股指數未就緒，繼續選股（無類股資料）");
        }

        // 清除舊的隔日沖候選（含 monitor），確保重跑不殘留
        $oldIds = Candidate::where('trade_date', $tradeDate)
            ->where('mode', 'overnight')
            ->pluck('id');
        if ($oldIds->isNotEmpty()) {
            CandidateMonitor::whereIn('candidate_id', $oldIds)->delete();
            Candidate::whereIn('id', $oldIds)->delete();
            $this->info("已清除 {$oldIds->count()} 筆舊隔日沖候選");
        }

        // Step 1: 規則篩選
        $this->info('Step 1: 股票篩選（overnight 門檻）...');
        $candidates = $screener->screen($tradeDate, null, null, 'overnight');
        $this->info("篩選完成：{$candidates->count()} 檔通過物理門檻");

        if ($candidates->isEmpty()) {
            $this->warn('無候選標的，終止。');
            return self::SUCCESS;
        }

        // Step 2: Haiku 快速預篩（最多放行 20 檔給 Opus）
        $this->info('Step 2: Haiku 快速預篩...');
        $candidates = $haiku->filter($tradeDate, $candidates, 20, 'overnight', $snapshotDate);
        $passedHaiku = $candidates->where('haiku_selected', true)->count();
        $this->info("Haiku 預篩完成：{$passedHaiku} 檔通過");

        if ($passedHaiku === 0) {
            $this->warn('Haiku 無通過標的，終止。');
            return self::SUCCESS;
        }

        // Step 3: Opus 深度審核（設定三個價格）
        $this->info('Step 3: Opus 深度審核...');
        $haikuPassed = $candidates->where('haiku_selected', true)->values();
        $candidates  = $ai->screen($tradeDate, $haikuPassed, 'overnight', $snapshotDate);

        $selected = $candidates->where('ai_selected', true)->count();
        $this->info("Opus 審核完成：{$selected} 檔選入隔日沖清單");

        // Step 4: 為 AI 選入的候選初始化隔日監控（status=holding）
        $selectedCandidates = $candidates->where('ai_selected', true)->values();
        $this->initOvernightMonitors($selectedCandidates);
        $this->info("已建立 {$selected} 筆隔日監控記錄");

        // Telegram 通知
        $total      = $candidates->count();
        $haikuCount = $candidates->where('haiku_selected', true)->count();

        $lines = ["🌙 *隔日沖選股完成* ({$snapshotDate} → {$tradeDate})"];
        $lines[] = "寬篩 {$total} 檔 → Haiku {$haikuCount} 檔 → Opus 選入 {$selected} 檔";
        $lines[] = '';

        foreach ($selectedCandidates as $c) {
            $lines[] = sprintf(
                "• %s %s | %s | 買 %.1f / 目標 %.1f / 停損 %.1f",
                $c->stock->symbol,
                $c->stock->name,
                $c->overnight_strategy ?? '-',
                (float) $c->suggested_buy,
                (float) $c->target_price,
                (float) $c->stop_loss
            );
        }

        app(TelegramService::class)->send(implode("\n", $lines));

        Log::info("AiScreenOvernightCandidates：{$snapshotDate} → {$tradeDate}，選入 {$selected} 檔");

        return self::SUCCESS;
    }

    /**
     * 為 AI 選入的候選建立/重置 CandidateMonitor（status=holding）
     */
    private function initOvernightMonitors(\Illuminate\Support\Collection $candidates): void
    {
        foreach ($candidates as $candidate) {
            CandidateMonitor::updateOrCreate(
                ['candidate_id' => $candidate->id],
                [
                    'status'          => CandidateMonitor::STATUS_HOLDING,
                    'current_target'  => $candidate->target_price,
                    'current_stop'    => $candidate->stop_loss,
                    'ai_advice_log'   => null,
                    'state_log'       => null,
                    'entry_price'     => null,
                    'entry_time'      => null,
                    'exit_price'      => null,
                    'exit_time'       => null,
                ]
            );
        }
    }

    /**
     * 取得下一個交易日（略過週末）
     * 注意：未處理台股公休日，如遇公休需手動傳入 date 參數
     */
    private function getNextTradingDay(string $date): string
    {
        $next = Carbon::parse($date)->addDay();

        // 跳過週末與國定假日（最多往後查 10 天，防無窮迴圈）
        for ($i = 0; $i < 10; $i++) {
            if (!$next->isWeekend() && !MarketHoliday::isHoliday($next->format('Y-m-d'))) {
                break;
            }
            $next->addDay();
        }

        return $next->format('Y-m-d');
    }
}

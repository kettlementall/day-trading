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
    protected $signature = 'stock:ai-screen-overnight {date?} {--force : 即使已有 Opus 完成過的批次仍強制覆寫} {--backfill : 補跑歷史資料（跳過 monitor 初始化與 Telegram 通知）}';
    protected $description = '隔日沖選股（12:50 執行）：Screener → Haiku → Opus，供今日收盤前下單';

    public function handle(
        StockScreener $screener,
        HaikuPreFilterService $haiku,
        AiScreenerService $ai
    ): int {
        $snapshotDate = $this->argument('date') ?? now()->format('Y-m-d');
        $tradeDate    = MarketHoliday::nextTradingDay($snapshotDate);
        $force        = (bool) $this->option('force');
        // 補跑模式：使用者顯式指定 --backfill，或 trade_date 已在過去（避免為舊批次建立 holding monitor）
        $backfill     = (bool) $this->option('backfill') || Carbon::parse($tradeDate)->isPast();

        $this->info("隔日沖選股：盤中日 {$snapshotDate}，目標交易日 {$tradeDate}" . ($backfill ? '（補跑模式）' : ''));

        // 確認類股指數可用（TWSE MI_INDEX 為收盤指數，盤中只有前一日資料）
        $sectorDate = SectorIndex::latestDateOn($snapshotDate);
        if ($sectorDate) {
            $this->info("類股指數就緒（資料日期：{$sectorDate}）");
        } else {
            $this->warn("類股指數未就緒，繼續選股（無類股資料）");
        }

        // 安全閘：若已有 Opus 完成過的批次（ai_reasoning 非空），預設拒絕覆寫
        // Why: 17:41 那次手動跑只跑到 screener 就中斷，但前置 DELETE 已先執行→ 12:50 完整批次連同 8 筆選入全部消失。
        // How: 看到 ai_reasoning 已填代表 Opus 跑完，這時必須由使用者顯式 --force 才能覆寫。
        $existingComplete = Candidate::where('trade_date', $tradeDate)
            ->where('mode', 'overnight')
            ->whereNotNull('ai_reasoning')
            ->where('ai_reasoning', '!=', '')
            ->count();

        if ($existingComplete > 0 && ! $force) {
            $this->error("trade_date={$tradeDate} 已有 {$existingComplete} 筆完成過 Opus 審核的紀錄。如確定要覆寫，請加 --force。");
            return self::FAILURE;
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
        // 補跑模式跳過：trade_date 已過，建 holding 沒意義且會污染未來查詢
        $selectedCandidates = $candidates->where('ai_selected', true)->values();
        if (! $backfill) {
            $this->initOvernightMonitors($selectedCandidates);
            $this->info("已建立 {$selected} 筆隔日監控記錄");
        } else {
            $this->info('補跑模式：跳過 monitor 初始化');
        }

        // Telegram 通知（補跑模式跳過，避免發出過時通知）
        $total      = $candidates->count();
        $haikuCount = $candidates->where('haiku_selected', true)->count();

        if (! $backfill) {
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

            app(TelegramService::class)->broadcast(implode("\n", $lines));
        }

        Log::info("AiScreenOvernightCandidates：{$snapshotDate} → {$tradeDate}，選入 {$selected} 檔" . ($backfill ? '（補跑）' : ''));

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

}

<?php

namespace App\Console\Commands;

use App\Services\AiScreenerService;
use App\Services\HaikuPreFilterService;
use App\Services\StockScreener;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AiScreenOvernightCandidates extends Command
{
    protected $signature = 'stock:ai-screen-overnight {date?}';
    protected $description = '隔日沖選股（12:30 執行）：Screener → Haiku → Opus，供今日收盤前下單';

    public function handle(
        StockScreener $screener,
        HaikuPreFilterService $haiku,
        AiScreenerService $ai
    ): int {
        $snapshotDate = $this->argument('date') ?? now()->format('Y-m-d');
        $tradeDate    = $this->getNextTradingDay($snapshotDate);

        $this->info("隔日沖選股：盤中日 {$snapshotDate}，目標交易日 {$tradeDate}");

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

        Log::info("AiScreenOvernightCandidates：{$snapshotDate} → {$tradeDate}，選入 {$selected} 檔");

        return self::SUCCESS;
    }

    /**
     * 取得下一個交易日（略過週末）
     * 注意：未處理台股公休日，如遇公休需手動傳入 date 參數
     */
    private function getNextTradingDay(string $date): string
    {
        $next = Carbon::parse($date)->addDay();

        while ($next->isWeekend()) {
            $next->addDay();
        }

        return $next->format('Y-m-d');
    }
}

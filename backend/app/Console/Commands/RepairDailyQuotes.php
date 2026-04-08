<?php

namespace App\Console\Commands;

use App\Models\DailyQuote;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairDailyQuotes extends Command
{
    protected $signature = 'stock:repair-quotes {--from= : 修復起始日 (YYYY-MM-DD)} {--to= : 修復結束日 (YYYY-MM-DD)} {--dry-run : 只檢查不修改}';
    protected $description = '修復因假日抓取導致日期錯位的行情資料：刪除假日錯誤資料，重新抓取正確交易日';

    public function handle(): int
    {
        $from = $this->option('from') ?? now()->subDays(30)->format('Y-m-d');
        $to = $this->option('to') ?? now()->format('Y-m-d');
        $dryRun = $this->option('dry-run');

        $this->info("修復範圍: {$from} ~ {$to}" . ($dryRun ? ' (dry-run 模式)' : ''));

        // Step 1: 找出可疑的假日資料
        // 同一天所有股票的 OHLCV 完全相同於另一天 → 就是假日重複存入
        $dates = DailyQuote::selectRaw('date, COUNT(*) as cnt')
            ->whereBetween('date', [$from, $to])
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('cnt', 'date');

        $this->info("期間共 {$dates->count()} 個有資料的日期");

        // 取每個日期的一筆代表性資料做比對
        $sampleStockId = DailyQuote::whereBetween('date', [$from, $to])
            ->groupBy('stock_id')
            ->havingRaw('COUNT(DISTINCT date) = ?', [$dates->count()])
            ->value('stock_id');

        if (!$sampleStockId) {
            $sampleStockId = DailyQuote::whereBetween('date', [$from, $to])
                ->selectRaw('stock_id, COUNT(DISTINCT date) as dcnt')
                ->groupBy('stock_id')
                ->orderByDesc('dcnt')
                ->value('stock_id');
        }

        if (!$sampleStockId) {
            $this->error('找不到足夠的樣本資料');
            return self::FAILURE;
        }

        $samples = DailyQuote::where('stock_id', $sampleStockId)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get()
            ->keyBy(fn ($q) => $q->date->format('Y-m-d'));

        // 找出重複資料的日期（與前一個交易日 OHLCV 完全相同）
        $duplicateDates = [];
        $prevQuote = null;
        foreach ($samples as $date => $quote) {
            if ($prevQuote && $this->isSameData($quote, $prevQuote)) {
                $duplicateDates[] = $date;
                $this->line("  <fg=red>✗</> {$date} — 資料與 {$prevQuote->date->format('Y-m-d')} 完全相同（假日重複）");
            } else {
                $this->line("  <fg=green>✓</> {$date}");
                $prevQuote = $quote;
            }
        }

        if (empty($duplicateDates)) {
            $this->info('未發現重複的假日資料');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn("發現 " . count($duplicateDates) . " 個假日錯誤資料");

        if ($dryRun) {
            $this->info('dry-run 模式，不執行修改');
            return self::SUCCESS;
        }

        if (!$this->confirm('確定要刪除這些假日的錯誤資料？')) {
            return self::SUCCESS;
        }

        // Step 2: 刪除假日的錯誤資料
        foreach ($duplicateDates as $date) {
            $deleted = DailyQuote::where('date', $date)->delete();
            $this->line("  刪除 {$date}: {$deleted} 筆");
        }

        // Step 3: 重新抓取整個期間的正確交易日資料
        $this->newLine();
        $this->info('重新抓取正確交易日資料...');

        $current = \Carbon\Carbon::parse($from);
        $end = \Carbon\Carbon::parse($to);
        $fetchedDays = 0;

        while ($current->lte($end)) {
            // 跳過週末
            if ($current->isWeekend()) {
                $current->addDay();
                continue;
            }

            $dateStr = $current->format('Ymd');
            $this->line("  抓取 {$current->format('Y-m-d')}...");

            $this->call('stock:fetch-daily', ['date' => $dateStr]);
            $fetchedDays++;

            // TWSE 有頻率限制，間隔 3 秒
            sleep(3);

            $current->addDay();
        }

        $this->newLine();
        $this->info("修復完成！重新抓取了 {$fetchedDays} 天的資料（非交易日會自動跳過）");

        return self::SUCCESS;
    }

    private function isSameData(DailyQuote $a, DailyQuote $b): bool
    {
        return (float) $a->open === (float) $b->open
            && (float) $a->high === (float) $b->high
            && (float) $a->low === (float) $b->low
            && (float) $a->close === (float) $b->close
            && (int) $a->volume === (int) $b->volume;
    }
}

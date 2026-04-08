<?php

namespace App\Console\Commands;

use App\Models\Candidate;
use App\Models\DailyQuote;
use App\Models\InstitutionalTrade;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class HealthCheck extends Command
{
    protected $signature = 'stock:health-check {date?}';
    protected $description = '檢查每日資料抓取是否正常，異常時記錄警告';

    public function handle(): int
    {
        $date = $this->argument('date') ?? now()->format('Y-m-d');
        $issues = [];

        // 1. 檢查每日行情
        $quoteCount = DailyQuote::where('date', $date)->count();
        if ($quoteCount === 0) {
            $issues[] = "每日行情 0 筆（預期 > 800）";
        } elseif ($quoteCount < 800) {
            $issues[] = "每日行情僅 {$quoteCount} 筆（預期 > 800），可能有部分資料缺失";
        } else {
            $this->info("✓ 每日行情: {$quoteCount} 筆");
        }

        // 2. 檢查三大法人
        $instCount = InstitutionalTrade::where('date', $date)->count();
        if ($instCount === 0) {
            $issues[] = "三大法人 0 筆（預期 > 800）";
        } elseif ($instCount < 800) {
            $issues[] = "三大法人僅 {$instCount} 筆（預期 > 800）";
        } else {
            $this->info("✓ 三大法人: {$instCount} 筆");
        }

        // 3. 檢查行情價格合理性（抽樣）
        $badPrices = DailyQuote::where('date', $date)
            ->where(function ($q) {
                $q->where('close', '<=', 0)
                    ->orWhere('open', '<=', 0)
                    ->orWhereColumn('high', '<', 'low');
            })
            ->count();
        if ($badPrices > 0) {
            $issues[] = "有 {$badPrices} 筆異常價格資料（收盤<=0 或 高<低）";
        } else {
            $this->info("✓ 價格合理性: 通過");
        }

        // 4. 檢查候選標的（如果已經跑過選股）
        $nextTradeDate = now()->addWeekday()->format('Y-m-d');
        $candidateCount = Candidate::where('trade_date', $nextTradeDate)->count();
        if ($candidateCount > 0) {
            $this->info("✓ 候選標的: {$candidateCount} 檔（{$nextTradeDate}）");
        } else {
            $this->info("  候選標的: 尚未產出（{$nextTradeDate}）");
        }

        // 5. 驗證 TWSE API 可連線
        try {
            $response = @file_get_contents(
                'https://www.twse.com.tw/exchangeReport/MI_INDEX?response=json&date=' . now()->format('Ymd') . '&type=ALLBUT0999',
                false,
                stream_context_create(['http' => ['timeout' => 10]])
            );
            $json = json_decode($response, true);
            if (isset($json['stat'])) {
                $this->info("✓ TWSE API: 可連線 (stat={$json['stat']})");
            } else {
                $issues[] = "TWSE API 回傳格式異常（無 stat 欄位）";
            }
        } catch (\Exception $e) {
            $issues[] = "TWSE API 無法連線: " . $e->getMessage();
        }

        // 結果
        $this->newLine();
        if (empty($issues)) {
            $this->info("=== 健康檢查通過 ===");
            return self::SUCCESS;
        }

        $this->error("=== 發現 " . count($issues) . " 個問題 ===");
        foreach ($issues as $issue) {
            $this->warn("  ✗ {$issue}");
            Log::warning("[HealthCheck] {$date}: {$issue}");
        }

        return self::FAILURE;
    }
}

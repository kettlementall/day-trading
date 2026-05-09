<?php

namespace App\Console\Commands;

use App\Models\MarketHoliday;
use App\Services\InvestmentThesisResearchService;
use App\Services\TelegramService;
use Illuminate\Console\Command;

class ResearchInvestmentTheses extends Command
{
    protected $signature = 'stock:research-investment-theses {date?}';
    protected $description = 'AI 研究並維護短線產業投資論點';

    public function handle(InvestmentThesisResearchService $service): int
    {
        $date = $this->argument('date') ?? now()->toDateString();
        if (!$this->argument('date') && MarketHoliday::isHoliday($date)) {
            $this->info("{$date} 休市，跳過 AI 產業論點研究");
            return self::SUCCESS;
        }
        $result = $service->research($date);

        app(TelegramService::class)->broadcast(
            "✅ *AI 產業論點研究* 完成\n📅 {$date} | 更新 {$result['saved']} 筆 | 新聞 {$result['input_articles']} 篇",
            'system'
        );

        $this->info("AI 產業論點研究完成：更新 {$result['saved']} 筆");
        return self::SUCCESS;
    }
}

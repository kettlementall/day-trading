<?php

namespace App\Console\Commands;

use App\Models\MarketHoliday;
use App\Models\PremarketBriefing as PremarketBriefingModel;
use App\Services\PremarketBriefingService;
use Illuminate\Console\Command;

class PremarketBriefing extends Command
{
    protected $signature = 'stock:premarket-briefing {date?} {--force : 強制重新產出（覆蓋既有）}';
    protected $description = '盤前 Opus 方向簡報：聚合美股/夜盤/新聞情緒/法人籌碼 → 推 Telegram';

    public function handle(): int
    {
        $date = $this->argument('date') ?? now()->format('Y-m-d');

        // 排程執行時跳過休市日（手動傳入 date 時不檢查，保留補跑彈性）
        if (!$this->argument('date') && MarketHoliday::isHoliday($date)) {
            $this->info("{$date} 為休市日，跳過盤前簡報");
            return self::SUCCESS;
        }

        if (!$this->option('force')
            && PremarketBriefingModel::where('trade_date', $date)->exists()) {
            $this->info("{$date} 盤前簡報已存在，跳過（可用 --force 重跑）");
            return self::SUCCESS;
        }

        $this->info("產出 {$date} 盤前方向簡報...");

        try {
            $result = (new PremarketBriefingService())->generate(
                $date,
                fn(string $msg) => $this->line($msg)
            );
        } catch (\Throwable $e) {
            $this->error("盤前簡報失敗：" . $e->getMessage());
            return self::FAILURE;
        }

        $briefing = $result['briefing'];
        $this->info(sprintf(
            '完成：direction=%s | tokens=%d→%d | cost=$%.4f',
            $briefing->direction,
            $briefing->prompt_tokens ?? 0,
            $briefing->completion_tokens ?? 0,
            $briefing->cost_usd ?? 0
        ));

        return self::SUCCESS;
    }
}

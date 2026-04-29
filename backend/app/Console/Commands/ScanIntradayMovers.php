<?php

namespace App\Console\Commands;

use App\Models\DailyQuote;
use App\Models\FormulaSetting;
use App\Services\FugleRealtimeClient;
use App\Services\HaikuPreFilterService;
use App\Services\IntradayMoverService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ScanIntradayMovers extends Command
{
    protected $signature = 'stock:scan-intraday-movers
        {--date= : 模擬日期（測試用）}';

    protected $description = '腿 2：盤中動態加入候選（4+1 軸聯集 → Fugle 即時報價 → 4 條規則 → Haiku 快評 → 寫入 candidates）';

    public function handle(
        IntradayMoverService $service,
        FugleRealtimeClient $fugle,
        HaikuPreFilterService $haiku,
        TelegramService $tg
    ): int {
        $tradeDate = $this->option('date') ?? now()->format('Y-m-d');

        $thresholds = FormulaSetting::getConfig('intraday_mover_thresholds') ?: [];
        $topN = (int) ($thresholds['pool_top_n'] ?? 100);

        // 1. 選池
        $pool = $service->selectScanPool($tradeDate, $topN);
        $this->info("待掃描池: {$pool->count()} 檔");
        Log::info("ScanIntradayMovers: 待掃描池 {$pool->count()} 檔");

        if ($pool->isEmpty()) {
            $tg->broadcast("ℹ️ *盤中加入* 待掃描池為空", 'system');
            return self::SUCCESS;
        }

        // 2. 取 Fugle 鎖（只包 fetchQuotes，避免跟 monitor-intraday 撞車）
        $lock = Cache::lock('fugle_bulk', 120); // 250 檔 × 150ms ≈ 38s，留餘量
        if (!$lock->block(30)) {
            $this->error('無法取得 Fugle 鎖，跳過此輪');
            Log::warning('ScanIntradayMovers: 無法取得 Fugle 鎖');
            return self::FAILURE;
        }

        try {
            // 3. 即時報價
            $this->info('抓取即時報價...');
            $quotes = $fugle->fetchQuotes($pool->all());
            $this->info("取得 " . count($quotes) . " 檔報價");
        } finally {
            $lock->release();
        }

        // 4. 規則過濾（不需 Fugle 鎖）
        $survivors = $service->filterByLiveQuote($pool, $quotes, $thresholds);
        $this->info("通過規則過濾: {$survivors->count()} 檔");

        if ($survivors->isEmpty()) {
            $tg->broadcast("ℹ️ *盤中加入* 無符合標的（規則過濾後）", 'system');
            return self::SUCCESS;
        }

        // 4.5. 為通過的標的抓取 5 分 K（再次取鎖，但只需抓 5-15 檔，很快）
        $candlesMap = [];
        $candleLock = Cache::lock('fugle_bulk', 30);
        if ($candleLock->block(15)) {
            try {
                foreach ($survivors as $stock) {
                    $candlesMap[$stock->symbol] = $fugle->fetchCandles($stock->symbol);
                    usleep(150_000);
                }
            } finally {
                $candleLock->release();
            }
        } else {
            $this->warn('無法取得 Fugle 鎖抓 5 分 K，Haiku 將缺少 K 棒資料');
        }

        // 5. Haiku 快評
        $this->info('Haiku 快評...');
        $haikuResults = $haiku->filterIntradayMovers($survivors, $quotes, $candlesMap, $tradeDate);

        $minConfidence = (int) ($thresholds['min_haiku_confidence'] ?? 60);
        $selected = $survivors->filter(fn($s) =>
            ($haikuResults[$s->symbol]['keep'] ?? false)
            && ($haikuResults[$s->symbol]['confidence'] ?? 0) >= $minConfidence
        );

        $this->info("Haiku 通過: {$selected->count()} 檔");

        if ($selected->isEmpty()) {
            $tg->broadcast("ℹ️ *盤中加���* 無符合標的（Haiku 快評後）", 'system');
            return self::SUCCESS;
        }

        // 6+7+8. 計算進場價並寫入 Candidate + Monitor
        $added = collect();
        $minRR = (float) ($thresholds['min_risk_reward'] ?? 0.8);

        foreach ($selected as $stock) {
            $prevClose = DailyQuote::where('stock_id', $stock->id)
                ->where('date', '<', $tradeDate)
                ->orderByDesc('date')
                ->value('close');

            if (!$prevClose) {
                $this->warn("{$stock->symbol}: 無前日收盤價，跳過");
                continue;
            }

            $prices = $service->calcEntryPrices($quotes[$stock->symbol], (float) $prevClose);
            if ($prices['risk_reward'] < $minRR) {
                $this->line("{$stock->symbol}: RR {$prices['risk_reward']} < {$minRR}，跳過");
                continue;
            }

            $candidate = $service->assembleCandidate(
                $stock, $prices, $haikuResults[$stock->symbol], $tradeDate
            );
            $service->assembleMonitor($candidate, $prices);
            $added->push($candidate);

            $this->info("✅ {$stock->symbol} {$stock->name} 買{$prices['suggested_buy']} 目標{$prices['target_price']} 停損{$prices['stop_loss']} RR:{$prices['risk_reward']}");
        }

        // 10. Telegram 摘要
        if ($added->isNotEmpty()) {
            $list = $added->map(fn($c) => "{$c->stock->symbol} {$c->stock->name}")->implode(', ');
            $tg->broadcast("✅ *盤中加入* {$added->count()} 檔: {$list}", 'system');
            Log::info("ScanIntradayMovers: 加入 {$added->count()} 檔: {$list}");
        } else {
            $tg->broadcast("ℹ️ *盤中加入* 無符合標的（RR 不足）", 'system');
        }

        return self::SUCCESS;
    }
}

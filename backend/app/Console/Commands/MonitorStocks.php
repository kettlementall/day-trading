<?php

namespace App\Console\Commands;

use App\Models\Candidate;
use App\Models\CandidateMonitor;
use App\Models\DailyQuote;
use App\Models\IntradaySnapshot;
use App\Models\MarketHoliday;
use App\Models\Stock;
use App\Services\IntradayAiAdvisor;
use App\Services\MonitorService;
use App\Services\TelegramService;
use App\Services\FugleRealtimeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MonitorStocks extends Command
{
    protected $signature = 'stock:monitor-intraday {date?}';
    protected $description = '盤中即時監控：每 30 秒快照 + AI 校準 + 規則式監控（09:00-13:30）';

    private ?\Carbon\Carbon $lastAiAdviceAt = null;

    public function __construct(
        private FugleRealtimeClient $client,
        private MonitorService $monitorService,
        private IntradayAiAdvisor $aiAdvisor,
        private TelegramService $telegram,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $date = $this->argument('date') ?? now()->format('Y-m-d');

        if (MarketHoliday::isHoliday($date)) {
            $this->line("今日（{$date}）休市，跳過監控");
            return self::SUCCESS;
        }


        // 時段外（scheduler 重啟保底用，正常由 between() 濾掉）
        $now = now();
        if ($now->format('H:i') > '13:30' || $now->format('H') < 9) {
            $this->line("非交易時段，跳過");
            return self::SUCCESS;
        }

        $this->info("盤中監控啟動（{$date}），每 30 秒快照");

        while (true) {
            $now = now();
            $timeStr = $now->format('H:i');

            if ($timeStr > '13:30') {
                $this->info("已過 13:30，監控結束");
                break;
            }

            $this->runCycle($date, $now);

            // 最後一個 cycle 做完直接結束，不再 sleep
            if (now()->format('H:i') >= '13:30') {
                break;
            }

            sleep(30);
        }

        return self::SUCCESS;
    }

    /**
     * 單次監控週期（每 30 秒執行一次）
     */
    private function runCycle(string $date, \Carbon\Carbon $now): void
    {
        $hour    = (int) $now->format('H');
        $minute  = (int) $now->format('i');
        $timeStr = $now->format('H:i');

        $candidates = Candidate::with('stock')
            ->where('trade_date', $date)
            ->where('mode', 'intraday')
            ->get();

        // 隔日沖標的（trade_date = 今日、mode = overnight、AI 選入）：只做快照，不做當沖監控
        $overnightCandidates = Candidate::with('stock')
            ->where('trade_date', $date)
            ->where('mode', 'overnight')
            ->where('ai_selected', true)
            ->get();

        // 合併所有需要快照的股票（去重）
        $intradayStocks  = $candidates->pluck('stock')->unique('id');
        $overnightStocks = $overnightCandidates->pluck('stock')->unique('id');
        $allStocks       = $intradayStocks->merge($overnightStocks)->unique('id')->values()->all();

        if (empty($allStocks)) {
            $this->warn("[{$timeStr}] 無 {$date} 需快照標的");
            return;
        }

        $overnightOnlyCount = $overnightStocks->diffKeys($intradayStocks->keyBy('id'))->count();
        $this->info("[{$timeStr}] 快照 " . count($allStocks) . " 檔" . ($overnightOnlyCount > 0 ? "（含隔日沖 {$overnightOnlyCount} 檔）" : ''));

        // ===== Step 1: 抓取即時報價並寫入快照 =====
        $quotes = $this->client->fetchQuotes($allStocks);
        $written = $this->writeSnapshots($quotes, $date, $now);
        $this->info("寫入 {$written} 筆快照");

        // ===== 以下步驟只針對當沖候選（intraday），不影響隔日沖 =====
        if ($candidates->isEmpty()) {
            return;
        }

        // ===== Step 1.5: 漲停/跌停通知 =====
        $this->notifyLimitHits($quotes, $date);

        // ===== Step 2: 09:02–09:10 AI 開盤校準（只做一次，確保至少已有 2 輪快照） =====
        if ($written > 0 && !$this->hasCalibrated($date) && $timeStr >= '09:02' && $timeStr <= '09:10') {
            $this->performOpeningCalibration($date, $candidates);
        }

        // ===== Step 3: 規則式監控（每次快照後） =====
        $emergencyMonitors = $this->monitorService->processSnapshot($date);

        // ===== Step 4: AI 滾動判斷（以上次執行時間控制間隔） =====
        $aiInterval = $this->getAiInterval($hour, $minute);
        if ($aiInterval > 0 && $timeStr >= '09:15') {
            $elapsed = $this->lastAiAdviceAt ? $this->lastAiAdviceAt->diffInMinutes($now) : PHP_INT_MAX;
            if ($elapsed >= $aiInterval) {
                $this->performRollingAdvice($date);
                $this->lastAiAdviceAt = $now->copy();
            }
        }

        // ===== Step 5: 緊急 AI 觸發 =====
        if (!empty($emergencyMonitors)) {
            $this->performEmergencyAdvice($date, $emergencyMonitors);
        }
    }

    /**
     * AI 開盤校準
     */
    private function performOpeningCalibration(string $date, $candidates): void
    {
        $this->info('執行 AI 開盤校準...');

        // 初始化 monitors
        $this->monitorService->initializeMonitors($date);

        // 取 AI 選中的候選 + 其快照
        $aiCandidates = $candidates->where('ai_selected', true);
        $snapshots = IntradaySnapshot::whereIn('stock_id', $aiCandidates->pluck('stock_id'))
            ->where('trade_date', $date)
            ->get();

        // AI 校準
        $calibrations = $this->aiAdvisor->openingCalibration($date, $aiCandidates, $snapshots);

        // 套用結果
        $this->monitorService->applyCalibration($date, $calibrations);

        $this->info('AI 開盤校準完成');
    }

    /**
     * AI 滾動判斷（取當日所有快照供 5 分 K 聚合）
     */
    private function performRollingAdvice(string $date): void
    {
        $monitors = CandidateMonitor::with(['candidate.stock'])
            ->whereHas('candidate', fn($q) => $q->where('trade_date', $date)->where('mode', 'intraday'))
            ->whereIn('status', CandidateMonitor::ACTIVE_STATUSES)
            ->get();

        if ($monitors->isEmpty()) return;

        $this->info("AI 滾動判斷：{$monitors->count()} 檔活躍標的");

        foreach ($monitors as $monitor) {
            $stock = $monitor->candidate->stock;

            // 取當日所有快照（供 5 分 K 聚合）
            $allSnapshots = IntradaySnapshot::where('stock_id', $stock->id)
                ->where('trade_date', $date)
                ->orderBy('snapshot_time')
                ->get();

            if ($allSnapshots->isEmpty()) continue;

            $advice = $this->aiAdvisor->rollingAdvice($date, $monitor, $allSnapshots);
            $this->monitorService->applyRollingAdvice($monitor, $advice);

            $this->line("  {$stock->symbol}: {$advice['action']} - {$advice['notes']}");
        }
    }

    /**
     * 緊急 AI 觸發：HOLDING 中出現急殺，不等排程週期
     */
    private function performEmergencyAdvice(string $date, array $emergencyMonitors): void
    {
        foreach ($emergencyMonitors as $monitorId => $reason) {
            $monitor = CandidateMonitor::with(['candidate.stock'])->find($monitorId);
            if (!$monitor) continue;

            $stock = $monitor->candidate->stock;

            // 每股每 5 分鐘最多觸發一次緊急 AI
            $fiveMinSlot = (int) (now()->minute / 5);
            $cacheKey = "emergency_ai:{$stock->id}:{$date}:{$fiveMinSlot}";
            if (Cache::has($cacheKey)) continue;
            Cache::put($cacheKey, true, 300);

            $this->info("緊急 AI 觸發：{$stock->symbol} - {$reason}");

            $allSnapshots = IntradaySnapshot::where('stock_id', $stock->id)
                ->where('trade_date', $date)
                ->orderBy('snapshot_time')
                ->get();

            if ($allSnapshots->isEmpty()) continue;

            $advice = $this->aiAdvisor->emergencyAdvice($date, $monitor, $allSnapshots, $reason);
            $this->monitorService->applyRollingAdvice($monitor, $advice);

            $this->line("  {$stock->symbol} [緊急]: {$advice['action']} - {$advice['notes']}");
        }
    }

    /**
     * 檢查是否已執行過校準
     */
    private function hasCalibrated(string $date): bool
    {
        return CandidateMonitor::whereHas('candidate', fn($q) => $q->where('trade_date', $date)->where('mode', 'intraday'))
            ->where('status', '!=', CandidateMonitor::STATUS_PENDING)
            ->exists();
    }

    /**
     * 寫入快照
     */
    private function writeSnapshots(array $quotes, string $date, $now): int
    {
        $snapshotTime = $now->copy()->second(0);
        $written = 0;

        foreach ($quotes as $symbol => $data) {
            $stock = Stock::where('symbol', $symbol)->first();
            if (!$stock) continue;

            $yesterdayVolume = $this->getYesterdayVolume($stock->id, $date);

            $marketOpen = $now->copy()->setTime(9, 0, 0);
            $totalMinutes = 270;
            $elapsedMinutes = max(1, $marketOpen->diffInMinutes($now));

            $estimatedDailyVolume = ($data['accumulated_volume'] / $elapsedMinutes) * $totalMinutes;
            $estimatedRatio = $yesterdayVolume > 0
                ? round($estimatedDailyVolume / $yesterdayVolume, 2)
                : 0;

            $openChangePercent = $data['prev_close'] > 0
                ? round(($data['open'] - $data['prev_close']) / $data['prev_close'] * 100, 2)
                : 0;

            $changePercent = $data['prev_close'] > 0
                ? round(($data['current_price'] - $data['prev_close']) / $data['prev_close'] * 100, 2)
                : 0;

            $amplitudePercent = $data['prev_close'] > 0
                ? round(($data['high'] - $data['low']) / $data['prev_close'] * 100, 2)
                : 0;

            // 內外盤推算
            $prevSnapshot = IntradaySnapshot::where('stock_id', $stock->id)
                ->where('trade_date', $date)
                ->orderByDesc('snapshot_time')
                ->first();

            $buyVolume = $prevSnapshot?->buy_volume ?? 0;
            $sellVolume = $prevSnapshot?->sell_volume ?? 0;

            $prevAccVolume = $prevSnapshot?->accumulated_volume ?? 0;
            $deltaVolume = max(0, $data['accumulated_volume'] - $prevAccVolume);

            if (!empty($data['limit_up'])) {
                // 漲停：全部算外盤（買方）
                $buyVolume += $deltaVolume;
            } elseif (!empty($data['limit_down'])) {
                // 跌停：全部算內盤（賣方）
                $sellVolume += $deltaVolume;
            } elseif ($data['current_price'] > 0 && $data['best_ask'] > 0 && $data['best_bid'] > 0) {
                $midPrice = ($data['best_ask'] + $data['best_bid']) / 2;
                if ($data['current_price'] >= $midPrice) {
                    $buyVolume += $deltaVolume;
                } else {
                    $sellVolume += $deltaVolume;
                }
            }

            $totalBuySell = $buyVolume + $sellVolume;
            $externalRatio = $totalBuySell > 0
                ? round($buyVolume / $totalBuySell * 100, 2)
                : 50;

            IntradaySnapshot::updateOrCreate(
                [
                    'stock_id' => $stock->id,
                    'trade_date' => $date,
                    'snapshot_time' => $snapshotTime,
                ],
                [
                    'open' => $data['open'],
                    'high' => $data['high'],
                    'low' => $data['low'],
                    'current_price' => $data['current_price'],
                    'prev_close' => $data['prev_close'],
                    'accumulated_volume' => $data['accumulated_volume'],
                    'estimated_volume_ratio' => $estimatedRatio,
                    'open_change_percent' => $openChangePercent,
                    'buy_volume' => $buyVolume,
                    'sell_volume' => $sellVolume,
                    'external_ratio' => $externalRatio,
                    'best_ask' => $data['best_ask'] ?? 0,
                    'best_bid' => $data['best_bid'] ?? 0,
                    'change_percent' => $changePercent,
                    'amplitude_percent' => $amplitudePercent,
                    'limit_up' => !empty($data['limit_up']),
                    'limit_down' => !empty($data['limit_down']),
                ]
            );

            $written++;
        }

        return $written;
    }

    private function getAiInterval(int $hour, int $minute): int
    {
        return match (true) {
            $hour === 9 && $minute < 30 => 10,
            $hour === 9 || ($hour === 10 && $minute < 30) => 15,
            $hour >= 13 => 10,
            default => 20,
        };
    }

    /**
     * 漲停/跌停通知（每檔每日只通知一次）
     */
    private function notifyLimitHits(array $quotes, string $date): void
    {
        foreach ($quotes as $symbol => $data) {
            $isLimitUp = !empty($data['limit_up']);
            $isLimitDown = !empty($data['limit_down']);

            if (!$isLimitUp && !$isLimitDown) continue;

            $stock = Stock::where('symbol', $symbol)->first();
            if (!$stock) continue;

            // 只通知有 active monitor 的當沖標的（隔日沖由 monitor-overnight-exit 獨立處理）
            $monitor = CandidateMonitor::whereHas('candidate', fn($q) => $q
                ->where('trade_date', $date)
                ->where('stock_id', $stock->id)
                ->where('mode', 'intraday')
            )->whereIn('status', CandidateMonitor::ACTIVE_STATUSES)->first();

            if (!$monitor) continue;

            $cacheKey = sprintf('limit_notified:%s:%s:%s', $symbol, $date, $isLimitUp ? 'up' : 'down');
            if (Cache::has($cacheKey)) continue;

            $label = $isLimitUp ? '漲停' : '跌停';
            $pct = $data['prev_close'] > 0
                ? round(($data['current_price'] - $data['prev_close']) / $data['prev_close'] * 100, 1)
                : 0;

            $this->telegram->send(sprintf(
                "[%s] %s %s %.2f (%+.1f%%) | 量 %s 股 | 監控 %s",
                $label,
                $symbol,
                $stock->name,
                $data['current_price'],
                $pct,
                number_format($data['accumulated_volume']),
                $monitor->status
            ));

            $this->line("  {$symbol} {$stock->name} → {$label} {$data['current_price']}");

            Cache::put($cacheKey, true, now()->endOfDay());
        }
    }

    private function getYesterdayVolume(int $stockId, string $date): int
    {
        return DailyQuote::where('stock_id', $stockId)
            ->where('date', '<', $date)
            ->orderByDesc('date')
            ->value('volume') ?? 0;
    }
}

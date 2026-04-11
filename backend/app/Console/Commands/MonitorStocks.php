<?php

namespace App\Console\Commands;

use App\Models\Candidate;
use App\Models\CandidateMonitor;
use App\Models\DailyQuote;
use App\Models\IntradaySnapshot;
use App\Models\Stock;
use App\Services\IntradayAiAdvisor;
use App\Services\MonitorService;
use App\Services\TwseRealtimeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MonitorStocks extends Command
{
    protected $signature = 'stock:monitor-intraday {date?}';
    protected $description = '盤中即時監控：動態頻率快照 + AI 校準 + 規則式監控（09:00-13:30）';

    public function __construct(
        private TwseRealtimeClient $client,
        private MonitorService $monitorService,
        private IntradayAiAdvisor $aiAdvisor,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $date = $this->argument('date') ?? now()->format('Y-m-d');
        $now = now();
        $hour = (int) $now->format('H');
        $minute = (int) $now->format('i');
        $timeStr = $now->format('H:i');

        // 時段外不執行
        if ($hour < 9 || ($hour >= 13 && $minute > 30) || $hour >= 14) {
            $this->line("非交易時段，跳過");
            return self::SUCCESS;
        }

        // 動態頻率控制
        $interval = $this->getInterval($hour, $minute);
        if ($minute % $interval !== 0) {
            return self::SUCCESS;
        }

        // 取得當日候選標的
        $candidates = Candidate::with('stock')
            ->where('trade_date', $date)
            ->get();

        if ($candidates->isEmpty()) {
            $this->warn("無 {$date} 的候選標的，跳過監控");
            return self::SUCCESS;
        }

        $stocks = $candidates->pluck('stock')->unique('id')->values()->all();
        $this->info("[{$timeStr}] 快照 " . count($stocks) . " 檔（間隔 {$interval} 分鐘）");

        // ===== Step 1: 抓取即時報價並寫入快照 =====
        $quotes = $this->client->fetchQuotes($stocks);
        $written = $this->writeSnapshots($quotes, $date, $now);
        $this->info("寫入 {$written} 筆快照");

        // ===== Step 2: 09:05 AI 開盤校準（首次快照後） =====
        if ($timeStr === '09:05' || ($hour === 9 && $minute <= 5 && !$this->hasCalibrated($date))) {
            $this->performOpeningCalibration($date, $candidates);
        }

        // ===== Step 3: 規則式監控（每次快照後） =====
        $this->monitorService->processSnapshot($date);

        // ===== Step 4: AI 滾動判斷（依時段動態頻率） =====
        // 09:05-09:30 每10分 / 09:30-10:30 每15分 / 10:30-13:00 每20分 / 13:00-13:25 每10分
        $aiInterval = $this->getAiInterval($hour, $minute);
        if ($aiInterval > 0 && $minute % $aiInterval === 5 && $timeStr >= '09:15') {
            $this->performRollingAdvice($date);
        }

        return self::SUCCESS;
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
     * AI 滾動判斷
     */
    private function performRollingAdvice(string $date): void
    {
        $monitors = CandidateMonitor::with(['candidate.stock'])
            ->whereHas('candidate', fn($q) => $q->where('trade_date', $date))
            ->whereIn('status', CandidateMonitor::ACTIVE_STATUSES)
            ->get();

        if ($monitors->isEmpty()) return;

        $this->info("AI 滾動判斷：{$monitors->count()} 檔活躍標的");

        foreach ($monitors as $monitor) {
            $stock = $monitor->candidate->stock;

            // 取最近 30 分鐘快照
            $recentSnapshots = IntradaySnapshot::where('stock_id', $stock->id)
                ->where('trade_date', $date)
                ->where('snapshot_time', '>=', now()->subMinutes(30))
                ->orderBy('snapshot_time')
                ->get();

            if ($recentSnapshots->isEmpty()) continue;

            $advice = $this->aiAdvisor->rollingAdvice($date, $monitor, $recentSnapshots);
            $this->monitorService->applyRollingAdvice($monitor, $advice);

            $this->line("  {$stock->symbol}: {$advice['action']} - {$advice['notes']}");
        }
    }

    /**
     * 檢查是否已執行過校準
     */
    private function hasCalibrated(string $date): bool
    {
        return CandidateMonitor::whereHas('candidate', fn($q) => $q->where('trade_date', $date))
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

            if ($data['current_price'] > 0 && $data['best_ask'] > 0 && $data['best_bid'] > 0) {
                $midPrice = ($data['best_ask'] + $data['best_bid']) / 2;
                $prevAccVolume = $prevSnapshot?->accumulated_volume ?? 0;
                $deltaVolume = max(0, $data['accumulated_volume'] - $prevAccVolume);

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

            IntradaySnapshot::create([
                'stock_id' => $stock->id,
                'trade_date' => $date,
                'snapshot_time' => $snapshotTime,
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
            ]);

            $written++;
        }

        return $written;
    }

    private function getInterval(int $hour, int $minute): int
    {
        return match (true) {
            $hour === 9 && $minute < 30 => 1,
            $hour === 9 || ($hour === 10 && $minute < 30) => 2,
            $hour >= 13 => 1,
            default => 3,
        };
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

    private function getYesterdayVolume(int $stockId, string $date): int
    {
        return DailyQuote::where('stock_id', $stockId)
            ->where('date', '<', $date)
            ->orderByDesc('date')
            ->value('volume') ?? 0;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Candidate;
use App\Models\DailyQuote;
use App\Models\IntradayQuote;
use App\Models\Stock;
use App\Services\FugleRealtimeClient;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FetchIntradayQuotes extends Command
{
    protected $signature = 'stock:fetch-intraday {date?}';
    protected $description = '抓取盤中即時行情（針對當日候選標的）';

    public function __construct(private FugleRealtimeClient $client)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $date = $this->argument('date') ?? now()->format('Y-m-d');

        // 取得當日候選標的
        $candidates = Candidate::with('stock')
            ->where('trade_date', $date)
            ->get();

        if ($candidates->isEmpty()) {
            app(TelegramService::class)->broadcast("✅ *盤中行情* 完成\n📅 {$date} | 無候選標的，跳過", 'system');
            $this->warn("無 {$date} 的候選標的，跳過盤中抓取");
            return self::SUCCESS;
        }

        $this->info("抓取 {$candidates->count()} 檔候選標的的盤中行情...");

        $stocks = $candidates->pluck('stock')->unique('id')->values()->all();
        $quotes = $this->client->fetchQuotes($stocks);

        $this->info("MIS API 回傳 " . count($quotes) . " 筆");

        foreach ($quotes as $symbol => $data) {
            $this->processRealtimeItem($data, $date);
        }

        $stockCount = $candidates->pluck('stock')->unique('id')->count();
        $apiCount = count($quotes);
        app(TelegramService::class)->broadcast(
            "✅ *盤中行情* 完成\n📅 {$date} | 候選 {$stockCount} 檔 · API 回傳 {$apiCount} 筆",
            'system'
        );

        $this->info('盤中行情抓取完成');
        return self::SUCCESS;
    }

    /**
     * 處理單一即時行情項目，計算衍生指標並寫入 IntradayQuote
     */
    private function processRealtimeItem(array $data, string $date): void
    {
        $stock = Stock::where('symbol', $data['symbol'])->first();
        if (!$stock) return;

        // 查昨日成交量
        $yesterdayQuote = DailyQuote::where('stock_id', $stock->id)
            ->where('date', '<', $date)
            ->orderByDesc('date')
            ->first();
        $yesterdayVolume = $yesterdayQuote?->volume ?? 0;

        // 預估全日成交量：根據已過時間比例推算
        $now = now();
        $marketOpen = now()->copy()->setTime(9, 0, 0);
        $marketClose = now()->copy()->setTime(13, 30, 0);
        $totalMinutes = $marketOpen->diffInMinutes($marketClose); // 270 分鐘
        $elapsedMinutes = max(1, $marketOpen->diffInMinutes($now));

        $estimatedDailyVolume = ($data['accumulated_volume'] / $elapsedMinutes) * $totalMinutes;
        $estimatedRatio = $yesterdayVolume > 0
            ? round($estimatedDailyVolume / $yesterdayVolume, 2)
            : 0;

        // 開盤漲幅
        $openChangePercent = round(($data['open'] - $data['prev_close']) / $data['prev_close'] * 100, 2);

        // 第一根5分K（09:00~09:05）的高低點
        $existing = IntradayQuote::where('stock_id', $stock->id)->where('date', $date)->first();
        $first5minHigh = $existing?->first_5min_high;
        $first5minLow = $existing?->first_5min_low;

        if (!$first5minHigh || $now->format('H:i') <= '09:05') {
            $first5minHigh = $data['high'];
            $first5minLow = $data['low'];
        }

        // 內外盤量：使用 Fugle API 直接提供的 tradeVolumeAtAsk/Bid
        $buyVolume = $data['trade_volume_at_ask'] ?? 0;   // 外盤
        $sellVolume = $data['trade_volume_at_bid'] ?? 0;  // 內盤

        $totalBuySell = $buyVolume + $sellVolume;
        $externalRatio = $totalBuySell > 0
            ? round($buyVolume / $totalBuySell * 100, 2)
            : 50;

        IntradayQuote::updateOrCreate(
            ['stock_id' => $stock->id, 'date' => $date],
            [
                'open' => $data['open'],
                'high' => $data['high'],
                'low' => $data['low'],
                'current_price' => $data['current_price'],
                'prev_close' => $data['prev_close'],
                'accumulated_volume' => $data['accumulated_volume'],
                'yesterday_volume' => $yesterdayVolume,
                'estimated_volume_ratio' => $estimatedRatio,
                'open_change_percent' => $openChangePercent,
                'first_5min_high' => $first5minHigh,
                'first_5min_low' => $first5minLow,
                'buy_volume' => $buyVolume,
                'sell_volume' => $sellVolume,
                'external_ratio' => $externalRatio,
                'snapshot_at' => now(),
            ]
        );
    }
}

<?php

namespace App\Console\Commands;

use App\Models\UsMarketIndex;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchUsIndices extends Command
{
    protected $signature = 'stock:fetch-us-indices {date?}
        {--tx-only : 僅更新台指期（symbol=TX，寫即時 CLast，用於日盤盤中報價）}
        {--no-tx : 跳過台指期，僅抓美股指數}
        {--tx-day-close : 抓 TX 日盤收盤（symbol=TX_DAY，14:00 排程使用；change vs 前一交易日 TX_DAY）}
        {--tx-night-close : 抓 TX 夜盤收盤（symbol=TX_NIGHT，05:01 排程使用；change vs 最近 TX_DAY = T-1 日盤收）}';
    protected $description = '抓取台指期夜盤/日盤收盤 + 美股主要指數收盤數據';

    private const INDICES = [
        '^GSPC'    => 'S&P 500',
        '^SOX'     => '費半',
        '^DJI'     => '道瓊',
        '^IXIC'    => '那斯達克',
        'DX-Y.NYB' => '美元指數',
        '^VIX'     => 'VIX',
    ];

    public function handle(): int
    {
        $date = $this->argument('date') ?? now()->format('Y-m-d');
        $count = 0;
        $txOnly = $this->option('tx-only');
        $noTx = $this->option('no-tx');
        $txDayClose = $this->option('tx-day-close');
        $txNightClose = $this->option('tx-night-close');

        // 帶任一 TX 專屬旗標時，跳過美股段
        $skipUs = $txOnly || $txDayClose || $txNightClose;

        foreach (self::INDICES as $symbol => $name) {
            if ($skipUs) {
                continue;
            }
            try {
                $encoded = urlencode($symbol);
                $response = Http::timeout(10)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                    ->get("https://query1.finance.yahoo.com/v8/finance/chart/{$encoded}?interval=1d&range=2d");

                if (!$response->successful()) {
                    $this->warn("  {$symbol}: HTTP {$response->status()}");
                    continue;
                }

                $meta = $response->json('chart.result.0.meta');
                if (!$meta || !isset($meta['regularMarketPrice'], $meta['chartPreviousClose'])) {
                    $this->warn("  {$symbol}: 無資料");
                    continue;
                }

                $close = (float) $meta['regularMarketPrice'];
                $prevClose = (float) $meta['chartPreviousClose'];
                $changePct = $prevClose > 0
                    ? round(($close - $prevClose) / $prevClose * 100, 2)
                    : 0;

                UsMarketIndex::updateOrCreate(
                    ['date' => $date, 'symbol' => $symbol],
                    [
                        'name' => $name,
                        'close' => $close,
                        'prev_close' => $prevClose,
                        'change_percent' => $changePct,
                    ]
                );

                $sign = $changePct >= 0 ? '+' : '';
                $this->info("  {$name}: {$close} ({$sign}{$changePct}%)");
                $count++;

                usleep(300_000);
            } catch (\Exception $e) {
                Log::error("FetchUsIndices {$symbol}: " . $e->getMessage());
                $this->error("  {$symbol}: " . $e->getMessage());
            }
        }

        // 台指期（近月）from 期交所
        // 三層防呆：
        //   (1) --no-tx 跳過：06:00 排程用於只抓美股，避免抓到夜盤未定盤的 TX
        //   (2) CLastPrice 視為 string「0.00」→ truthy 漏判，改用 float > 0 檢查
        //   (3) CLast = 0（日盤開盤前無成交）→ 不可覆蓋既有 row 為 0/-100%
        if (!$noTx && $txDayClose) {
            $count += $this->fetchTxSessionClose($date, 'TX_DAY', '台指期日盤收盤') ? 1 : 0;
        } elseif (!$noTx && $txNightClose) {
            $count += $this->fetchTxSessionClose($date, 'TX_NIGHT', '台指期夜盤收盤') ? 1 : 0;
        } elseif (!$noTx) {
            try {
                $response = Http::timeout(10)
                    ->post('https://mis.taifex.com.tw/futures/api/getQuoteList', [
                        'CID' => '',
                        'SymID' => 'TX',
                        'MarketType' => 0,
                        'PageNo' => 1,
                        'PageSize' => 10,
                    ]);

                if ($response->successful()) {
                    $quotes = $response->json('RtData.QuoteList', []);
                    // 取近月合約（第二筆，第一筆是現貨）
                    $futures = $quotes[1] ?? null;
                    $close = $futures ? (float) ($futures['CLastPrice'] ?? 0) : 0;
                    $prevClose = $futures ? (float) ($futures['CRefPrice'] ?? 0) : 0;

                    if ($close > 0 && $prevClose > 0) {
                        $changePct = round(($close - $prevClose) / $prevClose * 100, 2);

                        UsMarketIndex::updateOrCreate(
                            ['date' => $date, 'symbol' => 'TX'],
                            [
                                'name' => '台指期',
                                'close' => $close,
                                'prev_close' => $prevClose,
                                'change_percent' => $changePct,
                            ]
                        );

                        $sign = $changePct >= 0 ? '+' : '';
                        $this->info("  台指期: {$close} ({$sign}{$changePct}%)");
                        $count++;
                    } else {
                        // 不寫入無效值，避免下游（簡報、MarketContext）讀到 0/-100% 誤判
                        $this->warn("  台指期: CLast={$close} CRef={$prevClose} 無效值，跳過寫入（保留既有 row）");
                        Log::info("FetchUsIndices TX: CLast={$close} CRef={$prevClose}，跳過寫入");
                    }
                }
            } catch (\Exception $e) {
                Log::error("FetchUsIndices TX: " . $e->getMessage());
                $this->error("  台指期: " . $e->getMessage());
            }
        }

        // 組合通知訊息
        $label = match (true) {
            $txDayClose   => '台指期日盤收盤',
            $txNightClose => '台指期夜盤收盤',
            $txOnly       => '台指期日盤更新',
            default       => '美股指數抓取',
        };
        $relevantSymbols = match (true) {
            $txDayClose   => ['TX_DAY'],
            $txNightClose => ['TX_NIGHT'],
            $txOnly       => ['TX'],
            default       => ['^GSPC', '^SOX', '^DJI', '^IXIC', 'DX-Y.NYB', '^VIX', 'TX'],
        };
        $details = UsMarketIndex::where('date', $date)
            ->whereIn('symbol', $relevantSymbols)
            ->get()
            ->map(fn ($idx) => sprintf('%s %s%.2f%%', $idx->name, $idx->change_percent >= 0 ? '+' : '', $idx->change_percent))
            ->implode(' | ');

        app(TelegramService::class)->broadcast(
            "✅ *{$label}* 完成\n📅 {$date} | 共 {$count} 筆\n{$details}",
            'system'
        );

        $this->info("完成，共抓取 {$count} 筆指數");
        return self::SUCCESS;
    }

    /**
     * 抓 TX session 收盤（日盤 14:00 / 夜盤 05:01 用）
     *
     * 設計：抓 CLastPrice（剛收盤的最後一筆 = session 收盤）+ 從 DB 撈最近一筆 TX_DAY（= T-1 日盤收）
     * 算 change_percent。**不信任 API 的 CRefPrice**——它在 session 間切換時點不可靠
     * （5/29 06:00 觀察到 CRef 仍是 5/27 收 44794，而非預期的 5/28 收 43839）。
     *
     * @param string $symbol  'TX_DAY' or 'TX_NIGHT'
     */
    private function fetchTxSessionClose(string $date, string $symbol, string $name): bool
    {
        try {
            $response = Http::timeout(10)
                ->post('https://mis.taifex.com.tw/futures/api/getQuoteList', [
                    'CID' => '', 'SymID' => 'TX', 'MarketType' => 0,
                    'PageNo' => 1, 'PageSize' => 10,
                ]);

            if (!$response->successful()) {
                $this->error("  {$name} API HTTP {$response->status()}");
                Log::warning("FetchUsIndices {$symbol} HTTP {$response->status()}");
                return false;
            }

            $quotes = $response->json('RtData.QuoteList', []);
            $futures = $quotes[1] ?? null;
            $close = $futures ? (float) ($futures['CLastPrice'] ?? 0) : 0;

            if ($close <= 0) {
                $this->warn("  {$name}: CLast={$close} 無效，跳過寫入");
                Log::info("FetchUsIndices {$symbol}: CLast={$close}，跳過");
                return false;
            }

            // 基準 = 最近一筆 TX_DAY（= T-1 或更早的日盤收盤）
            //   TX_DAY 由 14:00 排程每日寫入
            //   TX_NIGHT 算 vs 最近 TX_DAY（即 T-1 日盤收）= 真實「夜盤漲跌」
            //   TX_DAY 算 vs 上一筆 TX_DAY（即昨日日盤收）= 日盤漲跌
            $prevDay = UsMarketIndex::where('symbol', 'TX_DAY')
                ->where('date', '<', $date)
                ->orderByDesc('date')
                ->first();
            $prevClose = $prevDay ? (float) $prevDay->close : null;

            if (!$prevClose || $prevClose <= 0) {
                $this->warn("  {$name}: 找不到前一筆 TX_DAY 當基準，change% 暫設 0；寫入後續可手動回填");
                $changePct = 0;
            } else {
                $changePct = round(($close - $prevClose) / $prevClose * 100, 2);
            }

            UsMarketIndex::updateOrCreate(
                ['date' => $date, 'symbol' => $symbol],
                [
                    'name' => $name,
                    'close' => $close,
                    'prev_close' => $prevClose,
                    'change_percent' => $changePct,
                ]
            );

            $sign = $changePct >= 0 ? '+' : '';
            $this->info("  {$name}: {$close} ({$sign}{$changePct}% vs TX_DAY {$prevClose})");
            return true;
        } catch (\Exception $e) {
            Log::error("FetchUsIndices {$symbol}: " . $e->getMessage());
            $this->error("  {$name}: " . $e->getMessage());
            return false;
        }
    }
}

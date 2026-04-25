<?php

namespace App\Console\Commands;

use App\Models\UsMarketIndex;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchUsIndices extends Command
{
    protected $signature = 'stock:fetch-us-indices {date?} {--tx-only : 僅更新台指期，略過美股指數}';
    protected $description = '抓取台指期夜盤 + 美股主要指數收盤數據';

    private const INDICES = [
        '^GSPC'    => 'S&P 500',
        '^SOX'     => '費半',
        '^DJI'     => '道瓊',
        '^IXIC'    => '那斯達克',
        'DX-Y.NYB' => '美元指數',
    ];

    public function handle(): int
    {
        $date = $this->argument('date') ?? now()->format('Y-m-d');
        $count = 0;
        $txOnly = $this->option('tx-only');

        foreach (self::INDICES as $symbol => $name) {
            if ($txOnly) {
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
                if ($futures && $futures['CLastPrice']) {
                    $close = (float) $futures['CLastPrice'];
                    $prevClose = (float) $futures['CRefPrice'];
                    $changePct = $prevClose > 0
                        ? round(($close - $prevClose) / $prevClose * 100, 2)
                        : 0;

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
                }
            }
        } catch (\Exception $e) {
            Log::error("FetchUsIndices TX: " . $e->getMessage());
            $this->error("  台指期: " . $e->getMessage());
        }

        // 組合通知訊息
        $label = $txOnly ? '台指期日盤更新' : '美股指數抓取';
        $details = UsMarketIndex::where('date', $date)
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
}

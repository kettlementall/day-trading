<?php

namespace App\Console\Commands;

use App\Models\UsMarketIndex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchUsIndices extends Command
{
    protected $signature = 'stock:fetch-us-indices {date?}';
    protected $description = '抓取美股主要指數收盤數據（S&P 500、費半、道瓊、那斯達克、美元指數）';

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

        foreach (self::INDICES as $symbol => $name) {
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

        $this->info("完成，共抓取 {$count} 筆美股指數");
        return self::SUCCESS;
    }
}

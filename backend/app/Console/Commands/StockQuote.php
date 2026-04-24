<?php

namespace App\Console\Commands;

use App\Services\FugleRealtimeClient;
use Illuminate\Console\Command;

class StockQuote extends Command
{
    protected $signature = 'stock:quote {symbol} {cost?}';
    protected $description = '即時查詢個股報價與5分K走勢';

    public function __construct(private FugleRealtimeClient $fugle)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $symbol = $this->argument('symbol');
        $cost   = $this->argument('cost') ? (float) $this->argument('cost') : null;

        // ── 抓取即時報價 ──
        $quote = $this->fugle->fetchRawQuote($symbol);
        if (!$quote || empty($quote['symbol'])) {
            $this->error("無法取得 {$symbol} 報價");
            return self::FAILURE;
        }

        // ── 抓取 5 分 K ──
        usleep(150000); // 同 FugleRealtimeClient REQUEST_DELAY
        $candles = $this->fugle->fetchRawCandles($symbol);

        // ── 顯示 ──
        $this->displayQuote($quote, $cost);
        $this->displayCandles($candles, (float) ($quote['referencePrice'] ?? 0));

        return self::SUCCESS;
    }

    private function displayQuote(array $q, ?float $cost): void
    {
        $name      = $q['name'] ?? '';
        $symbol    = $q['symbol'];
        $prevClose = (float) ($q['referencePrice'] ?? 0);
        $open      = (float) ($q['openPrice'] ?? 0);
        $high      = (float) ($q['highPrice'] ?? 0);
        $low       = (float) ($q['lowPrice'] ?? 0);
        $close     = (float) ($q['closePrice'] ?? 0);
        $total     = $q['total'] ?? [];
        $volume    = (int) ($total['tradeVolume'] ?? 0);
        $volAtAsk  = (int) ($total['tradeVolumeAtAsk'] ?? 0);
        $volAtBid  = (int) ($total['tradeVolumeAtBid'] ?? 0);
        $txn       = (int) ($total['transaction'] ?? 0);

        $changePct = $prevClose > 0 ? ($close - $prevClose) / $prevClose * 100 : 0;
        $extRatio  = ($volAtAsk + $volAtBid) > 0
            ? $volAtAsk / ($volAtAsk + $volAtBid) * 100 : 50;

        $this->newLine();
        $this->info("═══ {$symbol} {$name} ═══");
        $this->newLine();

        $this->table(
            ['項目', '數值'],
            [
                ['昨收', number_format($prevClose, 2)],
                ['開盤', number_format($open, 2)],
                ['最高', number_format($high, 2)],
                ['最低', number_format($low, 2)],
                ['現價', number_format($close, 2)],
                ['漲跌%', sprintf('%+.2f%%', $changePct)],
                ['成交量', number_format($volume) . ' 張'],
                ['成交筆', number_format($txn)],
                ['外盤比', sprintf('%.1f%%', $extRatio)],
            ]
        );

        // 五檔
        $bids = $q['bids'] ?? [];
        $asks = $q['asks'] ?? [];
        $fiveRows = [];
        for ($i = 0; $i < 5; $i++) {
            $askP = isset($asks[$i]) ? number_format($asks[$i]['price'], 2) : '-';
            $askS = isset($asks[$i]) ? number_format($asks[$i]['size']) : '-';
            $bidP = isset($bids[$i]) ? number_format($bids[$i]['price'], 2) : '-';
            $bidS = isset($bids[$i]) ? number_format($bids[$i]['size']) : '-';
            $fiveRows[] = [$askP, $askS, $bidP, $bidS];
        }
        $this->table(['賣價', '賣量', '買價', '買量'], $fiveRows);

        if ($cost !== null) {
            $pnlPct = $cost > 0 ? ($close - $cost) / $cost * 100 : 0;
            $label  = $pnlPct >= 0 ? '獲利' : '虧損';
            $this->info(sprintf("成本 %.2f → 帳面%s %+.2f%%", $cost, $label, $pnlPct));
            $this->newLine();
        }
    }

    private function displayCandles(?array $data, float $prevClose): void
    {
        if (!$data || empty($data['data'])) {
            $this->warn('無 5 分 K 資料');
            return;
        }

        $rows = [];
        foreach ($data['data'] as $c) {
            $time  = substr($c['date'] ?? '', 11, 5);
            $open  = (float) ($c['open'] ?? 0);
            $high  = (float) ($c['high'] ?? 0);
            $low   = (float) ($c['low'] ?? 0);
            $close = (float) ($c['close'] ?? 0);
            $vol   = (int) ($c['volume'] ?? 0);
            $pct   = $prevClose > 0 ? sprintf('%+.2f%%', ($close - $prevClose) / $prevClose * 100) : '-';

            $rows[] = [
                $time,
                number_format($open, 2),
                number_format($high, 2),
                number_format($low, 2),
                number_format($close, 2),
                number_format($vol),
                $pct,
            ];
        }

        $this->info('── 5 分 K 走勢 ──');
        $this->table(['時間', '開', '高', '低', '收', '量(張)', '漲跌%'], $rows);
    }
}

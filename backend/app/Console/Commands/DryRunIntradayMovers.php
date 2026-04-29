<?php

namespace App\Console\Commands;

use App\Models\DailyQuote;
use App\Services\IntradayMoverService;
use Illuminate\Console\Command;

class DryRunIntradayMovers extends Command
{
    protected $signature = 'stock:dry-run-movers
        {--date= : 模擬日期（預設：今天）}
        {--watch= : 觀察特定股票代號是否在池內（逗號分隔）}';

    protected $description = '腿 2 盤中加入 dry-run：驗證選池邏輯 + 用日 K 模擬規則過濾（不打 Fugle API）';

    public function handle(IntradayMoverService $service): int
    {
        $tradeDate = $this->option('date') ?? now()->format('Y-m-d');
        $watch = collect(explode(',', $this->option('watch') ?? ''))
            ->map(fn($s) => trim($s))
            ->filter()
            ->values()
            ->all();

        $this->info("== 盤中加入 dry-run（{$tradeDate}）==");

        // 1. 選池
        $pool = $service->selectScanPool($tradeDate);
        $this->info("待掃描池: {$pool->count()} 檔");

        // 2. 用前日收盤資料模擬「即時報價」做規則過濾
        $prevDate = DailyQuote::where('date', '<', $tradeDate)
            ->orderByDesc('date')
            ->value('date');

        if (!$prevDate) {
            $this->error("找不到 {$tradeDate} 之前的交易日");
            return self::FAILURE;
        }

        $this->line("模擬日期: {$prevDate} 收盤資料當「盤中報價」");

        // 組裝假 quotes（用日 K 當天收盤當 current_price）
        $fakeQuotes = [];
        $todayQuotes = DailyQuote::with('stock')
            ->where('date', $prevDate)
            ->whereIn('stock_id', $pool->pluck('id'))
            ->get();

        // 取前前日收盤當 prev_close
        $prevPrevDate = DailyQuote::where('date', '<', $prevDate)
            ->orderByDesc('date')
            ->value('date');

        foreach ($todayQuotes as $dq) {
            $symbol = $dq->stock->symbol ?? '';
            if (!$symbol) continue;

            $prevPrevClose = $prevPrevDate
                ? (float) DailyQuote::where('stock_id', $dq->stock_id)
                    ->where('date', $prevPrevDate)->value('close')
                : (float) $dq->close;

            $fakeQuotes[$symbol] = [
                'symbol'              => $symbol,
                'current_price'       => (float) $dq->close,
                'prev_close'          => $prevPrevClose,
                'open'                => (float) $dq->open,
                'high'                => (float) $dq->high,
                'low'                 => (float) $dq->low,
                'accumulated_volume'  => (int) $dq->volume,
                'trade_volume_at_ask' => (int) ($dq->volume * 0.55), // 模擬 55% 外盤
                'trade_volume_at_bid' => (int) ($dq->volume * 0.45),
            ];
        }

        // 規則過濾
        $thresholds = \App\Models\FormulaSetting::getConfig('intraday_mover_thresholds') ?: [];
        $survivors = $service->filterByLiveQuote($pool, $fakeQuotes, $thresholds);
        $this->info("通過規則過濾: {$survivors->count()} 檔");

        // 顯示通過的標的
        if ($survivors->isNotEmpty()) {
            $rows = $survivors->map(function ($stock) use ($fakeQuotes) {
                $q = $fakeQuotes[$stock->symbol] ?? [];
                $prev = (float) ($q['prev_close'] ?? 0);
                $cur = (float) ($q['current_price'] ?? 0);
                $chg = $prev > 0 ? round(($cur - $prev) / $prev * 100, 2) : 0;
                return [
                    $stock->symbol,
                    $stock->name,
                    $stock->industry ?? '-',
                    sprintf('%.2f', $cur),
                    sprintf('%+.2f%%', $chg),
                    sprintf('%.0fk', ($q['accumulated_volume'] ?? 0) / 1000),
                ];
            });
            $this->table(['代號', '名稱', '類股', '收盤', '漲跌', '量'], $rows->toArray());
        }

        // 3. 覆蓋率：該日真實漲幅 ≥5% 的標的中，幾檔在池內
        $bigMovers = DailyQuote::with('stock')
            ->where('date', $prevDate)
            ->where('change_percent', '>=', 5.0)
            ->get();

        $poolSymbols = $pool->pluck('symbol')->toArray();
        $covered = $bigMovers->filter(fn($dq) => in_array($dq->stock->symbol ?? '', $poolSymbols));

        $this->newLine();
        $this->info("== 覆蓋率分析（{$prevDate} 漲幅 ≥5% 的標的）==");
        $this->line("實際爆漲: {$bigMovers->count()} 檔");
        $this->line("池內覆蓋: {$covered->count()} 檔");
        $pct = $bigMovers->count() > 0 ? round($covered->count() / $bigMovers->count() * 100, 1) : 0;
        $this->info("覆蓋率: {$pct}%");

        if ($bigMovers->isNotEmpty()) {
            $rows = $bigMovers->map(function ($dq) use ($poolSymbols) {
                $sym = $dq->stock->symbol ?? '';
                $inPool = in_array($sym, $poolSymbols);
                return [
                    $sym,
                    $dq->stock->name ?? '',
                    sprintf('%+.2f%%', (float) $dq->change_percent),
                    sprintf('%.0fk', $dq->volume / 1000),
                    $inPool ? '✅' : '❌',
                ];
            })->sortByDesc(fn($r) => $r[2]);
            $this->table(['代號', '名稱', '漲幅', '量', '池內'], $rows->values()->toArray());
        }

        // 4. 觀察名單
        if (!empty($watch)) {
            $this->newLine();
            $this->info("== 觀察名單 ==");
            foreach ($watch as $sym) {
                $inPool = in_array($sym, $poolSymbols);
                $inSurvivor = $survivors->contains(fn($s) => $s->symbol === $sym);
                $this->line(sprintf(
                    "  %s: 池內=%s 規則通過=%s",
                    $sym,
                    $inPool ? '✅' : '❌',
                    $inSurvivor ? '✅' : '❌'
                ));
            }
        }

        return self::SUCCESS;
    }
}

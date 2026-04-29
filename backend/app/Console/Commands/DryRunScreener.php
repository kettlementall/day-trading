<?php

namespace App\Console\Commands;

use App\Models\DailyQuote;
use App\Models\FormulaSetting;
use App\Models\InstitutionalTrade;
use App\Models\MarginTrade;
use App\Models\Stock;
use App\Services\TechnicalIndicator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class DryRunScreener extends Command
{
    protected $signature = 'stock:dry-run-screener
        {--date= : 模擬此日期的選股（預設：下一個交易日）}
        {--watch= : 觀察特定股票代號排名變化（逗號分隔，例如 1597,2049,1590）}
        {--top=20 : 顯示前 N 名（預設 20）}
        {--max=100 : top 名單截斷數（預設 100，含核心保底 20）}';

    protected $description = '物理門檻通過的標的同時用「5 日均量」與「當沖複合分數」排序，輸出對比表，不寫入 DB';

    /**
     * 當沖複合分數權重（振幅 > 流動性 > 日內模式/籌碼 > 動能/突破，搭配負分機制）
     */
    private const W_AMPLITUDE = 0.35;  // 當沖核心：沒振幅就沒手續費覆蓋
    private const W_LIQUIDITY = 0.20;  // 流動性夠就好（log 飽和）
    private const W_PATTERN   = 0.15;  // 日內活躍度（近 10 日 ≥5% 振幅天數）
    private const W_CHIPS     = 0.15;
    private const W_MOMENTUM  = 0.10;  // 動能（當沖弱化）
    private const W_BREAKOUT  = 0.05;  // 突破（當沖弱化，避免追高）

    // 核心保底已移除：複合分數本身就是「值不值得當沖」的判斷，
    // 保底只會把平盤大型股（如平盤日的 2330 台積電）強塞進池子，浪費 AI token。
    // 若大型股真有爆發訊號，由盤中動態加入（腿 2）抓即可。

    public function handle(): int
    {
        $tradeDate = $this->option('date') ?? now()->format('Y-m-d');
        $watch = collect(explode(',', $this->option('watch') ?? ''))
            ->map(fn ($s) => trim($s))
            ->filter()
            ->values()
            ->all();
        $top = (int) $this->option('top');
        $max = (int) $this->option('max');

        $screenConfig = FormulaSetting::getConfig('screen_thresholds') ?: [];
        $minVolume = $screenConfig['min_volume'] ?? 500;
        $minPrice  = $screenConfig['min_price'] ?? 10;
        $minAmp    = $screenConfig['min_amplitude'] ?? 2.5;
        $minVol5   = $screenConfig['min_day_trading_volume'] ?? 200;

        $this->info("== 物理門檻 dry-run（{$tradeDate}）==");
        $this->line("硬門檻: 量≥{$minVolume}張、價≥{$minPrice}、5日均振幅≥{$minAmp}%、5日均量≥{$minVol5}張");

        $stocks = Stock::where('is_day_trading', true)->get();
        $this->line("掃描 {$stocks->count()} 檔");

        $rows = [];
        $bar = $this->output->createProgressBar($stocks->count());
        $bar->start();

        foreach ($stocks as $stock) {
            $bar->advance();

            $quotes = DailyQuote::where('stock_id', $stock->id)
                ->where('date', '<', $tradeDate)
                ->orderByDesc('date')
                ->limit(20)
                ->get();
            if ($quotes->count() < 20) continue;

            $closes  = $quotes->pluck('close')->map(fn($v) => (float)$v)->toArray();
            $highs   = $quotes->pluck('high')->map(fn($v) => (float)$v)->toArray();
            $volumes = $quotes->pluck('volume')->toArray();
            $changes = $quotes->pluck('change_percent')->map(fn($v) => (float)$v)->toArray();
            $amps    = $quotes->pluck('amplitude')->map(fn($v) => (float)$v)->toArray();

            // 物理門檻（與 StockScreener intraday 模式一致）
            if (($volumes[0] / 1000) < $minVolume) continue;
            if ($closes[0] < $minPrice) continue;
            $avgAmp5 = array_sum(array_slice($amps, 0, 5)) / 5;
            if ($avgAmp5 < $minAmp) continue;
            $avgVol5Lots = array_sum(array_slice($volumes, 0, 5)) / 5 / 1000;
            if ($avgVol5Lots < $minVol5) continue;

            // 籌碼（取最近 2 日法人 + 1 日融資）
            $inst = InstitutionalTrade::where('stock_id', $stock->id)
                ->orderByDesc('date')->limit(2)->get();
            $margin = MarginTrade::where('stock_id', $stock->id)
                ->orderByDesc('date')->limit(1)->first();

            // 子分數
            $liq = $this->liquidityScore($avgVol5Lots);
            $amp = $this->amplitudeScore($avgAmp5);
            $pat = $this->intradayPatternScore($amps);
            $mom = $this->momentumScore($changes);
            $brk = $this->breakoutScore($closes, $highs, $volumes);
            $chp = $this->chipsScore($inst, $margin);
            $pen = $this->penaltyScore($changes, $amps);

            $base = $amp * self::W_AMPLITUDE
                  + $liq * self::W_LIQUIDITY
                  + $pat * self::W_PATTERN
                  + $chp * self::W_CHIPS
                  + $mom * self::W_MOMENTUM
                  + $brk * self::W_BREAKOUT;
            $compound = $base - $pen;

            $rows[] = [
                'symbol'    => $stock->symbol,
                'name'      => $stock->name,
                'industry'  => $stock->industry ?: '-',
                'avg_vol5'  => $avgVol5Lots,
                'avg_amp5'  => $avgAmp5,
                'prev_chg'  => $changes[0] ?? 0,
                'compound'  => $compound,
                'amp'       => $amp,
                'liq'       => $liq,
                'pat'       => $pat,
                'chp'       => $chp,
                'mom'       => $mom,
                'brk'       => $brk,
                'pen'       => $pen,
            ];
        }

        $bar->finish();
        $this->newLine(2);

        $allRows = collect($rows);
        $byVol      = $allRows->sortByDesc('avg_vol5')->values();
        $byCompound = $allRows->sortByDesc('compound')->values();

        $this->info("通過物理門檻：{$byVol->count()} 檔");
        $this->newLine();

        $this->showTop($byVol, $byCompound, $top);
        $this->showNewEntries($byVol, $byCompound, $max);

        if (!empty($watch)) {
            $this->showWatchlist($byVol, $byCompound, $watch);
        }

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // 子分數
    // -------------------------------------------------------------------------

    private function liquidityScore(float $avgVol5Lots): float
    {
        if ($avgVol5Lots <= 1) return 0.0;
        return max(0.0, min(100.0, log10($avgVol5Lots) * 25.0));
    }

    private function amplitudeScore(float $avgAmp5): float
    {
        return max(0.0, min(100.0, $avgAmp5 * 20.0));
    }

    /**
     * 日內活躍度：近 10 日內單日振幅 ≥ 5% 出現次數 × 20
     * 比 5 日均振幅更精準——區分「3 天 5% + 2 天 5%」這種真當沖標的，
     * vs「平均 2% 但每天都小震」的死水股
     */
    private function intradayPatternScore(array $amps): float
    {
        $window = array_slice($amps, 0, 10);
        $bigSwings = count(array_filter($window, fn ($a) => $a >= 5.0));
        return min(100.0, $bigSwings * 20.0);
    }

    /**
     * 動能：前日漲幅 + 近 3 日累計漲幅 bonus
     */
    private function momentumScore(array $changes): float
    {
        $prev = max(0.0, $changes[0] ?? 0);
        $base = min(100.0, $prev * 15.0);

        $recent3 = array_slice($changes, 0, 3);
        $cum = max(0.0, array_sum($recent3));
        $bonus = min(20.0, $cum * 2.0);

        return min(100.0, $base + $bonus);
    }

    /**
     * 突破：突破前 5 日最高 + 爆量 + 站上短均
     */
    private function breakoutScore(array $closes, array $highs, array $volumes): float
    {
        $score = 0.0;

        $prev5High = count($highs) >= 6 ? max(array_slice($highs, 1, 5)) : 0;
        if ($prev5High > 0 && $closes[0] > $prev5High) {
            $score += 50;
        }

        $avgVol5 = array_sum(array_slice($volumes, 0, 5)) / 5;
        if ($avgVol5 > 0 && $volumes[0] > $avgVol5 * 1.5) {
            $score += 30;
        }

        $ma5 = TechnicalIndicator::sma($closes, 5);
        $ma10 = TechnicalIndicator::sma($closes, 10);
        if ($ma5 && $ma10 && $closes[0] > $ma5 && $ma5 > $ma10) {
            $score += 20;
        }

        return min(100.0, $score);
    }

    /**
     * 籌碼：法人買超 + 連買 + 融資減 + 融券增
     */
    private function chipsScore($inst, $margin): float
    {
        $score = 0.0;

        if ($inst->isNotEmpty()) {
            $latest = $inst->first();
            $netLatest = (float)$latest->foreign_net + (float)$latest->trust_net;
            if ($netLatest > 0) $score += 40;

            if ($inst->count() >= 2) {
                $prev = $inst->get(1);
                $foreignTwoUp = (float)$latest->foreign_net > 0 && (float)$prev->foreign_net > 0;
                $trustTwoUp   = (float)$latest->trust_net   > 0 && (float)$prev->trust_net   > 0;
                if ($foreignTwoUp || $trustTwoUp) $score += 30;
            }
        }

        if ($margin) {
            if ((float)$margin->margin_change < 0) $score += 20;
            if ((float)$margin->short_change > 0)  $score += 10;
        }

        return min(100.0, $score);
    }

    /**
     * 負分機制（當沖視角）：扣分以降低排名
     * - 前日漲停 -25：今日大機率跳空，買不到
     * - 連漲 ≥3 日且累計 ≥15% -20：過熱反轉風險（對齊 AiLesson）
     * - 近 5 日有跌停 -15：主力倒貨痕跡
     * - 近 3 日累計跌 >8% -10：弱勢，不適合做多當沖
     */
    private function penaltyScore(array $changes, array $amps): float
    {
        $penalty = 0.0;

        $prev = $changes[0] ?? 0;
        if ($prev >= 9.8) $penalty += 25;

        $recent3 = array_slice($changes, 0, 3);
        $upStreak = count($recent3) >= 3
            && $recent3[0] > 0 && $recent3[1] > 0 && $recent3[2] > 0;
        if ($upStreak && array_sum($recent3) >= 15.0) {
            $penalty += 20;
        }

        $window5 = array_slice($changes, 0, 5);
        if (count(array_filter($window5, fn ($c) => $c <= -9.8)) > 0) {
            $penalty += 15;
        }

        if (array_sum($recent3) <= -8.0) {
            $penalty += 10;
        }

        return $penalty;
    }

    // -------------------------------------------------------------------------
    // 輸出
    // -------------------------------------------------------------------------

    private function showTop($byVol, $byCompound, int $top): void
    {
        $rows = [];
        for ($i = 0; $i < $top; $i++) {
            $a = $byVol[$i]      ?? null;
            $b = $byCompound[$i] ?? null;
            $rows[] = [
                $i + 1,
                $a ? sprintf('%s %s (%.0fk張 %+.2f%%)', $a['symbol'], $a['name'], $a['avg_vol5'], $a['prev_chg']) : '',
                $b ? sprintf(
                    '%s %s c=%.1f A%d/L%d/P%d/C%d/M%d/B%d%s',
                    $b['symbol'],
                    $b['name'],
                    $b['compound'],
                    $b['amp'], $b['liq'], $b['pat'], $b['chp'], $b['mom'], $b['brk'],
                    $b['pen'] > 0 ? sprintf(' -P%d', $b['pen']) : ''
                ) : '',
            ];
        }
        $this->table(
            ['#', '5日均量榜', '當沖複合分數榜（A=振幅 L=流動性 P=日內模式 C=籌碼 M=動能 B=突破 -P=負分）'],
            $rows
        );
    }

    private function showNewEntries($byVol, $byCompound, int $max): void
    {
        $volTopSymbols      = $byVol->take($max)->pluck('symbol')->all();
        $compoundTopSymbols = $byCompound->take($max)->pluck('symbol')->all();

        $newcomers = $byCompound->take($max)
            ->filter(fn ($r) => !in_array($r['symbol'], $volTopSymbols))
            ->values();

        $dropped = $byVol->take($max)
            ->filter(fn ($r) => !in_array($r['symbol'], $compoundTopSymbols))
            ->values();

        $this->newLine();
        $this->info("== 切換到當沖複合分數後，top {$max} 名單變動 ==");
        $this->line("新進 {$newcomers->count()} 檔（複合排名前 {$max}、現行 5日均量榜外）");
        if ($newcomers->isNotEmpty()) {
            $rows = $newcomers->take(30)->map(function ($r) use ($byVol) {
                $volRank = $byVol->search(fn ($x) => $x['symbol'] === $r['symbol']);
                $volRank = $volRank === false ? '-' : $volRank + 1;
                return [
                    $r['symbol'],
                    $r['name'],
                    $r['industry'],
                    sprintf('%.1f', $r['compound']),
                    sprintf('%.1f%%', $r['avg_amp5']),
                    $volRank,
                    sprintf('%+.2f%%', $r['prev_chg']),
                    sprintf('%.0fk', $r['avg_vol5']),
                    $r['pen'] > 0 ? "-{$r['pen']}" : '',
                ];
            });
            $this->table(['代號', '名稱', '類股', '複合', '5日振幅', '原排名', '前日漲', '5日均量', '負分'], $rows);
        }

        $this->line("被擠掉 {$dropped->count()} 檔（現行 top {$max}、複合排名外）");
        if ($dropped->isNotEmpty()) {
            $rows = $dropped->take(20)->map(function ($r) use ($byCompound) {
                $cmpRank = $byCompound->search(fn ($x) => $x['symbol'] === $r['symbol']);
                $cmpRank = $cmpRank === false ? '-' : $cmpRank + 1;
                return [
                    $r['symbol'],
                    $r['name'],
                    $r['industry'],
                    sprintf('%.1f', $r['compound']),
                    sprintf('%.1f%%', $r['avg_amp5']),
                    $cmpRank,
                    sprintf('%+.2f%%', $r['prev_chg']),
                    sprintf('%.0fk', $r['avg_vol5']),
                ];
            });
            $this->table(['代號', '名稱', '類股', '複合', '5日振幅', '複合排名', '前日漲', '5日均量'], $rows);
        }
    }

    private function showWatchlist($byVol, $byCompound, array $watch): void
    {
        $this->newLine();
        $this->info("== 觀察名單排名變化 ==");
        $rows = [];
        foreach ($watch as $sym) {
            $volIdx = $byVol->search(fn ($x) => $x['symbol'] === $sym);
            $cmpIdx = $byCompound->search(fn ($x) => $x['symbol'] === $sym);
            $row = $byCompound->firstWhere('symbol', $sym) ?? $byVol->firstWhere('symbol', $sym);
            if (!$row) {
                $rows[] = [$sym, '— 未通過物理門檻 —', '', '', '', '', '', '', '', ''];
                continue;
            }
            $rows[] = [
                $sym,
                $row['name'],
                $row['industry'],
                $volIdx === false ? '未上榜' : $volIdx + 1,
                $cmpIdx === false ? '未上榜' : $cmpIdx + 1,
                sprintf('%.1f', $row['compound']),
                sprintf('A%d L%d P%d C%d M%d B%d', $row['amp'], $row['liq'], $row['pat'], $row['chp'], $row['mom'], $row['brk']),
                $row['pen'] > 0 ? "-{$row['pen']}" : '0',
                sprintf('%+.2f%%', $row['prev_chg']),
                sprintf('%.0fk', $row['avg_vol5']),
            ];
        }
        $this->table(
            ['代號', '名稱', '類股', '量榜排名', '複合排名', '複合分', '子分', '負分', '前日漲', '5日均量'],
            $rows
        );
    }
}

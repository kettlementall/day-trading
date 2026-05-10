<?php

namespace App\Console\Commands;

use App\Models\DailyQuote;
use App\Models\MarketHoliday;
use App\Models\Stock;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RefreshSwingUniverse extends Command
{
    protected $signature = 'stock:refresh-swing-universe';
    protected $description = '依流動性／價格／資料完整度／ETF 類型重算 stocks.is_swing_eligible';

    private const AVG_VOLUME_DAYS = 20;
    private const MIN_AVG_VOLUME_LOTS = 1000;
    private const MIN_CLOSE_PRICE = 10.0;
    private const DROPPED_ALERT_THRESHOLD = 10;

    private const EXCLUDED_KEYWORDS = [
        '期信', 'VIX', '原油', '黃金', '債券',
        '槓桿', '反向', '正2', '反1',
    ];

    public function handle(): int
    {
        if (MarketHoliday::isHoliday(now()->toDateString())) {
            $this->info(now()->toDateString() . ' 休市，跳過短線股票池重算');
            return self::SUCCESS;
        }

        $previousIds = Stock::where('is_swing_eligible', true)->pluck('id')->all();

        $passing = [];
        $individualCount = 0;
        $etfCount = 0;

        Stock::whereIn('market', ['twse', 'tpex'])->get()->each(function (Stock $stock) use (
            &$passing,
            &$individualCount,
            &$etfCount,
        ) {
            $isEtf = $this->looksLikeEtf($stock);

            foreach (self::EXCLUDED_KEYWORDS as $keyword) {
                if (mb_stripos($stock->name ?? '', $keyword) !== false) {
                    return;
                }
            }

            $recent = DailyQuote::where('stock_id', $stock->id)
                ->orderByDesc('date')
                ->limit(self::AVG_VOLUME_DAYS)
                ->get(['close', 'volume']);

            if ($recent->isEmpty()) {
                return;
            }

            $latestClose = (float) $recent->first()->close;
            if ($latestClose < self::MIN_CLOSE_PRICE) {
                return;
            }

            $avgVolumeShares = $recent->avg('volume');
            $avgVolumeLots = $avgVolumeShares / 1000;
            if ($avgVolumeLots < self::MIN_AVG_VOLUME_LOTS) {
                return;
            }

            $passing[] = $stock->id;
            if ($isEtf) {
                $etfCount++;
            } else {
                $individualCount++;
            }
        });

        DB::transaction(function () use ($passing) {
            Stock::query()->update(['is_swing_eligible' => false]);
            if (!empty($passing)) {
                Stock::whereIn('id', $passing)->update(['is_swing_eligible' => true]);
            }
        });

        $added = array_values(array_diff($passing, $previousIds));
        $dropped = array_values(array_diff($previousIds, $passing));
        $unchanged = array_values(array_intersect($previousIds, $passing));

        $dayTradingTotal = Stock::where('is_day_trading', true)->count();
        $dayTradingIds = Stock::where('is_day_trading', true)->pluck('id')->all();
        $onlySwing = count(array_diff($passing, $dayTradingIds));
        $onlyDay = count(array_diff($dayTradingIds, $passing));

        $lines = [
            '[swing-universe]',
            sprintf('  total_eligible      = %d（個股 %d / ETF %d）', count($passing), $individualCount, $etfCount),
            sprintf('  added_this_run      = %d', count($added)),
            sprintf('  dropped_this_run    = %d', count($dropped)),
            sprintf('  unchanged           = %d', count($unchanged)),
            sprintf('  day_trading_total   = %d', $dayTradingTotal),
            sprintf('  only_swing_eligible = %d', $onlySwing),
            sprintf('  only_day_trading    = %d', $onlyDay),
        ];

        foreach ($lines as $line) {
            $this->info($line);
        }

        if (count($dropped) >= self::DROPPED_ALERT_THRESHOLD) {
            app(TelegramService::class)->broadcast(
                "⚠️ *短線股票池量縮警示*\n本次重算掉出 " . count($dropped) . " 檔（門檻 " . self::DROPPED_ALERT_THRESHOLD . "）\n目前合格 " . count($passing) . " 檔",
                'system'
            );
        }

        return self::SUCCESS;
    }

    private function looksLikeEtf(Stock $stock): bool
    {
        $name = $stock->name ?? '';
        if (mb_stripos($name, 'ETF') !== false) {
            return true;
        }
        if (mb_stripos($name, '指數') !== false || mb_stripos($name, '基金') !== false) {
            return true;
        }
        if (preg_match('/^00\d{3,4}/', $stock->symbol)) {
            return true;
        }
        return false;
    }
}

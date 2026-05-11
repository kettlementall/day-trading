<?php

namespace App\Console\Commands;

use App\Models\DailyQuote;
use App\Models\Stock;
use App\Services\FugleRealtimeClient;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 從 TWSE MI_INDEX 拉全部 ETF（>4 碼或名稱含 ETF）寫進 stocks，
 * 然後用 Fugle Historical Candles 補 80 天歷史日 K，
 * 讓短線 universe 重算（refresh-swing-universe）能把它們納入。
 *
 * Idempotent：既有 ETF 也會檢查 DailyQuote 是否足夠，不夠就補抓。
 */
class ImportEtfs extends Command
{
    protected $signature = 'stock:import-etfs {date? : TWSE 行情日期 yyyymmdd, 預設今日}';
    protected $description = '從 TWSE 拉 ETF 名單寫進 stocks 並用 Fugle 補歷史日 K';

    private const MIN_QUOTE_DAYS = 60;
    private const BACKFILL_DAYS = 80;

    public function __construct(private FugleRealtimeClient $fugle)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $date = $this->argument('date') ?? now()->format('Ymd');
        $this->info("從 TWSE 抓 {$date} ETF 名單…");

        $etfs = $this->fetchEtfList($date);
        if (empty($etfs)) {
            $this->warn('TWSE 沒回傳 ETF 名單（可能是休市日），請傳入交易日期再試。');
            return self::FAILURE;
        }

        $this->info('抓到 ' . count($etfs) . ' 檔 ETF，開始寫入 stocks…');

        $created = 0;
        $existed = 0;
        $needsBackfill = [];

        foreach ($etfs as [$symbol, $name]) {
            $stock = Stock::firstOrCreate(
                ['symbol' => $symbol],
                ['name' => $name, 'market' => 'twse', 'is_day_trading' => false],
            );
            if ($stock->wasRecentlyCreated) {
                $created++;
            } else {
                $existed++;
            }

            // 即使已存在，若日 K 不足也納入 backfill
            $count = DailyQuote::where('stock_id', $stock->id)->count();
            if ($count < self::MIN_QUOTE_DAYS) {
                $needsBackfill[] = $stock;
            }
        }

        $this->info(sprintf('Stocks 寫入：new=%d, existed=%d，缺日 K：%d 檔', $created, $existed, count($needsBackfill)));

        if (empty($needsBackfill)) {
            $this->info('全部 ETF 都已有充足日 K，無需 backfill。');
            $this->notifyDone($created, $existed, 0, 0);
            return self::SUCCESS;
        }

        $this->info("開始 Fugle backfill 80 天日 K（共 " . count($needsBackfill) . ' 檔）…');
        $okBackfill = 0;
        $failBackfill = 0;
        $bar = $this->output->createProgressBar(count($needsBackfill));
        $bar->start();

        foreach ($needsBackfill as $stock) {
            $written = $this->backfillFromFugle($stock);
            if ($written > 0) {
                $okBackfill++;
            } else {
                $failBackfill++;
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        $this->info(sprintf('Backfill 完成：成功 %d / 失敗 %d', $okBackfill, $failBackfill));
        $this->info('建議接著跑 `php artisan stock:refresh-swing-universe` 把新 ETF 納入 is_swing_eligible。');

        $this->notifyDone($created, $existed, $okBackfill, $failBackfill);
        return self::SUCCESS;
    }

    /**
     * 從 TWSE MI_INDEX 抓 ETF：symbol 5+ 碼或名稱含 ETF / 指數 / 基金。
     *
     * @return array<int, array{0:string,1:string}>
     */
    private function fetchEtfList(string $date): array
    {
        $url = "https://www.twse.com.tw/exchangeReport/MI_INDEX?response=json&date={$date}&type=ALLBUT0999";
        $json = Http::timeout(30)
            ->withHeaders(['Accept-Language' => 'zh-TW'])
            ->get($url)
            ->json();

        if (($json['stat'] ?? '') !== 'OK') {
            Log::warning('ImportEtfs: TWSE stat=' . ($json['stat'] ?? 'unknown'));
            return [];
        }

        // 從 tables 找「每日收盤行情」
        $rows = [];
        foreach ($json['tables'] ?? [] as $table) {
            if (str_contains($table['title'] ?? '', '每日收盤行情')) {
                $rows = $table['data'] ?? [];
                break;
            }
        }

        $etfs = [];
        foreach ($rows as $row) {
            $symbol = trim($row[0] ?? '');
            $name = trim($row[1] ?? '');
            if ($symbol === '' || $name === '') continue;
            if (!preg_match('/^\d{4,6}[A-Z]?$/', $symbol)) continue;

            // ETF 判定：symbol 開頭 00 五碼以上、或名稱含 ETF / 指數 / 基金
            $isEtfBySymbol = preg_match('/^00\d{3,4}/', $symbol);
            $isEtfByName = mb_stripos($name, 'ETF') !== false
                || mb_stripos($name, '指數') !== false
                || mb_stripos($name, '基金') !== false;
            if (!$isEtfBySymbol && !$isEtfByName) continue;

            $etfs[] = [$symbol, $name];
        }
        return $etfs;
    }

    private function backfillFromFugle(Stock $stock): int
    {
        $candles = $this->fugle->fetchDailyCandles($stock->symbol, self::BACKFILL_DAYS);
        if (empty($candles)) {
            Log::info("ImportEtfs: Fugle returned empty for {$stock->symbol}");
            return 0;
        }

        $written = 0;
        foreach ($candles as $c) {
            if (empty($c['date']) || (float) ($c['close'] ?? 0) <= 0) continue;

            $close = (float) $c['close'];
            $high = (float) ($c['high'] ?? 0);
            $low = (float) ($c['low'] ?? 0);
            $changePercent = (float) ($c['change_percent'] ?? 0);
            $change = $changePercent !== 0.0
                ? round($close - ($close / (1 + ($changePercent / 100))), 2)
                : 0.0;
            $prevClose = $close - $change;
            $amplitude = $prevClose > 0 ? round(($high - $low) / $prevClose * 100, 2) : 0;

            DailyQuote::updateOrCreate(
                ['stock_id' => $stock->id, 'date' => $c['date']],
                [
                    'open' => (float) ($c['open'] ?? 0),
                    'high' => $high,
                    'low' => $low,
                    'close' => $close,
                    'volume' => (int) ($c['volume'] ?? 0),
                    'trade_value' => 0,
                    'trade_count' => 0,
                    'change' => $change,
                    'change_percent' => $changePercent,
                    'amplitude' => $amplitude,
                ],
            );
            $written++;
        }
        return $written;
    }

    private function notifyDone(int $created, int $existed, int $okBackfill, int $failBackfill): void
    {
        app(TelegramService::class)->broadcast(
            "✅ *ETF 匯入完成*\n新增 {$created} 檔 / 已存在 {$existed} 檔\nBackfill 成功 {$okBackfill} / 失敗 {$failBackfill}",
            'system'
        );
    }
}

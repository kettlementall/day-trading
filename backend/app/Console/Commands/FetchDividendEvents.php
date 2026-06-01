<?php

namespace App\Console\Commands;

use App\Models\DividendEvent;
use App\Models\Stock;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchDividendEvents extends Command
{
    protected $signature = 'stock:fetch-dividends';
    protected $description = '抓取 TWSE 除權息預告表（TWT48U），累積未來除權息行事曆';

    // 除權除息日當天就從預告表消失，故須每日抓取累積；此表只含「未來」事件。
    private const URL = 'https://www.twse.com.tw/exchangeReport/TWT48U?response=json';

    public function handle(): int
    {
        $this->info('抓取除權息預告表 (TWT48U)');

        try {
            $response = Http::timeout(30)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->get(self::URL);
            $json = $response->json();

            if (($json['stat'] ?? '') !== 'OK') {
                $this->warn('回傳非 OK: ' . ($json['stat'] ?? 'unknown'));
                return self::SUCCESS;
            }

            $rows = $json['data'] ?? [];
            $count = 0;
            $skipped = 0;

            foreach ($rows as $row) {
                $symbol = trim($row[1] ?? '');
                // 僅收普通股 4 碼；排除特別股/ETN 等含字母代號（避免污染選股池 stock_id）
                if (!preg_match('/^\d{4}$/', $symbol)) {
                    $skipped++;
                    continue;
                }

                $exDate = $this->parseExDate($row[8] ?? '', $row[0] ?? '');
                if (!$exDate) {
                    $skipped++;
                    continue;
                }

                // 不主動建立股票（除權息表含許多非選股池標的）；找不到就跳過
                $stock = Stock::where('symbol', $symbol)->first();
                if (!$stock) {
                    $skipped++;
                    continue;
                }

                DividendEvent::updateOrCreate(
                    ['stock_id' => $stock->id, 'ex_date' => $exDate],
                    [
                        'type' => trim($row[3] ?? '') ?: null,
                        'cash_dividend' => $this->parseNumber($row[7] ?? '0'),
                        'stock_ratio' => $this->parseNumber($row[4] ?? '0'),
                        'cash_capital_ratio' => $this->parseNumber($row[5] ?? '0'),
                        'cash_capital_price' => $this->parseNumber($row[6] ?? '0') ?: null,
                        'reference_price' => $this->parseNumber($row[9] ?? '0') ?: null,
                    ]
                );
                $count++;
            }

            app(TelegramService::class)->broadcast(
                "✅ *除權息行事曆* 更新\n📅 累積 {$count} 筆未來除權息（跳過 {$skipped}）",
                'system'
            );
            $this->info("除權息行事曆: 更新 {$count} 筆（跳過 {$skipped}）");
        } catch (\Exception $e) {
            Log::error('Dividend fetch error: ' . $e->getMessage());
            $this->error('抓取失敗: ' . $e->getMessage());
        }

        return self::SUCCESS;
    }

    /**
     * 解析除權息日。優先用欄位[8]內嵌的 AD 日期（"code,YYYYMMDD"），
     * 否則退回解析欄位[0]的民國日期（"115年06月02日"）。
     */
    private function parseExDate(string $detailCol, string $rocCol): ?string
    {
        if (preg_match('/,(\d{8})/', $detailCol, $m)) {
            return substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2);
        }
        if (preg_match('/(\d{2,3})年(\d{2})月(\d{2})日/', $rocCol, $m)) {
            return ((int) $m[1] + 1911) . '-' . $m[2] . '-' . $m[3];
        }
        return null;
    }

    /**
     * 安全解析數字：欄位可能是 "尚未公告"、HTML（"待公告..."）或帶逗號，非數字一律回 0。
     */
    private function parseNumber(string $value): float
    {
        $clean = str_replace([',', ' '], '', strip_tags($value));
        return is_numeric($clean) ? (float) $clean : 0.0;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\SectorIndex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchSectorIndices extends Command
{
    protected $signature = 'stock:fetch-sector-indices {date?}';
    protected $description = '抓取 TWSE 類股指數即時漲跌（12:25 執行，供隔日沖選股使用）';

    /**
     * TWSE 類股代號 → 系統 sector_name（對應 stocks.industry）
     * 代號參考：https://openapi.twse.com.tw/
     */
    private const SECTOR_MAP = [
        'IX0007' => '電子工業',
        'IX0008' => '金融保險',
        'IX0009' => '鋼鐵工業',
        'IX0010' => '橡膠工業',
        'IX0011' => '水泥工業',
        'IX0012' => '食品工業',
        'IX0013' => '塑膠工業',
        'IX0014' => '紡織纖維',
        'IX0015' => '電機機械',
        'IX0016' => '電器電纜',
        'IX0017' => '化學生技醫療',
        'IX0018' => '玻璃陶瓷',
        'IX0019' => '造紙工業',
        'IX0020' => '建材營造',
        'IX0021' => '航運業',
        'IX0022' => '觀光餐旅',
        'IX0023' => '其他',
        'IX0049' => '半導體業',
        'IX0050' => '電腦及週邊設備業',
        'IX0051' => '光電業',
        'IX0052' => '通信網路業',
        'IX0053' => '電子零組件業',
        'IX0054' => '電子通路業',
        'IX0055' => '資訊服務業',
        'IX0056' => '其他電子業',
    ];

    public function handle(): int
    {
        $date = $this->argument('date') ?? now()->format('Y-m-d');
        $this->info("抓取類股指數：{$date}");

        try {
            $data = $this->fetchFromTwse();
        } catch (\Exception $e) {
            $this->error('TWSE API 失敗：' . $e->getMessage());
            Log::error('FetchSectorIndices TWSE API 失敗：' . $e->getMessage());
            return self::FAILURE;
        }

        $saved = 0;
        foreach ($data as $item) {
            $code = $item['Index'] ?? $item['code'] ?? '';
            if (!isset(self::SECTOR_MAP[$code])) {
                continue;
            }

            $sectorName   = self::SECTOR_MAP[$code];
            $indexValue   = $this->parseFloat($item['SI'] ?? $item['index'] ?? 0);
            $changeStr    = $item['CHG'] ?? $item['change'] ?? '0';
            $changePctStr = $item['CHGP'] ?? $item['change_percent'] ?? null;

            // 優先用 CHGP，次選自行計算
            if ($changePctStr !== null && $changePctStr !== '--') {
                $changePct = $this->parseFloat($changePctStr);
            } elseif ($indexValue > 0 && $changeStr !== '--') {
                $change    = $this->parseFloat($changeStr);
                $prevIndex = $indexValue - $change;
                $changePct = $prevIndex > 0 ? round($change / $prevIndex * 100, 2) : 0;
            } else {
                $changePct = 0;
            }

            $volume = (int) ($this->parseFloat($item['TV'] ?? $item['volume'] ?? 0) * 1000);

            SectorIndex::updateOrCreate(
                ['date' => $date, 'sector_code' => $code],
                [
                    'sector_name'    => $sectorName,
                    'index_value'    => $indexValue,
                    'change_percent' => $changePct,
                    'volume'         => $volume,
                ]
            );

            $saved++;
        }

        $this->info("完成，儲存 {$saved} 個類股指數。");
        Log::info("FetchSectorIndices {$date}：儲存 {$saved} 筆");

        return self::SUCCESS;
    }

    private function fetchFromTwse(): array
    {
        // TWSE OpenAPI 類股指數即時行情
        $response = Http::timeout(15)
            ->withHeaders(['Accept' => 'application/json'])
            ->get('https://openapi.twse.com.tw/v1/indicesReport/MI_5MINS');

        if (!$response->successful()) {
            throw new \RuntimeException("HTTP {$response->status()}: " . $response->body());
        }

        $data = $response->json();

        if (!is_array($data) || empty($data)) {
            throw new \RuntimeException('TWSE API 回傳空資料');
        }

        return $data;
    }

    private function parseFloat(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        $cleaned = str_replace([',', ' ', '--', 'X'], '', (string) $value);
        return is_numeric($cleaned) ? (float) $cleaned : 0.0;
    }
}

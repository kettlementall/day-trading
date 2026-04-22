<?php

namespace App\Console\Commands;

use App\Models\SectorIndex;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchSectorIndices extends Command
{
    protected $signature = 'stock:fetch-sector-indices {date?}';
    protected $description = '抓取 TWSE 類股指數即時漲跌（12:25 執行，供隔日沖選股使用）';

    /**
     * TWSE MI_INDEX 回傳的「指數」中文名稱 → 系統 industry（對應 stocks.industry）
     * 只取非報酬版本（不含「報酬指數」）
     */
    private const SECTOR_MAP = [
        '半導體類指數'         => '半導體業',
        '電子工業類指數'       => '電子工業',
        '電腦及週邊設備類指數' => '電腦及週邊設備業',
        '光電類指數'           => '光電業',
        '通信網路類指數'       => '通信網路業',
        '電子零組件類指數'     => '電子零組件業',
        '電子通路類指數'       => '電子通路業',
        '資訊服務類指數'       => '資訊服務業',
        '其他電子類指數'       => '其他電子業',
        '金融保險類指數'       => '金融保險',
        '鋼鐵類指數'           => '鋼鐵工業',
        '橡膠類指數'           => '橡膠工業',
        '水泥類指數'           => '水泥工業',
        '食品類指數'           => '食品工業',
        '塑膠類指數'           => '塑膠工業',
        '紡織纖維類指數'       => '紡織纖維',
        '電機機械類指數'       => '電機機械',
        '電器電纜類指數'       => '電器電纜',
        '化學生技醫療類指數'   => '化學生技醫療',
        '玻璃陶瓷類指數'       => '玻璃陶瓷',
        '造紙類指數'           => '造紙工業',
        '建材營造類指數'       => '建材營造',
        '航運類指數'           => '航運業',
        '觀光餐旅類指數'       => '觀光餐旅',
        '生技醫療類指數'       => '生技醫療業',
        '油電燃氣類指數'       => '油電燃氣業',
        '數位雲端類指數'       => '數位雲端',
        '綠能環保類指數'       => '綠能環保',
        '其他類指數'           => '其他',
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
        $actualDate = $date;
        foreach ($data as $item) {
            $indexName = trim($item['指數'] ?? '');
            if (!isset(self::SECTOR_MAP[$indexName])) {
                continue;
            }

            $sectorName = self::SECTOR_MAP[$indexName];
            $indexValue = $this->parseFloat($item['收盤指數'] ?? 0);
            $sign       = trim($item['漲跌'] ?? '+') === '-' ? -1 : 1;
            $changePct  = $sign * $this->parseFloat($item['漲跌百分比'] ?? 0);

            // 民國年日期 → 西元（e.g. "1150414" → "2026-04-14"）
            $rocDate    = $item['日期'] ?? '';
            $dataDate   = $this->parseRocDate($rocDate) ?? $date;
            $actualDate = $dataDate;

            SectorIndex::updateOrCreate(
                ['date' => $dataDate, 'sector_code' => $indexName],
                [
                    'sector_name'    => $sectorName,
                    'index_value'    => $indexValue,
                    'change_percent' => $changePct,
                    'volume'         => 0,
                ]
            );

            $saved++;
        }

        // 找出漲跌幅前3名
        $topSectors = SectorIndex::where('date', $actualDate)
            ->orderByDesc('change_percent')
            ->take(3)
            ->get()
            ->map(fn ($s) => sprintf('%s %+.1f%%', $s->sector_name, $s->change_percent))
            ->implode(' | ');
        $bottomSectors = SectorIndex::where('date', $actualDate)
            ->orderBy('change_percent')
            ->take(3)
            ->get()
            ->map(fn ($s) => sprintf('%s %+.1f%%', $s->sector_name, $s->change_percent))
            ->implode(' | ');

        app(TelegramService::class)->send(
            "✅ *類股指數抓取* 完成\n📅 {$date} | 共 {$saved} 類\n🔺 {$topSectors}\n🔻 {$bottomSectors}"
        );

        $this->info("完成，儲存 {$saved} 個類股指數。");
        Log::info("FetchSectorIndices {$date}：儲存 {$saved} 筆");

        return self::SUCCESS;
    }

    private function fetchFromTwse(): array
    {
        // TWSE OpenAPI 類股指數（每日收盤後更新，含各類股漲跌幅）
        $response = Http::timeout(15)
            ->withHeaders(['Accept' => 'application/json'])
            ->get('https://openapi.twse.com.tw/v1/indicesReport/MI_INDEX');

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

    /**
     * 民國年日期字串轉西元（"1150414" → "2026-04-14"）
     */
    private function parseRocDate(string $rocStr): ?string
    {
        $rocStr = trim($rocStr);
        if (strlen($rocStr) !== 7 || !ctype_digit($rocStr)) {
            return null;
        }
        $rocYear = (int) substr($rocStr, 0, 3);
        $month   = substr($rocStr, 3, 2);
        $day     = substr($rocStr, 5, 2);
        $year    = $rocYear + 1911;
        return "{$year}-{$month}-{$day}";
    }
}

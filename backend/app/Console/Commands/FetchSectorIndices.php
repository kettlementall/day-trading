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
    protected $description = '抓取 TWSE 類股指數收盤漲跌（15:30 執行，盤後 swing 持倉檢討使用）';

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

        // 主來源：TWSE 帶日期端點（明確要求指定日期，stat=OK 才回傳該日資料）
        $data = null;
        $source = null;
        try {
            $data = $this->fetchFromTwseWithDate($date);
            if ($data !== null) {
                $source = 'twse_with_date';
            }
        } catch (\Exception $e) {
            $this->warn("TWSE 帶日期端點失敗：{$e->getMessage()}，嘗試 OpenAPI fallback");
            Log::warning("FetchSectorIndices: twse_with_date 失敗，fallback OpenAPI — {$e->getMessage()}");
        }

        // Fallback：OpenAPI（保證回得到資料、但常為 T-1）
        if ($data === null) {
            try {
                $data = $this->fetchFromOpenApi();
                $source = 'twse_openapi_fallback';
                Log::info("FetchSectorIndices: 主端點無 {$date} 資料，已 fallback OpenAPI");
            } catch (\Exception $e) {
                $this->error('OpenAPI 也失敗：' . $e->getMessage());
                Log::error('FetchSectorIndices 雙端點皆失敗：' . $e->getMessage());
                return self::FAILURE;
            }
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

        if ($actualDate !== $date) {
            $this->warn("TWSE 回傳資料日期為 {$actualDate}（請求 {$date}），收盤指數尚未更新（source={$source}）");
            Log::info("FetchSectorIndices：API 回傳 {$actualDate}，請求 {$date}，source={$source}");
        } else {
            Log::info("FetchSectorIndices {$date}：成功取得當日資料，source={$source}");
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

        app(TelegramService::class)->broadcast(
            "✅ *類股指數抓取* 完成\n📅 {$date} | 共 {$saved} 類\n🔺 {$topSectors}\n🔻 {$bottomSectors}",
            'system'
        );

        $this->info("完成，儲存 {$saved} 個類股指數。");
        Log::info("FetchSectorIndices {$date}：儲存 {$saved} 筆");

        return self::SUCCESS;
    }

    /**
     * 帶日期端點：可明確指定日期取資料，當日尚未發佈時回 null（不會誤回 T-1）
     *
     * @return array<array<string,string>>|null  null = 該日資料尚未發佈或 API 異常
     */
    private function fetchFromTwseWithDate(string $date): ?array
    {
        $compactDate = str_replace('-', '', $date);
        $url = "https://www.twse.com.tw/exchangeReport/MI_INDEX?response=json&date={$compactDate}&type=IND";

        $response = Http::timeout(15)
            ->withHeaders(['Accept' => 'application/json'])
            ->get($url);

        if (!$response->successful()) {
            throw new \RuntimeException("HTTP {$response->status()}: " . $response->body());
        }

        $data = $response->json();

        // stat: OK = 成功；其他訊息（如「查詢日期大於今日」）= 該日尚未發佈
        if (($data['stat'] ?? '') !== 'OK') {
            Log::info("FetchSectorIndices twse_with_date 回應 stat={$data['stat']}（{$date}）");
            return null;
        }

        $tables = $data['tables'] ?? [];
        // 第一張表為「臺灣證券交易所價格指數」
        $table = $tables[0] ?? null;
        if (!$table || empty($table['data'])) {
            return null;
        }

        // 轉成與 OpenAPI 相容的格式：['指數','收盤指數','漲跌','漲跌百分比','日期']
        // 新端點 [指數,收盤,signHtml,點數,signed%] — % 已含正負號
        $rows = [];
        foreach ($table['data'] as $row) {
            if (!is_array($row) || count($row) < 5) {
                continue;
            }
            $pctStr = trim((string) $row[4]);
            $sign = str_starts_with($pctStr, '-') ? '-' : '+';
            $absPct = ltrim($pctStr, '-+');
            $rows[] = [
                '指數'         => (string) $row[0],
                '收盤指數'     => (string) $row[1],
                '漲跌'         => $sign,
                '漲跌百分比'   => $absPct,
                '日期'         => $this->toRocDate($date),
            ];
        }

        return $rows;
    }

    /**
     * OpenAPI fallback：當帶日期端點無法回應時使用，可能回 T-1
     */
    private function fetchFromOpenApi(): array
    {
        $response = Http::timeout(15)
            ->withHeaders(['Accept' => 'application/json'])
            ->get('https://openapi.twse.com.tw/v1/indicesReport/MI_INDEX');

        if (!$response->successful()) {
            throw new \RuntimeException("HTTP {$response->status()}: " . $response->body());
        }

        $data = $response->json();

        if (!is_array($data) || empty($data)) {
            throw new \RuntimeException('TWSE OpenAPI 回傳空資料');
        }

        return $data;
    }

    /**
     * "2026-05-28" → "1150528"（民國年壓字串）
     */
    private function toRocDate(string $date): string
    {
        $ts = strtotime($date);
        if ($ts === false) {
            return '';
        }
        $year = (int) date('Y', $ts) - 1911;
        return sprintf('%03d%s%s', $year, date('m', $ts), date('d', $ts));
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

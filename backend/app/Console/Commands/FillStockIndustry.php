<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FillStockIndustry extends Command
{
    protected $signature = 'stock:fill-industry {--force : 強制覆蓋已有 industry 的資料}';
    protected $description = '從 TWSE/TPEX OpenAPI 抓取上市櫃公司基本資料，填入 stocks.industry（產業別）';

    /**
     * MOPS 產業別代碼 → 系統 industry 名稱
     *
     * 命名原則：
     * 1. 凡有對應 sector_indices 類股指數者，採用 sector_indices 表中的 sector_name，
     *    讓 SectorIndex::getChangeForIndustry() 可直接命中。
     * 2. 沒有對應類股指數者（如汽車工業、貿易百貨），採用 MOPS 標準產業別名稱，
     *    至少讓 AI prompt 能看到正確分類，sector lookup 自然 fallback 為 null。
     * 3. 化學工業（21）併入「化學生技醫療」（TWSE 公布的合併指數），生技醫療業（22）獨立。
     */
    private const INDUSTRY_NAME_MAP = [
        '01' => '水泥工業',
        '02' => '食品工業',
        '03' => '塑膠工業',
        '04' => '紡織纖維',
        '05' => '電機機械',
        '06' => '電器電纜',
        '08' => '玻璃陶瓷',
        '09' => '造紙工業',
        '10' => '鋼鐵工業',
        '11' => '橡膠工業',
        '12' => '汽車工業',
        '14' => '建材營造',
        '15' => '航運業',
        '16' => '觀光餐旅',
        '17' => '金融保險',
        '18' => '貿易百貨',
        '20' => '其他',
        '21' => '化學生技醫療',
        '22' => '生技醫療業',
        '23' => '油電燃氣業',
        '24' => '半導體業',
        '25' => '電腦及週邊設備業',
        '26' => '光電業',
        '27' => '通信網路業',
        '28' => '電子零組件業',
        '29' => '電子通路業',
        '30' => '資訊服務業',
        '31' => '其他電子業',
        '32' => '文化創意業',
        '33' => '農業科技業',
        '35' => '綜合',
        '36' => '綠能環保',
        '37' => '數位雲端',
        '38' => '運動休閒',
        '39' => '居家生活',
        '80' => '管理股票',
        '91' => '創新板',
    ];

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $this->info('開始抓取上市櫃公司產業別資料' . ($force ? '（force 覆蓋模式）' : ''));

        // 注意：以 symbol 為 key 的關聯陣列，PHP 會把 '2002' 等數字字串 key 自動轉成 int，
        // array_merge 在 int key 上會重編號 0..n-1，因此這裡改用 + 聯集運算子保留 key。
        $entries = [];
        $unknownCodes = [];

        try {
            [$twseEntries, $twseUnknown] = $this->fetchTwse();
            $entries = $entries + $twseEntries;
            $unknownCodes = array_merge($unknownCodes, $twseUnknown);
            $this->info("TWSE 上市：{$this->countOf($twseEntries)} 筆");
        } catch (\Exception $e) {
            $this->error('TWSE 抓取失敗：' . $e->getMessage());
            Log::error('FillStockIndustry TWSE 失敗：' . $e->getMessage());
        }

        try {
            [$tpexEntries, $tpexUnknown] = $this->fetchTpex();
            $entries = $entries + $tpexEntries;
            $unknownCodes = array_merge($unknownCodes, $tpexUnknown);
            $this->info("TPEX 上櫃：{$this->countOf($tpexEntries)} 筆");
        } catch (\Exception $e) {
            $this->error('TPEX 抓取失敗：' . $e->getMessage());
            Log::error('FillStockIndustry TPEX 失敗：' . $e->getMessage());
        }

        if (empty($entries)) {
            $this->error('TWSE 與 TPEX 皆失敗，已中止');
            return self::FAILURE;
        }

        $updated = 0;
        $skippedExisting = 0;
        $notInDb = 0;

        foreach ($entries as $symbol => $industry) {
            $stock = Stock::where('symbol', $symbol)->first();
            if (!$stock) {
                $notInDb++;
                continue;
            }
            if (!$force && !empty($stock->industry)) {
                $skippedExisting++;
                continue;
            }
            if ($stock->industry !== $industry) {
                $stock->industry = $industry;
                $stock->save();
                $updated++;
            }
        }

        $remainingEmpty = Stock::where(function ($q) {
            $q->whereNull('industry')->orWhere('industry', '');
        })->count();

        $unknownSummary = '';
        if (!empty($unknownCodes)) {
            $unique = array_unique($unknownCodes);
            $unknownSummary = '；未識別代碼：' . implode(',', $unique);
            Log::warning('FillStockIndustry 未識別產業代碼：' . implode(',', $unique));
        }

        $this->info("完成：更新 {$updated} 檔，已有 industry 跳過 {$skippedExisting} 檔，{$notInDb} 檔不在本地 stocks 表內{$unknownSummary}");
        $this->info("剩餘 industry 為空的 stocks：{$remainingEmpty} 檔");

        Log::info("FillStockIndustry：更新 {$updated} 檔，剩餘空值 {$remainingEmpty} 檔");

        app(TelegramService::class)->broadcast(
            "✅ *產業別填補* 完成\n📥 抓取 " . count($entries) . " 筆 | ✏️ 更新 {$updated} 檔\n⏭️ 已有 industry：{$skippedExisting}\n❓ 不在本地表：{$notInDb}\n🕳️ 仍空值：{$remainingEmpty}",
            'system'
        );

        return self::SUCCESS;
    }

    /**
     * @return array{0: array<string,string>, 1: array<string>}  [entries, unknownCodes]
     */
    private function fetchTwse(): array
    {
        $resp = Http::timeout(30)
            ->withHeaders(['Accept' => 'application/json'])
            ->get('https://openapi.twse.com.tw/v1/opendata/t187ap03_L');

        if (!$resp->successful()) {
            throw new \RuntimeException("TWSE HTTP {$resp->status()}");
        }
        $data = $resp->json();
        if (!is_array($data) || empty($data)) {
            throw new \RuntimeException('TWSE 回傳空資料');
        }

        return $this->extractEntries($data, '公司代號', '產業別');
    }

    /**
     * @return array{0: array<string,string>, 1: array<string>}
     */
    private function fetchTpex(): array
    {
        $resp = Http::timeout(30)
            ->withHeaders(['Accept' => 'application/json'])
            ->get('https://www.tpex.org.tw/openapi/v1/mopsfin_t187ap03_O');

        if (!$resp->successful()) {
            throw new \RuntimeException("TPEX HTTP {$resp->status()}");
        }
        $data = $resp->json();
        if (!is_array($data) || empty($data)) {
            throw new \RuntimeException('TPEX 回傳空資料');
        }

        return $this->extractEntries($data, 'SecuritiesCompanyCode', 'SecuritiesIndustryCode');
    }

    /**
     * @return array{0: array<string,string>, 1: array<string>}
     */
    private function extractEntries(array $rows, string $codeKey, string $industryKey): array
    {
        $entries = [];
        $unknownCodes = [];

        foreach ($rows as $row) {
            $symbol = trim($row[$codeKey] ?? '');
            $industryCode = trim($row[$industryKey] ?? '');

            // 只接受 4 碼一般股票代號（排除權證、ETF、債券等）
            if (!preg_match('/^\d{4}$/', $symbol)) {
                continue;
            }
            if ($industryCode === '') {
                continue;
            }

            $industryName = self::INDUSTRY_NAME_MAP[$industryCode] ?? null;
            if ($industryName === null) {
                $unknownCodes[] = $industryCode;
                continue;
            }

            $entries[$symbol] = $industryName;
        }

        return [$entries, $unknownCodes];
    }

    private function countOf(array $entries): int
    {
        return count($entries);
    }
}

<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 驗證 TWSE/TPEX API 回傳格式是否仍與程式解析邏輯一致。
 *
 * 執行方式：
 *   docker compose exec php php artisan test --filter=FetchApiFormatTest
 *
 * 這些測試會打真實 API，建議在交易日 15:00 後執行，確保當天資料已發布。
 */
class FetchApiFormatTest extends TestCase
{
    /**
     * 找到最近一個有資料的交易日（避免假日）
     */
    private function findRecentTradeDate(): string
    {
        // 往回找 7 天，避開假日
        for ($i = 1; $i <= 7; $i++) {
            $date = now()->subDays($i);
            if ($date->isWeekend()) continue;

            $dateStr = $date->format('Ymd');
            $url = "https://www.twse.com.tw/exchangeReport/MI_INDEX?response=json&date={$dateStr}&type=ALLBUT0999";
            $response = file_get_contents($url);
            $json = json_decode($response, true);

            if (($json['stat'] ?? '') === 'OK') {
                return $dateStr;
            }
            usleep(500000);
        }

        $this->markTestSkipped('找不到近期交易日資料');
        return '';
    }

    /**
     * 驗證 TWSE 每日收盤行情 API 格式
     */
    public function test_twse_daily_quote_format(): void
    {
        $date = $this->findRecentTradeDate();

        $url = "https://www.twse.com.tw/exchangeReport/MI_INDEX?response=json&date={$date}&type=ALLBUT0999";
        $response = file_get_contents($url);
        $json = json_decode($response, true);

        $this->assertEquals('OK', $json['stat'], 'TWSE API 應回傳 stat=OK');

        // 取得股票行情資料（支援新舊格式）
        $rows = $json['data9'] ?? $json['data8'] ?? [];
        if (empty($rows) && !empty($json['tables'])) {
            foreach ($json['tables'] as $table) {
                if (str_contains($table['title'] ?? '', '每日收盤行情')) {
                    $rows = $table['data'] ?? [];
                    break;
                }
            }
        }

        $this->assertNotEmpty($rows, '應該有股票行情資料（data9/data8 或 tables 中的每日收盤行情）');
        $this->assertGreaterThan(500, count($rows), '上市股應超過 500 檔');

        // 找一筆 4 碼代號的資料驗證欄位結構
        $sampleRow = null;
        foreach ($rows as $row) {
            if (preg_match('/^\d{4}$/', trim($row[0]))) {
                $sampleRow = $row;
                break;
            }
        }

        $this->assertNotNull($sampleRow, '應該有 4 碼股票代號的資料');
        $this->assertGreaterThanOrEqual(10, count($sampleRow), '每筆資料應至少 10 個欄位');

        // 驗證欄位可正確解析
        $symbol = trim($sampleRow[0]);
        $this->assertMatchesRegularExpression('/^\d{4}$/', $symbol, '欄位 0 應為 4 碼代號');

        $name = trim($sampleRow[1]);
        $this->assertNotEmpty($name, '欄位 1 應為股票名稱');

        $volume = (float) str_replace([',', ' '], '', $sampleRow[2]);
        $this->assertGreaterThan(0, $volume, '欄位 2（成交股數）應 > 0');

        $open = (float) str_replace([',', ' '], '', $sampleRow[5]);
        $high = (float) str_replace([',', ' '], '', $sampleRow[6]);
        $low = (float) str_replace([',', ' '], '', $sampleRow[7]);
        $close = (float) str_replace([',', ' '], '', $sampleRow[8]);

        $this->assertGreaterThan(0, $open, '欄位 5（開盤價）應 > 0');
        $this->assertGreaterThan(0, $close, '欄位 8（收盤價）應 > 0');
        $this->assertGreaterThanOrEqual($low, $high, '最高價應 >= 最低價');

        // 漲跌欄位：row[9] 含方向資訊
        $this->assertNotEmpty($sampleRow[9], '欄位 9（漲跌方向）應存在');
    }

    /**
     * 驗證三大法人 API 格式
     */
    public function test_twse_institutional_trade_format(): void
    {
        $date = $this->findRecentTradeDate();

        $url = "https://www.twse.com.tw/fund/T86?response=json&date={$date}&selectType=ALLBUT0999";
        $response = file_get_contents($url);
        $json = json_decode($response, true);

        $this->assertEquals('OK', $json['stat'], '三大法人 API 應回傳 stat=OK');

        $rows = $json['data'] ?? [];
        $this->assertNotEmpty($rows, '應該有法人買賣超資料');
        $this->assertGreaterThan(500, count($rows), '上市股應超過 500 檔');

        // 驗證欄位結構
        $sampleRow = null;
        foreach ($rows as $row) {
            if (preg_match('/^\d{4}$/', trim($row[0]))) {
                $sampleRow = $row;
                break;
            }
        }

        $this->assertNotNull($sampleRow, '應該有 4 碼代號的法人資料');
        $this->assertGreaterThanOrEqual(14, count($sampleRow), '法人資料應至少 14 個欄位');

        // 外資買賣超
        $foreignBuy = (int) str_replace([',', ' '], '', $sampleRow[2]);
        $foreignSell = (int) str_replace([',', ' '], '', $sampleRow[3]);
        $foreignNet = (int) str_replace([',', ' '], '', $sampleRow[4]);

        $this->assertGreaterThanOrEqual(0, $foreignBuy, '外資買應 >= 0');
        $this->assertGreaterThanOrEqual(0, $foreignSell, '外資賣應 >= 0');
        $this->assertEquals($foreignBuy - $foreignSell, $foreignNet, '外資淨買超應 = 買 - 賣');
    }

    /**
     * 驗證盤中即時報價 API 格式（mis.twse.com.tw）
     */
    public function test_twse_realtime_quote_format(): void
    {
        // 用台積電 2330 測試
        $url = 'https://mis.twse.com.tw/stock/api/getStockInfo.jsp?ex_ch=tse_2330.tw';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Referer: https://mis.twse.com.tw/'],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertEquals(200, $httpCode, 'mis API 應回傳 200');

        $json = json_decode($response, true);
        $this->assertArrayHasKey('msgArray', $json, '應包含 msgArray');

        $items = $json['msgArray'];
        $this->assertNotEmpty($items, 'msgArray 不應為空');

        $item = $items[0];

        // 基本欄位
        $this->assertArrayHasKey('c', $item, '應有 c（代號）欄位');
        $this->assertEquals('2330', $item['c'], '代號應為 2330');

        $this->assertArrayHasKey('n', $item, '應有 n（名稱）欄位');
        $this->assertArrayHasKey('y', $item, '應有 y（昨收）欄位');
        $this->assertArrayHasKey('o', $item, '應有 o（開盤）欄位');

        // 交易時段才有的欄位（非交易時段可能為 "-"）
        $this->assertArrayHasKey('z', $item, '應有 z（最新成交價）欄位');
        $this->assertArrayHasKey('v', $item, '應有 v（成交量）欄位');
        $this->assertArrayHasKey('h', $item, '應有 h（最高）欄位');
        $this->assertArrayHasKey('l', $item, '應有 l（最低）欄位');

        // 五檔報價
        $this->assertArrayHasKey('a', $item, '應有 a（賣價）欄位');
        $this->assertArrayHasKey('b', $item, '應有 b（買價）欄位');
    }

    /**
     * 驗證 StockScreener 的完整流程能正常運作（需要 DB 資料）
     */
    public function test_screening_produces_results(): void
    {
        $count = \App\Models\DailyQuote::distinct('date')->count('date');

        if ($count < 20) {
            $this->markTestSkipped('需要至少 20 天歷史資料才能測試選股');
        }

        $screener = new \App\Services\StockScreener();
        $tradeDate = now()->format('Y-m-d');
        $candidates = $screener->screen($tradeDate);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $candidates);

        if ($candidates->isNotEmpty()) {
            $first = $candidates->first();
            $this->assertArrayHasKey('stock_id', $first);
            $this->assertArrayHasKey('score', $first);
            $this->assertArrayHasKey('suggested_buy', $first);
            $this->assertArrayHasKey('target_price', $first);
            $this->assertArrayHasKey('stop_loss', $first);
            $this->assertArrayHasKey('reasons', $first);
            $this->assertGreaterThanOrEqual(30, $first['score'], '分數應 >= 30');
            $this->assertGreaterThanOrEqual(1.5, $first['risk_reward_ratio'], '風報比應 >= 1.5');
        }
    }
}

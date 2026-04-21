---
description: 即時抓取股票盤中報價與5分K走勢，分析價量趨勢並給出操作建議
user-invocable: true
---

用戶輸入: $ARGUMENTS

從用戶輸入中解析以下參數：
- **股票代號**（必填）：4位數字，例如 6191
- **成本價**（選填）：用戶的持倉成本，例如 110.56

範例輸入格式：
- `6191` — 只看報價
- `6191 110.56` — 看報價 + 以成本 110.56 分析損益

---

## 執行步驟

### Step 1：透過 Fugle API 抓取即時報價

在 docker 容器內用 tinker 執行，先抓 quote 再隔 1 秒抓 5 分 K：

```
docker compose exec php php artisan tinker --execute="
\$apiKey = config('services.fugle.api_key', '');
\$symbol = '{股票代號}';

// 即時報價
\$resp = \Illuminate\Support\Facades\Http::timeout(10)
    ->withHeaders(['X-API-KEY' => \$apiKey])
    ->get('https://api.fugle.tw/marketdata/v1.0/stock/intraday/quote/' . \$symbol);
echo '===QUOTE===' . \"\n\" . \$resp->body() . \"\n\";

sleep(1);

// 5分K
\$resp2 = \Illuminate\Support\Facades\Http::timeout(10)
    ->withHeaders(['X-API-KEY' => \$apiKey])
    ->get('https://api.fugle.tw/marketdata/v1.0/stock/intraday/candles/' . \$symbol, ['timeframe' => '5']);
echo '===CANDLES===' . \"\n\" . \$resp2->body() . \"\n\";
"
```

如果遇到 429 rate limit，等 5 秒後重試一次。

### Step 2：整理並顯示報價摘要

用表格呈現：
- 昨收、開盤、最高、最低、現價、漲跌%
- 成交量（tradeVolume 單位已是張）、成交筆數
- 外盤比（tradeVolumeAtAsk / (tradeVolumeAtBid + tradeVolumeAtAsk) × 100）
- 最佳五檔買賣價量
- 如果有成本價：計算帳面損益%

### Step 3：顯示 5 分 K 走勢表

每根 K 線一行，包含時間、開高低收、成交張數、對昨收漲跌%。

### Step 4：走勢分析與操作建議

根據以下面向分析：

1. **開盤型態**：開高/開低/平開，跳空幅度
2. **量價關係**：量能集中在哪個時段、是否價漲量增或價跌量縮
3. **趨勢方向**：從 5 分 K 判斷是上升、下降、還是震盪整理
4. **關鍵價位**：日高、日低、開盤價作為短線支撐壓力
5. **外盤比**：> 55% 偏多方、< 45% 偏空方

如果用戶有提供成本價，明確建議：
- **續抱**：說明理由和建議停利/停損價位
- **止損**：說明理由和建議出場價位
- **觀望**：說明需要觀察什麼條件再決定

# 系統規格書

> 本文件為系統完整規格，任何規則、排程、公式的異動都必須同步更新此文件。

---

## 1. 每日排程

排程定義於 `backend/routes/console.php`。

| 時間  | 指令                        | 說明                           |
|-------|-----------------------------|-------------------------------|
| 06:00 | `news:fetch`                | 抓取隔夜國際新聞               |
| 06:15 | `news:compute-indices`      | 計算新聞指數（供選股用）        |
| 08:00 | `stock:ai-screen`           | AI 選股（規則式寬篩 min_score=45 + AI 審核選出 10-15 檔 + 策略標籤） |
| 08:00 | `news:fetch`                | 開盤前新聞抓取                 |
| 08:15 | `news:compute-indices`      | 計算新聞指數                   |
| 09:05 | `stock:fetch-intraday`      | 盤中即時行情（5分K）           |
| 09:30 | `stock:fetch-intraday`      | 盤中即時行情（30分鐘後狀態）   |
| 09:00-13:30 | `stock:monitor-intraday` | 盤中即時監控（每分鐘觸發，內部依時段動態控頻） |
| 12:00 | `news:fetch`                | 午間新聞抓取                   |
| 12:15 | `news:compute-indices`      | 計算新聞指數                   |
| 14:30 | `stock:fetch-daily`         | 收盤後抓取每日行情             |
| 15:00 | `stock:update-results`      | 更新前日候選標的的盤後結果     |
| 16:00 | `stock:fetch-institutional` | 抓取三大法人買賣超             |
| 16:30 | `stock:fetch-margin`        | 抓取融資融券                   |
| 18:00 | `news:fetch`                | 盤後新聞抓取                   |
| 18:15 | `news:compute-indices`      | 計算新聞指數                   |
| 22:00 | `stock:health-check`        | 健康檢查（確認當日資料完整）   |
| 週一 07:00 | `stock:backtest --validated` | 帶驗證的自動回測優化（過去60天，最多10次嘗試）  |

### 資料依賴流程

```
14:30 行情 ──┐
16:00 法人 ──┤
16:30 融資 ──┼── 06:00 隔夜新聞 → 06:15 算指數 → 08:00 AI 選股（寬篩 + AI 審核）
18:00 新聞 ──┘                                         │
                                                       ↓
                         09:00 開始盤中快照 → 09:05 AI 開盤校準
                                                       │
                         09:05+ 規則式持續監控 + AI 每 30 分鐘滾動判斷
                                                       │
                                              13:25 強制平倉 → 15:00 盤後結果回填
```

### 排程日誌

所有排程指令的 stdout 輸出皆追加寫入 `storage/logs/schedule.log`，可用於確認各指令是否有實際執行。

### 非交易日處理

所有市場資料抓取指令（`stock:fetch-daily`、`stock:fetch-institutional`、`stock:fetch-margin`）皆具備日期驗證機制：

- 從 TWSE/TPEX API 回傳的 `date` / `reportDate` 欄位取得**實際交易日**
- 若實際交易日與請求日期不符（代表該日為假日），自動跳過不存入
- 避免假日期間 API 回傳上一交易日資料被存到錯誤日期的問題

排程在假日仍會執行，但因上述驗證機制，不會產生錯誤資料。

### 資料修復

若發現歷史資料日期錯位，可使用修復指令：

```bash
# 檢查（不修改）
php artisan stock:repair-quotes --from=2026-03-01 --to=2026-04-08 --dry-run

# 執行修復（刪除假日錯誤資料 → 重新抓取正確交易日）
php artisan stock:repair-quotes --from=2026-03-01 --to=2026-04-08
```

---

## 2. 選股評分規則

定義於 `backend/app/Services/StockScreener.php`。所有分數與閾值皆可透過 `FormulaSetting` 設定頁調整。

### 2.1 基礎門檻

| 條件             | 閾值      |
|-----------------|----------|
| 成交量（張）      | > 500    |
| 股價              | > 10 元  |
| 最終分數          | >= 30 分  |
| 風報比            | >= 1.5   |
| 最多選取          | 前 20 名  |

### 2.1a 硬排除條件

在評分之前先行排除，不進入評分流程：

| 條件 | 閾值 | 設定鍵 | 說明 |
|------|------|--------|------|
| 5日均振幅過低 | < 2.5% | `min_amplitude` | 振幅太小無當沖價值 |
| 5日均量過低 | < 2000 張 | `min_day_trading_volume` | 量能不足難以進出 |

### 2.2 評分項目

| #  | 項目           | 預設分數 | 條件                                   | 設定鍵              |
|----|---------------|---------|----------------------------------------|---------------------|
| 1  | 量能放大       | 15      | 當日成交量 > 5日均量 × 1.5              | `volume_surge`      |
| 2  | 均線多頭排列   | 15      | MA5 > MA10 > MA20                      | `ma_bullish`        |
| 3  | 站上5MA        | 5       | 收盤價 > MA5                            | `above_ma5`         |
| 4  | KD黃金交叉     | 10      | K > D 且 K < 80                        | `kd_golden_cross`   |
| 5  | RSI適中        | 5       | RSI 介於 40 ~ 70                       | `rsi_moderate`      |
| 6  | 外資買超       | 10      | 最近一日外資淨買 > 0                    | `foreign_buy`       |
| 7  | 法人連續買超   | 10      | 連續 >= 3 日法人合計淨買 > 0            | `consecutive_buy`   |
| 8  | 投信買超       | 5       | 最近一日投信淨買 > 0                    | `trust_buy`         |
| 9  | 融資減少       | 5       | 最近一日融資變化 < 0                    | `margin_decrease`   |
| 10 | 振幅適中       | 5       | 當日振幅介於 2% ~ 7%                   | `amplitude_moderate`|
| 11 | 突破前高       | 10      | 收盤價 > 前5日最高價（排除假突破：長上影線 > 實體×1.5 且收盤貼近低點） | `break_prev_high`   |
| 11a| 過高回測站穩   | 12      | 前2~5日曾突破更早期高點（前6~10日），拉回後收盤仍站穩前高上方 | `break_prev_high.retest` |
| 11b| 壓力位偵測     | 0（標記）| 收盤接近近60日高點（≥97%）但未突破，標記 `near_pressure` 供參考 | `break_prev_high.pressure` |
| 12 | 布林中軌上方   | 5       | 收盤價介於布林中軌與上軌之間            | `bollinger_position`|
| 13 | 高波動         | 10      | 近10日平均振幅 >= 5%                    | `high_volatility`   |
| 14 | 近月強勢       | 10      | 近20日漲幅 > 15%                       | `strong_trend`      |
| 15 | 跌深反彈       | 15      | 策略分類為 bounce（見 §2.3）            | `strategy.bounce`   |
| 16 | 突破追多       | 15      | 策略分類為 breakout（見 §2.3）          | `strategy.breakout` |
| 17 | 外資大買       | 5       | 外資淨買 > 當日成交量 × 5%             | `foreign_big_buy`   |
| 18 | 自營大買       | 5       | 自營淨買 > 當日成交量 × 3%             | `dealer_big_buy`    |
| 19 | 萬張量能       | 5       | 成交量 >= 10,000 張                    | `high_volume`       |
| 20 | 消息面情緒     | ±10~15  | 依消息面指數調整（見 §2.5）             | `news_sentiment`    |
| 21 | 長上影線懲罰   | -10     | 上影線 > 實體 ×1.5 且收盤在下方 30%     | （內建規則）         |

### 2.3 策略分類

#### 跌深反彈 (bounce)

觸發條件（全部滿足）：

| 參數                 | 預設值 | 說明                                      |
|---------------------|--------|------------------------------------------|
| `washout_drop_pct`  | -5%    | 近 N 日內有單日跌幅達此值，或...          |
| `two_day_drop_pct`  | -7%    | 連兩日合計跌幅達此值                      |
| `washout_lookback_days` | 3  | 洗盤回溯天數                              |
| `bounce_from_low_pct`   | 3% | 收盤價距近3日最低點反彈幅度 >= 此值       |

額外判斷：急跌當天不算反彈；需出現紅 K 或長下影線才確認反彈。

#### 突破追多 (breakout)

觸發條件（全部滿足）：

| 參數                  | 預設值 | 說明                           |
|----------------------|--------|-------------------------------|
| `prev_high_days`     | 5      | 比較前 N 日最高價              |
| `near_breakout_pct`  | 0.98   | 收盤價 >= 前高 × 此值即算接近  |
| 站上 MA5             | -      | 收盤價 > MA5                   |

**突破確認原則（§2.2 #11 細化）：**

- **真突破**：量能放大 + 收盤實體站上前高 + 無長上影線 → 加分
- **假突破**：爆量長上影線（上影 > 實體 ×1.5）+ 收盤貼近低點 → 不加分，標記 `fake_breakout`
- **過高回測**：前幾日已突破 → 拉回不破前高 → 確認站穩才是最佳進場時機
- **壓力試探**：接近長期高點（近60日）但未突破 → 標記 `near_pressure`，不宜追買

### 2.4 價格計算

#### 建議買入價

優先順序：
1. **跌深反彈型**：取 MA10（介於收盤 ×0.95~1.07 間），否則取 MA5
2. **突破追多型**：取前5日最高價（介於收盤 ×0.98~1.08 間）
3. **通用邏輯**：從近5日最低點、MA(N)、布林中軌取最高的支撐價
4. **Fallback**：收盤價 × 0.99

篩選規則：支撐價需介於收盤價 ×0.95 ~ 收盤價之間。

#### 目標獲利價

取以下三者中最保守者：
- 近5日最高價
- 收盤價 + ATR × 1.5
- 布林上軌

篩選規則：目標價需介於收盤價 ~ 收盤價 ×1.10 之間。Fallback：收盤價 × 1.03。

#### 停損價

優先順序：
1. 收盤價 - ATR × 1.0
2. 近5日最低價（不低於收盤 × 0.985）
3. Fallback：收盤價 × 0.985

#### 當沖漲跌停限價

所有價格計算完成後，一律夾在當日漲跌停範圍內（台股 ±10%）：

```
漲停價 = tickRound(前日收盤 × 1.10, 向下取整至升降單位)
跌停價 = tickRound(前日收盤 × 0.90, 向上取整至升降單位)

建議買入 = clamp(建議買入, 跌停價, 漲停價)
目標獲利 = clamp(目標獲利, 跌停價, 漲停價)
停損價   = clamp(停損價,   跌停價, 漲停價)
```

台股升降單位（tick size）：

| 股價區間     | 升降單位 |
|-------------|---------|
| < 10        | 0.01    |
| 10 ~ 50     | 0.05    |
| 50 ~ 100    | 0.10    |
| 100 ~ 500   | 0.50    |
| 500 ~ 1000  | 1.00    |
| >= 1000     | 5.00    |

#### 風報比

```
獲利空間 = 目標價 - 建議買入價
虧損空間 = 建議買入價 - 停損價
風報比 = 獲利空間 / 虧損空間
```

低於 1.5 的標的不列入候選。

### 2.5 消息面情緒修正

定義於 `StockScreener::calcNewsSentimentFactor()`。選股時讀取最新 `NewsIndex`，計算修正係數。

#### 評分調整

| 條件                      | 預設閾值 | 分數調整 |
|--------------------------|---------|---------|
| 整體情緒偏空              | < 40    | -10     |
| 整體情緒偏多              | > 65    | +10     |
| 恐慌指標高                | > 60    | -5      |
| 產業情緒偏空              | < 35    | -5      |
| 產業情緒偏多              | > 65    | +5      |
| **合計上下限**            |         | ±15     |

#### 價格修正係數 (price_factor)

| 條件            | 預設係數 | 效果                          |
|----------------|---------|-------------------------------|
| 情緒偏空        | ×0.90   | 目標價獲利空間打9折            |
| 情緒偏多        | ×1.05   | 目標價獲利空間放寬5%           |
| 恐慌高          | ×0.92   | 額外壓縮8%                    |
| 產業偏空        | -0.05   | 再減5%                        |
| 產業偏多        | +0.05   | 再加5%                        |
| **係數範圍**    |         | 0.85 ~ 1.10                  |

修正公式：
```
目標價 = 建議買入 + (原目標價 - 建議買入) × price_factor
停損（偏空時）= 建議買入 - (建議買入 - 原停損) × (2.0 - price_factor)
```

所有閾值可透過 `FormulaSetting` type = `news_sentiment` 配置：

```json
{
  "bearish_below": 40,
  "bullish_above": 65,
  "panic_above": 60,
  "bearish_factor": 0.90,
  "bullish_factor": 1.05,
  "panic_factor": 0.92,
  "bearish_score": -10,
  "bullish_score": 10,
  "industry_bearish_below": 35,
  "industry_bullish_above": 65,
  "industry_factor": 0.05
}
```

---

## 3. AI 選股審核

定義於 `backend/app/Services/AiScreenerService.php`。每日 08:00 由 `stock:ai-screen` 指令執行。

### 流程

1. **規則式寬篩**（StockScreener, min_score=45）→ 產出 30-50 檔候選池
2. **AI 審核選股**（AiScreenerService）→ 從候選池中選出 10-15 檔
3. AI 為每檔標的給出：策略標籤（intraday_strategy）、參考支撐/壓力位、加減分、選股理由

### 策略標籤

| 標籤 | 說明 |
|------|------|
| breakout_fresh | 首次突破 |
| breakout_retest | 突破回測 |
| gap_pullback | 跳空拉回 |
| bounce | 跌深反彈 |
| momentum | 量能動能 |

### Fallback

API 失敗時自動降級為規則式：取 score 前 15 名，依 strategy_type 給預設策略。

---

## 3.5. 盤前確認規則（已由 AI 開盤校準取代）

> **注意**：09:35 的 `stock:screen-morning` 排程已停用，由 09:05 的 AI 開盤校準取代。
> MorningScreener 類別和指令保留可手動執行，其 4 條規則作為 AI 校準的 fallback 邏輯。

定義於 `backend/app/Services/MorningScreener.php`。

### 確認規則

#### 基本四規則（計分用）

| #  | 規則           | 條件                           | 分數 |
|----|---------------|-------------------------------|------|
| 1  | 預估量爆發     | 預估成交量 > 昨量 × 1.5 倍     | 30   |
| 2  | 開盤開高       | 開盤漲幅介於 2% ~ 5%           | 25   |
| 3  | 突破首根5分K   | 現價 > 第一根5分K高點           | 25   |
| 4  | 外盤比         | 外盤比 > 55%                   | 20   |

#### 額外驗證規則（否決用）

| #  | 規則           | 條件                                      | 效果                          |
|----|---------------|------------------------------------------|-------------------------------|
| 5  | 跳空風險       | 開盤漲幅 > 7%                             | 否決通過（隔日沖風險過高）     |
| 6  | 支撐確認       | 突破型：現價需站穩買入價上方，盤中低點未跌破買入價×0.99 | 突破型未通過 → 否決           |

規則 6 僅適用於 `strategy_type = breakout` 的標的，跌深反彈型不受此規則約束。

### 通過條件

1. 基本四規則至少 3 項通過
2. 且「預估量爆發」必須通過（必要條件）
3. 若跳空風險未通過 → 強制否決
4. 若支撐確認未通過（僅限突破型）→ 否決

### 補充判讀

| 規則       | 補充說明                                       |
|-----------|-----------------------------------------------|
| 預估量     | >= 2.0 倍為「強勢爆量」，>= 1.5 倍為「量能放大」 |
| 開盤開高   | > 7% 有隔日沖風險；< 2% 漲幅偏小                |
| 突破5分K   | 跌破低點代表走勢轉弱                            |
| 外盤比     | >= 65% 極旺；45%~55% 均衡；< 45% 賣壓偏重       |
| 跳空風險   | 漲幅 > 7% 的標的，隔日沖賣壓極大，即使量能符合也不宜追 |
| 支撐確認   | 突破型標的的核心：過高後需回測確認支撐有效才進場   |

---

## 3.6 盤中 AI 監控系統

定義於 `backend/app/Services/MonitorService.php`、`IntradayAiAdvisor.php`，由 `stock:monitor-intraday` 指令驅動。

### 快照資料層

`backend/app/Services/TwseRealtimeClient.php` 負責 TWSE/TPEX MIS API 即時報價，20 股一批、500ms 間隔。

`stock:monitor-intraday` 每分鐘由排程觸發，內部依時段動態控頻：

| 時段 | 頻率 | 說明 |
|------|------|------|
| 09:00-09:30 | 每 1 分鐘 | 開盤初期高頻 |
| 09:30-10:30 | 每 2 分鐘 | 早盤 |
| 10:30-13:00 | 每 3 分鐘 | 盤中低頻 |
| 13:00-13:30 | 每 1 分鐘 | 尾盤高頻 |

快照寫入 `intraday_snapshots` 表（時序資料，always insert）。

### 狀態機

每檔 AI 選中的候選標的對應一筆 `CandidateMonitor`，狀態轉換如下：

```
pending → watching → entry_signal → holding → target_hit
                                            → stop_hit
                                            → trailing_stop
                                            → closed (時間停損/強制平倉)
         → skipped (AI 校準否決)
```

| 狀態 | 說明 |
|------|------|
| `pending` | 初始狀態，等待 AI 校準 |
| `watching` | AI 通過，觀察等待進場訊號 |
| `entry_signal` | 偵測到進場條件（價格到位 + 量能） |
| `holding` | 持有中，監控出場條件 |
| `target_hit` | 達標出場 |
| `stop_hit` | 觸停損出場 |
| `trailing_stop` | 移動停利觸發 |
| `closed` | 時間停損或 13:25 強制平倉 |
| `skipped` | AI 校準否決，不參與 |

### AI 開盤校準（09:05）

由 `IntradayAiAdvisor::openingCalibration()` 執行，取代原 MorningScreener。

- 接收所有候選標的 + 開盤快照，呼叫 Claude API 判斷哪些標的適合當天交易
- 為通過者設定 `watching` + 調整目標/停損；否決者設為 `skipped`
- 結果回寫 `morning_score` / `morning_confirmed` 保持前端相容

**Fallback**：API 失敗時使用 MorningScreener 四條規則（量能 ≥1.5 倍、開盤漲 2-5%、站上 5 分 K 高點、外盤比 >55%；跳空 >7% 否決）。

### 進場判定

由 `MonitorService::evaluateWatching()` 依策略標籤判斷：

| 策略 | 進場條件 |
|------|----------|
| breakout_fresh / momentum | 現價 > 參考壓力位 |
| breakout_retest / gap_pullback | 拉回至參考支撐位附近後反彈 |
| bounce | 觸及參考支撐位 + 出現反彈跡象 |

共同條件：量能充足（預估量比 ≥ 設定值）、外盤比合理、非弱勢走勢（連續 3+ 根下跌量縮判定為 weakness）。

### 出場判定

由 `MonitorService::evaluateHolding()` 執行：

| 條件 | 動作 |
|------|------|
| 現價 ≥ 目標價 | `target_hit` |
| 現價 ≤ 停損價 | `stop_hit` |
| 持有期最高價回落 50% | `trailing_stop`（移動停利） |
| 獲利 >2% 時提高停損 | 動態停損（不低於進場價） |
| 獲利 >4% 時進一步收緊停損 | 動態停損（至少鎖 2% 利潤） |
| 持有 >60 分鐘且獲利 <0.5% | `closed`（時間停損） |
| 13:25 | `closed`（強制平倉） |

### AI 滾動建議（依時段動態頻率）

由 `IntradayAiAdvisor::rollingAdvice()` 對所有 active monitors 執行：

| 時段 | 頻率 | 說明 |
|------|------|------|
| 09:05-09:30 | 每 10 分鐘 | 開盤最劇烈，需快速反應 |
| 09:30-10:30 | 每 15 分鐘 | 早盤仍活躍 |
| 10:30-13:00 | 每 20 分鐘 | 盤中趨緩 |
| 13:00-13:25 | 每 10 分鐘 | 尾盤平倉決策 |

一天約 20 次 AI call，Haiku 約 NT$15-25/天。

- 接收近期快照 + 持倉狀態，呼叫 Claude API 給出 hold/adjust_target/adjust_stop/close 建議
- MonitorService 根據建議調整目標/停損
- Fallback：回傳 `{action: 'hold'}`

### Telegram 通知

所有狀態轉換（進場、出場、校準結果、AI 建議）皆發送 Telegram 通知。

---

## 4. 消息面指數

### 資料來源

每日 06:00 / 08:00 / 12:00 / 18:00 透過 `news:fetch` 抓取，`news:compute-indices` 計算。

### 指數定義 (NewsIndex)

| 指數           | 欄位            | 範圍    | 說明           |
|---------------|----------------|---------|---------------|
| 情緒指標       | `sentiment`    | 0 ~ 100 | 整體市場情緒    |
| 熱度指標       | `heatmap`      | 0 ~ 100 | 新聞關注度      |
| 恐慌指標       | `panic`        | 0 ~ 100 | 市場恐慌程度    |
| 國際風向       | `international`| 0 ~ 100 | 國際市場氛圍    |

### 分類

| scope      | 說明                         |
|-----------|------------------------------|
| `overall` | 整體市場（單筆）              |
| `industry`| 按產業分（多筆，`scope_value` = 產業名） |

---

## 5. 回測系統

### 5.1 盤後結果回填（`stock:update-results`）

定義於 `UpdateCandidateResults`，每日 **15:00** 收盤後自動執行。

**觸發條件：** 當日有候選標的（`candidates`）且尚未建立對應結果（`candidate_results`）。

**判定邏輯：**

| 欄位                 | 計算方式                                                    |
|---------------------|-----------------------------------------------------------|
| `actual_open`       | 當日 `daily_quotes.open`                                   |
| `actual_high`       | 當日 `daily_quotes.high`                                   |
| `actual_low`        | 當日 `daily_quotes.low`                                    |
| `actual_close`      | 當日 `daily_quotes.close`                                  |
| `hit_target`        | 當日最高價 ≥ 候選標的的 `target_price` → `true`             |
| `hit_stop_loss`     | 當日最低價 ≤ 候選標的的 `stop_loss` → `true`                |
| `max_profit_percent`| `(high - suggested_buy) / suggested_buy × 100`             |
| `max_loss_percent`  | `(suggested_buy - low) / suggested_buy × 100`              |
| `buy_reachable`     | 當日最低價 ≤ 建議買入價 → `true`                            |
| `target_reachable`  | 當日最高價 ≥ 目標價 → `true`                                |
| `buy_gap_percent`   | `(suggested_buy - low) / suggested_buy × 100`（正值=買得到）|
| `target_gap_percent`| `(high - target_price) / target_price × 100`（正值=超過目標）|

**注意事項：**
- 需要當日 `daily_quotes` 資料才能計算（依賴 14:30 的 `stock:fetch-daily`）
- 若當日無行情資料（如該股停牌），則跳過不建立結果
- 已有結果的候選標的不會重複計算（`whereDoesntHave('result')`）

### 5.2 回測核心指標

定義於 `BacktestService::computeMetrics()`，由 `CandidateController::stats()` 呼叫。

| 指標           | 欄位                | 計算方式                                                      |
|---------------|--------------------|------------------------------------------------------------- |
| 候選標的數     | `total_candidates` | 期間內 `candidates` 總筆數                                     |
| 已驗證         | `evaluated`        | 有對應 `candidate_results` 的筆數                               |
| 買入可達率     | `buy_reach_rate`   | `buy_reachable = true` 數 / 已驗證數 × 100                    |
| 目標可達率     | `target_reach_rate`| `target_reachable = true` 數 / 已驗證數 × 100                  |
| 雙達率         | `dual_reach_rate`  | 同時 `buy_reachable AND target_reachable` 數 / 已驗證數 × 100  |
| 期望值         | `expected_value`   | 見下方計算公式                                                  |
| 停損觸及率     | `hit_stop_loss_rate`| `hit_stop_loss = true` 數 / 已驗證數 × 100                    |
| 平均買入間距   | `avg_buy_gap`      | 所有已驗證標的 `buy_gap_percent` 平均值                         |
| 平均目標間距   | `avg_target_gap`   | 所有已驗證標的 `target_gap_percent` 平均值                      |
| 平均風報比     | `avg_risk_reward`  | 所有已驗證標的 `risk_reward_ratio` 平均值                       |

#### 期望值計算公式

對每筆已驗證候選標的，依情境計算該筆模擬損益：

```
if buy_reachable AND target_reachable:
    profit = (target_price - suggested_buy) / suggested_buy × 100
elif buy_reachable AND hit_stop_loss:
    profit = -(suggested_buy - stop_loss) / suggested_buy × 100
elif buy_reachable:
    profit = (actual_close - suggested_buy) / suggested_buy × 100
else:
    profit = 0（未買到不計算）

expected_value = avg(所有 buy_reachable 為 true 的 profit)
```

#### 監控系統指標（有 monitor 資料時額外計算）

| 指標 | 欄位 | 計算方式 |
|------|------|----------|
| AI 通過率 | `ai_approval_rate` | AI 選中（`ai_selected`）數 / 候選總數 × 100 |
| 有效進場率 | `valid_entry_rate` | `valid_entry = true` 數 / 已驗證數 × 100 |
| 平均 MFE | `avg_mfe` | 持有期間最大有利偏移（%）平均 |
| 平均 MAE | `avg_mae` | 持有期間最大不利偏移（%）平均 |
| 弱勢轉換率 | `weak_to_price_rate` | 弱勢走勢到達停損的比率 |
| 有效進場期望值 | `profit_if_valid_entry` | 只算有效進場的平均損益（%） |
| 平均持有時間 | `avg_holding_minutes` | 進場到出場的平均分鐘數 |
| AI 介入準確率 | `ai_override_accuracy` | AI 調整後結果為達標或停利的比率（%） |
| 改良版風報比 | `effective_rr` | `(target_hit_rate × avg_profit_per_hit) / (stop_hit_rate × avg_loss_per_hit)` |

這些指標僅在系統產生 monitor 資料後才會出現（向後相容）。

#### 策略分類分析

指標可依策略類型（`bounce` / `breakout`）分別統計，回傳於 `by_strategy` 欄位。

#### 日別趨勢

回傳 `daily` 陣列，每日包含 `buy_reach_rate`、`target_reach_rate`、`dual_reach_rate`，供前端繪製趨勢圖。

### 5.3 選股品質指標

定義於 `BacktestService::calcScreeningMetrics()`，隨回測指標一同計算，供 AI 優化選股邏輯使用。

| 指標                     | 欄位                      | 說明                                                    |
|-------------------------|--------------------------|--------------------------------------------------------|
| 平均評分                 | `screening.avg_score`     | 所有候選標的的平均 `score`                                |
| 每日候選數               | `screening.candidates_per_day` | 候選總數 / 交易天數                                  |
| 策略分布                 | `screening.strategy_distribution` | 各策略（bounce/breakout）的候選數量               |
| 選股理由觸發頻率         | `screening.reason_frequency` | 各 `reasons` 出現的百分比（了解哪些評分因子活躍）      |
| 依結果分組平均分數       | `screening.avg_score_by_outcome` | win（雙達）/ loss（觸停損）/ miss（未買到）的平均分數 |

#### 選股品質解讀

- **win 和 loss 平均分數接近**：評分系統區辨力不足，無法有效區分好壞標的，需調整權重分布
- **miss 平均分數高**：高分股買不到，代表價格公式偏保守需放寬
- **某理由觸發率極高**：該因子普遍性太強，對篩選無實質貢獻，考慮降低權重或收緊門檻
- **某理由觸發率極低**：門檻可能太嚴，可考慮放寬讓更多股票受益

### 5.4 AI 優化（`stock:backtest`）

定義於 `BacktestOptimizer`，可透過指令或 API 觸發。每次只優化**一類參數**（價格 / 評分 / 門檻）。

```bash
# 僅查看回測指標（含選股品質）
php artisan stock:backtest --from=2026-03-01 --to=2026-04-09

# 執行 AI 優化分析（單次，不自動套用）
php artisan stock:backtest --from=2026-03-01 --to=2026-04-09 --optimize

# 分析並自動套用建議（單次）
php artisan stock:backtest --from=2026-03-01 --to=2026-04-09 --optimize --apply

# 帶驗證的優化循環（建議 → 重跑 → 比較 → 回滾重試，最多10次）
php artisan stock:backtest --validated
php artisan stock:backtest --validated --max-attempts=5
```

**核心原則：一次只調一類參數。** 同時調整價格 + 評分 + 門檻會導致結果不可控（改評分會改變選哪些股票，使價格指標失去參考意義）。AI 每次從以下三類中選擇問題最嚴重的一類調整：

1. **價格公式**（suggested_buy / target_price / stop_loss）— 買入/目標可達率異常時
2. **選股評分**（scoring / strategy）— 評分區辨力不足時
3. **篩選門檻**（screen_thresholds）— 候選數太多或太少時

#### 帶驗證的優化循環（`--validated`）

排程預設使用此模式，確保每次調整都經過驗證，不會盲目套用：

1. 保存目前參數快照
2. 用目前參數重跑過去 60 天的選股 + 結果回填，取得 **baseline 指標**
3. 迴圈（最多 10 次）：
   a. AI 分析當前指標，建議一類參數調整
   b. 套用建議
   c. 重跑同期間選股 + 結果回填，取得**新指標**
   d. 加權比較（期望值 ×3 + 雙達率 ×0.5 + 買入可達率 ×0.1 - 停損率 ×0.1）
   e. 若改善 → 採用，繼續嘗試下一輪
   f. 若未改善 → 回滾參數，重新嘗試
4. 最終使用歷次嘗試中的最佳參數

#### 單次優化流程（`--optimize`）

1. 計算指定期間的回測指標（含選股品質指標）
2. 讀取目前所有 `FormulaSetting` 參數（含 `scoring`、`strategy`）
3. 呼叫 Claude API 分析數據，選擇一類參數建議調整（無 API key 時降級為規則式分析）
4. 將分析結果存入 `backtest_rounds` 表
5. 若指定 `--apply`，自動套用建議到 `formula_settings`

**AI 可調整的參數範圍：**

| 類型            | 說明                                           | 範例參數                                              |
|----------------|-----------------------------------------------|------------------------------------------------------|
| `suggested_buy` | 買入價公式                                     | `fallback_pct`, `sources.atr.multiplier`              |
| `target_price`  | 目標價公式                                     | `fallback_pct`, `filter_upper_pct`                    |
| `stop_loss`     | 停損價公式                                     | `fallback_pct`, `sources.atr.multiplier`              |
| `scoring`       | 評分因子權重與閾值                              | `volume_surge.score`, `rsi_moderate.min`, `high_volatility.min_amplitude` |
| `strategy`      | 策略分類參數                                    | `bounce.score`, `bounce.washout_drop_pct`, `breakout.near_breakout_pct` |

**規則式降級邏輯（價格公式）：**

| 條件                              | 調整方向                                 |
|----------------------------------|----------------------------------------|
| 買入可達率 < 50%                  | 提高 `suggested_buy.fallback_pct`       |
| 買入可達率 > 80% 且間距 > 2%      | 降低 `suggested_buy.fallback_pct`       |
| 目標可達率 < 40%                  | 降低 `target_price.fallback_pct`        |
| 目標可達率 > 75% 且間距 > 2%      | 提高 `target_price.fallback_pct`        |
| 停損觸及率 > 50%                  | 提高 `stop_loss.fallback_pct`（收緊）    |

**規則式降級邏輯（選股）：**

| 條件                                    | 調整方向                                             |
|-----------------------------------------|----------------------------------------------------|
| win/loss 平均分數差距 < 5 分             | 提高 `volume_surge.score` 和 `ma_bullish.score` 增加區辨力 |
| 跌深反彈策略期望值 < -1% 且明顯劣於突破  | 降低 `strategy.bounce.score`                         |
| 突破追多策略期望值 < -1% 且明顯劣於反彈  | 降低 `strategy.breakout.score`                       |
| 每日候選數 < 5                           | 放寬 `high_volatility.min_amplitude`                 |
| 每日候選數 > 15                          | 收緊 `volume_surge.ratio`                            |

**排程：** 每週一 07:00 自動執行帶驗證的優化循環（`stock:backtest --validated`，過去 60 天，最多 10 次嘗試）。

### 5.5 單日 AI 檢討報告（`/api/backtest/daily-review`）

定義於 `DailyReviewService`，透過前端 StatsView 的「單日 AI 檢討」區塊觸發。

**用途：** 針對單一交易日的候選標的，分析每檔為什麼買入可達/不可達、目標可達/不可達。不做參數調整，只產出診斷報告。

**資料來源：**

| 資料 | 用途 |
|------|------|
| `candidates` + `candidate_results` | 盤前設定 vs 盤後實際結果 |
| `intraday_quotes` | 盤中行情快照（量能、開盤位階、外盤比） |
| `daily_quotes`（近 5 日） | K 線走勢、成交量變化 |
| `news_indices` | 當日消息面情緒 |

**報告結構：**

1. **當日總覽** — 大盤氛圍、消息面影響、整體表現
2. **逐檔分析** — 每檔的盤前設定合理性、盤中表現、達標/未達標原因、最佳進場時機、AI 監控決策是否合理
3. **共通問題** — 系統性模式（買入價偏高/偏低、特定策略偏差、AI 決策品質）
4. **AI 決策檢討** — MFE vs 實際出場利潤比較、AI 否決標的事後表現、滾動建議品質
5. **改善建議** — 具體可改善方向（不含參數調整）

**額外資料來源：** `candidate_monitors`（AI 監控軌跡：狀態、進出場、MFE/MAE、校準備註、AI 建議摘要）

**實作：** SSE 串流，前端透過 EventSource 接收 `log`（進度）、`chunk`（AI 串流文字片段）和 `done`（最終報告）事件。

---

## 6. 資料表結構摘要

| 資料表                 | 用途               | 關鍵欄位                                              |
|-----------------------|-------------------|----------------------------------------------------- |
| `stocks`              | 股票基本資料       | `symbol`, `name`, `industry`, `is_day_trading`        |
| `daily_quotes`        | 每日行情           | `stock_id`, `date`, OHLCV, `change_percent`, `amplitude` |
| `institutional_trades`| 三大法人           | `stock_id`, `date`, `foreign_net`, `trust_net`, `dealer_net`, `total_net` |
| `margin_trades`       | 融資融券           | `stock_id`, `date`, `margin_change`                   |
| `intraday_quotes`     | 盤中即時行情       | `stock_id`, `date`, `current_price`, `estimated_volume_ratio`, `open_change_percent`, `first_5min_high`, `first_5min_low`, `external_ratio` |
| `candidates`          | 候選標的           | `stock_id`, `trade_date`, `suggested_buy`, `target_price`, `stop_loss`, `risk_reward_ratio`, `score`, `strategy_type`, `strategy_detail`, `reasons`, `morning_*`, `ai_selected`, `ai_score_adjustment`, `ai_reasoning`, `intraday_strategy`, `reference_support`, `reference_resistance`, `ai_warnings` |
| `candidate_results`   | 盤後結果           | `candidate_id`, `actual_open/high/low/close`, `hit_target`, `hit_stop_loss`, `max_profit_percent`, `max_loss_percent`, `buy_reachable`, `target_reachable`, `buy_gap_percent`, `target_gap_percent`, `entry_time`, `exit_time`, `entry_price_actual`, `exit_price_actual`, `entry_type`, `mfe_percent`, `mae_percent`, `valid_entry`, `monitor_status` |
| `intraday_snapshots`  | 盤中時序快照       | `stock_id`, `trade_date`, `snapshot_time`, `open`, `high`, `low`, `current_price`, `prev_close`, `accumulated_volume`, `estimated_volume_ratio`, `open_change_percent`, `buy_volume`, `sell_volume`, `external_ratio`, `best_ask`, `best_bid`, `change_percent`, `amplitude_percent` |
| `candidate_monitors`  | AI 監控狀態機      | `candidate_id`(unique), `status`, `entry_price`, `entry_time`, `entry_type`, `exit_price`, `exit_time`, `current_target`, `current_stop`, `ai_calibration`(JSON), `ai_advice_log`(JSON), `state_log`(JSON), `last_ai_advice_at`, `skip_reason` |
| `backtest_rounds`     | 回測優化紀錄       | `analyzed_from`, `analyzed_to`, `sample_count`, `metrics_before` (JSON), `metrics_after` (JSON), `suggestions` (JSON), `applied`, `applied_at` |
| `screening_rules`     | 自訂篩選規則       | `conditions` (JSON), `is_active`, `sort_order`        |
| `formula_settings`    | 公式參數設定       | `type`, `config` (JSON)                               |
| `news_articles`       | 新聞文章           | `source`, `title`, `url`, `industry`, `sentiment_score`, `sentiment_label`, `ai_analysis`, `fetched_date`, `published_at` |
| `news_indices`        | 新聞指數           | `date`, `scope`, `scope_value`, `sentiment`, `heatmap`, `panic`, `international`, `article_count` |

---

## 7. API 端點

| 方法   | 路徑                      | 說明                   |
|--------|--------------------------|------------------------|
| GET    | `/api/candidates`         | 候選標的列表（含盤前確認、盤後結果） |
| GET    | `/api/candidates/dates`   | 有資料的日期列表        |
| GET    | `/api/candidates/stats`   | 回測統計指標            |
| GET    | `/api/candidates/morning` | 盤前確認結果            |
| GET    | `/api/candidates/monitors`| 盤中監控狀態（含現價、損益%） |
| GET    | `/api/candidates/{id}`    | 單一候選標的詳情        |
| GET    | `/api/candidates/{id}/snapshots` | 候選標的盤中快照  |
| GET    | `/api/candidates/{id}/monitor`   | 單檔監控詳情（含 state_log、ai_advice_log） |
| GET    | `/api/stocks`             | 股票列表               |
| GET    | `/api/stocks/{id}`        | 股票詳情               |
| GET    | `/api/stocks/{id}/kline`  | K線資料                |
| GET    | `/api/stocks/{id}/detail` | 股票完整資訊            |
| GET    | `/api/screening-rules`    | 篩選規則 CRUD          |
| GET    | `/api/formula-settings`   | 公式設定               |
| PUT    | `/api/formula-settings/{type}` | 更新公式設定      |
| POST   | `/api/data-sync`          | 手動觸發資料同步        |
| GET    | `/api/news/dashboard`     | 消息面儀表板            |
| POST   | `/api/news/fetch`         | 手動觸發新聞抓取        |
| GET    | `/api/news/fetch-status`  | 新聞抓取進度            |
| GET    | `/api/backtest/rounds`    | 回測優化歷史列表        |
| POST   | `/api/backtest/optimize`  | 觸發 AI 優化分析        |
| POST   | `/api/backtest/rounds/{id}/apply` | 套用優化建議  |
| GET    | `/api/backtest/daily-review` | 單日 AI 檢討報告（SSE） |
| GET    | `/api/spec`               | 系統規格文件（SPEC.md）  |

---

## 8. FormulaSetting 類型一覽

| type              | 用途               | 使用位置                    |
|-------------------|-------------------|-----------------------------|
| `suggested_buy`   | 建議買入價參數      | `StockScreener::calcSuggestedBuy()` |
| `target_price`    | 目標價參數          | `StockScreener::calcTargetPrice()`  |
| `stop_loss`       | 停損價參數          | `StockScreener::calcStopLoss()`     |
| `strategy`        | 策略分類參數        | `StockScreener::classifyStrategy()` |
| `scoring`         | 評分項目參數        | `StockScreener::screen()`           |
| `news_sentiment`  | 消息面修正參數      | `StockScreener::calcNewsSentimentFactor()` |

---

## 9. 前端頁面

| 路由       | 元件                | 說明                |
|-----------|---------------------|---------------------|
| `/`       | `CandidatesView`    | 當沖候選標的（主頁） |
| `/history`| `HistoryView`       | 歷史紀錄             |
| `/stats`  | `StatsView`         | 回測分析             |
| `/news`   | `NewsView`          | 消息面               |
| `/settings`| `SettingsView`     | 篩選設定             |
| `/spec`   | `SpecView`          | 系統規格書            |
| `/stock/:id` | `StockDetailView`| 個股詳情（K線）      |

---

*最後更新：2026-04-11*

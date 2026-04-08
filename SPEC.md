# 系統規格書

> 本文件為系統完整規格，任何規則、排程、公式的異動都必須同步更新此文件。

---

## 1. 每日排程

排程定義於 `backend/routes/console.php`。

| 時間  | 指令                        | 說明                           |
|-------|-----------------------------|-------------------------------|
| 06:00 | `news:fetch`                | 抓取隔夜國際新聞               |
| 06:15 | `news:compute-indices`      | 計算新聞指數（供選股用）        |
| 08:00 | `stock:screen-candidates`   | 執行選股篩選，產出當日候選清單（含消息面修正，預設 date = 今天） |
| 08:00 | `news:fetch`                | 開盤前新聞抓取                 |
| 08:15 | `news:compute-indices`      | 計算新聞指數                   |
| 09:05 | `stock:fetch-intraday`      | 盤中即時行情（5分K）           |
| 09:30 | `stock:fetch-intraday`      | 盤中即時行情（30分鐘後狀態）   |
| 09:35 | `stock:screen-morning`      | 盤前確認篩選                   |
| 12:00 | `news:fetch`                | 午間新聞抓取                   |
| 12:15 | `news:compute-indices`      | 計算新聞指數                   |
| 14:30 | `stock:fetch-daily`         | 收盤後抓取每日行情             |
| 15:00 | `stock:update-results`      | 更新前日候選標的的盤後結果     |
| 16:00 | `stock:fetch-institutional` | 抓取三大法人買賣超             |
| 16:30 | `stock:fetch-margin`        | 抓取融資融券                   |
| 18:00 | `news:fetch`                | 盤後新聞抓取                   |
| 18:15 | `news:compute-indices`      | 計算新聞指數                   |
| 22:00 | `stock:health-check`        | 健康檢查（確認當日資料完整）   |

### 資料依賴流程

```
14:30 行情 ──┐
16:00 法人 ──┤
16:30 融資 ──┼── 06:00 隔夜新聞 → 06:15 算指數 → 08:00 產生候選標的
18:00 新聞 ──┘                                         │
                                                       ↓
                                    09:05/09:30 盤中行情 → 09:35 盤前確認
                                                                │
                                                       15:00 盤後結果回填
```

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
| 11 | 突破前高       | 10      | 收盤價 > 前5日最高價                    | `break_prev_high`   |
| 12 | 布林中軌上方   | 5       | 收盤價介於布林中軌與上軌之間            | `bollinger_position`|
| 13 | 高波動         | 10      | 近10日平均振幅 >= 5%                    | `high_volatility`   |
| 14 | 近月強勢       | 10      | 近20日漲幅 > 15%                       | `strong_trend`      |
| 15 | 跌深反彈       | 15      | 策略分類為 bounce（見 §2.3）            | `strategy.bounce`   |
| 16 | 突破追多       | 15      | 策略分類為 breakout（見 §2.3）          | `strategy.breakout` |
| 17 | 外資大買       | 5       | 外資淨買 > 當日成交量 × 5%             | `foreign_big_buy`   |
| 18 | 自營大買       | 5       | 自營淨買 > 當日成交量 × 3%             | `dealer_big_buy`    |
| 19 | 萬張量能       | 5       | 成交量 >= 10,000 張                    | `high_volume`       |
| 20 | 消息面情緒     | ±10~15  | 依消息面指數調整（見 §2.5）             | `news_sentiment`    |

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

## 3. 盤前確認規則

定義於 `backend/app/Services/MorningScreener.php`。每日 09:35 執行，對當日候選標的做開盤後驗證。

### 四大確認規則

| #  | 規則           | 條件                           | 分數 |
|----|---------------|-------------------------------|------|
| 1  | 預估量爆發     | 預估成交量 > 昨量 × 1.5 倍     | 30   |
| 2  | 開盤開高       | 開盤漲幅介於 2% ~ 5%           | 25   |
| 3  | 突破首根5分K   | 現價 > 第一根5分K高點           | 25   |
| 4  | 外盤比         | 外盤比 > 55%                   | 20   |

### 通過條件

- 至少 3 項規則通過
- 且「預估量爆發」必須通過（必要條件）

### 補充判讀

| 規則       | 補充說明                                       |
|-----------|-----------------------------------------------|
| 預估量     | >= 2.0 倍為「強勢爆量」，>= 1.5 倍為「量能放大」 |
| 開盤開高   | > 7% 有隔日沖風險；< 2% 漲幅偏小                |
| 突破5分K   | 跌破低點代表走勢轉弱                            |
| 外盤比     | >= 65% 極旺；45%~55% 均衡；< 45% 賣壓偏重       |

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

## 5. 實際表現與績效統計

### 5.1 盤後結果回填（`stock:update-results`）

定義於 `UpdateCandidateResults`，每日 **15:00** 收盤後自動執行。

**觸發條件：** 當日有候選標的（`candidates`）且尚未建立對應結果（`candidate_results`）。

**判定邏輯：**

| 欄位                | 計算方式                                                    |
|--------------------|-----------------------------------------------------------|
| `actual_open`      | 當日 `daily_quotes.open`                                   |
| `actual_high`      | 當日 `daily_quotes.high`                                   |
| `actual_low`       | 當日 `daily_quotes.low`                                    |
| `actual_close`     | 當日 `daily_quotes.close`                                  |
| `hit_target`       | 當日最高價 ≥ 候選標的的 `target_price` → `true`             |
| `hit_stop_loss`    | 當日最低價 ≤ 候選標的的 `stop_loss` → `true`                |
| `max_profit_percent` | `(high - suggested_buy) / suggested_buy × 100`            |
| `max_loss_percent` | `(suggested_buy - low) / suggested_buy × 100`              |

**注意事項：**
- 需要當日 `daily_quotes` 資料才能計算（依賴 14:30 的 `stock:fetch-daily`）
- 若當日無行情資料（如該股停牌），則跳過不建立結果
- 已有結果的候選標的不會重複計算（`whereDoesntHave('result')`）

### 5.2 績效統計

定義於 `CandidateController::stats()`。

| 指標         | 計算方式                                        |
|-------------|------------------------------------------------|
| 候選標的數   | 期間內 `candidates` 總筆數                       |
| 已驗證       | 有對應 `candidate_results` 的筆數                |
| 命中率       | `hit_target = true` 數 / 已驗證數 × 100         |
| 平均最高獲利 | 所有已驗證標的 `max_profit_percent` 的平均值      |

---

## 6. 資料表結構摘要

| 資料表                 | 用途               | 關鍵欄位                                              |
|-----------------------|-------------------|----------------------------------------------------- |
| `stocks`              | 股票基本資料       | `symbol`, `name`, `industry`, `is_day_trading`        |
| `daily_quotes`        | 每日行情           | `stock_id`, `date`, OHLCV, `change_percent`, `amplitude` |
| `institutional_trades`| 三大法人           | `stock_id`, `date`, `foreign_net`, `trust_net`, `dealer_net`, `total_net` |
| `margin_trades`       | 融資融券           | `stock_id`, `date`, `margin_change`                   |
| `intraday_quotes`     | 盤中即時行情       | `stock_id`, `date`, `current_price`, `estimated_volume_ratio`, `open_change_percent`, `first_5min_high`, `first_5min_low`, `external_ratio` |
| `candidates`          | 候選標的           | `stock_id`, `trade_date`, `suggested_buy`, `target_price`, `stop_loss`, `risk_reward_ratio`, `score`, `strategy_type`, `strategy_detail`, `reasons`, `morning_*` |
| `candidate_results`   | 盤後結果           | `candidate_id`, `actual_open/high/low/close`, `hit_target`, `hit_stop_loss`, `max_profit_percent`, `max_loss_percent` |
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
| GET    | `/api/candidates/stats`   | 績效統計               |
| GET    | `/api/candidates/morning` | 盤前確認結果            |
| GET    | `/api/candidates/{id}`    | 單一候選標的詳情        |
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
| `/stats`  | `StatsView`         | 績效統計             |
| `/news`   | `NewsView`          | 消息面               |
| `/settings`| `SettingsView`     | 篩選設定             |
| `/spec`   | `SpecView`          | 系統規格書            |
| `/stock/:id` | `StockDetailView`| 個股詳情（K線）      |

---

*最後更新：2026-04-08*

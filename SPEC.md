# 系統規格書

> 本文件為系統完整規格，任何規則、排程、公式的異動都必須同步更新此文件。

---

## 1. 每日排程

排程定義於 `backend/routes/console.php`。

| 時間  | 指令                        | 說明                                                        |
|-------|-----------------------------|-----------------------------------------------------------|
| 06:00 | `stock:fetch-us-indices`    | 抓取美股指數 + 台指期夜盤（S&P 500、費半、道瓊、那斯達克、美元指數、台指期）               |
| 06:00 | `news:fetch`                | 抓取隔夜國際新聞                                                  |
| 06:15 | `news:compute-indices`      | 計算新聞指數（供選股用）                                              |
| 08:00 | `stock:ai-screen`           | 三階段 AI 選股：物理門檻寬篩（top 80）→ Haiku 批量預篩（→ 最多 30 檔）→ Opus 精審，最終選出 10–15 檔 |
| 08:45 | `stock:fetch-us-indices --tx-only` | 更新台指期日盤開盤價（日盤 08:45 開盤，確保候選頁顯示當日盤中即時價而非夜盤收盤價）             |
| 08:00 | `news:fetch`                | 開盤前新聞抓取                                                   |
| 08:15 | `news:compute-indices`      | 計算新聞指數                                                    |
| 09:05 | `stock:fetch-intraday`      | 盤中即時行情（5分K）                                               |
| 09:30 | `stock:fetch-intraday`      | 盤中即時行情（30分鐘後狀態）                                           |
| 09:00-13:30 | `stock:monitor-intraday` | 盤中即時監控（每 30 秒快照；command 內部 loop，scheduler 每分鐘觸發作為當機重啟保底） |
| 12:00 | `news:fetch`                | 午間新聞抓取                                                    |
| 12:15 | `news:compute-indices`      | 計算新聞指數                                                    |
| **12:45** | **`stock:fetch-sector-indices`** | **抓取 TWSE 類股指數（供隔日沖選股使用）** |
| **12:50** | **`stock:ai-screen-overnight`** | **隔日沖三階段 AI 選股（用今日盤中資料選明日建倉標的）** |
| 14:30 | `stock:fetch-daily`         | 收盤後抓取每日行情                                                 |
| 15:00 | `stock:update-results`      | 更新當日當沖候選標的的盤後結果                                           |
| **15:05** | **`stock:update-overnight-results`** | **更新隔日沖候選標的盤後實際結果（T+1 收盤後）** |
| 15:30 | `stock:daily-review`        | 自動產出當日 AI 檢討報告（依賴 15:00 結果回填，不含教訓萃取）                     |
| **15:35** | **`stock:daily-review --mode=overnight`** | **自動產出隔日沖 AI 檢討報告（不含教訓萃取）** |
| 16:30 | `stock:fetch-institutional` | 抓取三大法人買賣超（TWSE 約 16:15~16:30 上線）                          |
| 17:00 | `stock:fetch-margin`        | 抓取融資融券                                                    |
| **17:15** | **`stock:fetch-valuations`** | **從 TWSE 抓取本益比/殖利率/股價淨值比（BWIBBU_ALL），供隔日沖 Opus 估值判斷使用** |
| 18:00 | `news:fetch`                | 盤後新聞抓取                                                    |
| 18:15 | `news:compute-indices`      | 計算新聞指數                                                    |
| 22:00 | `stock:health-check`        | 健康檢查（資料完整性 + 卡住 monitor 強制收尾 + 當沖/隔日沖結果與檢討補跑 + API 連通性 + Log 大小警告） |
| 週日 03:00 | `stock:cleanup`             | 清理過期資料（快照保留 30 天、AI 教訓過期刪除）                               |
| **T+1 09:05–13:15** | **`stock:monitor-overnight-exit --slot={time}`** | **隔日沖 T+1 出場監控，每 15 分鐘一次（09:05/09:15 開盤快速檢查；Fugle 即時報價：目標/停損觸發自動終止；Sonnet 滾動判斷 hold/adjust/exit）** |
| **週日 22:00** | **`stock:compute-strategy-stats`** | **計算隔日沖/當沖策略量化績效統計（30/60 天窗口）** |

> `stock:backtest --validated` 已停用自動排程。指令保留可手動執行回測指標檢視。

### 資料依賴流程

**當沖流程：**
```
14:30 行情 ──┐
16:00 法人 ──┤
16:30 融資 ──┼── 06:00 隔夜新聞 → 06:15 算指數 → 08:00 AI 三階段選股
18:00 新聞 ──┘                                 │
                                               ├─ Step 1: 物理門檻寬篩（top 80 by 5日均量）
                                               ├─ Step 2: Haiku 批量預篩（→ 最多 30 檔）
                                               └─ Step 3: Opus 精審（→ 最終 10–15 檔）
                                                                │
                         09:00 開始盤中快照 → 09:05 AI 開盤校準
                                                                │
                         09:05+ 規則式持續監控 + AI 動態頻率滾動判斷（10-15 分鐘）
                                                                │
                                                       13:25 強制平倉 → 15:00 盤後結果回填
```

**隔日沖流程（T+0 → T+1）：**
```
16:00 法人（T-1）──┐
17:00 估值資料 ────┤
12:00 新聞（T+0）──┼── 12:45 類股指數 → 12:50 隔日沖 AI 三階段選股
12:50 盤中快照 ────┘                   │
                                       ├─ Step 1: overnight 物理篩選（top 100）
                                       ├─ Step 2: Haiku overnight（→ 最多 20 檔）
                                       └─ Step 3: Opus overnight（→ 設定三個價格）
                                                        │
                  13:00–13:25 使用者下單建倉（T+0）
                                                        │
                  T+1 09:05~13:15 每 15 分鐘出場監控（Fugle + Sonnet 滾動）
                                                        │
                  T+1 15:05 盤後結果回填 → 15:35 AI 隔日沖檢討
```

### 休市日檢查

`stock:ai-screen`、`stock:monitor-intraday`、`stock:update-results`、`stock:update-overnight-results`、`stock:daily-review` 開頭檢查 `MarketHoliday::isHoliday()`，週末或國定假日自動跳過（手動傳入 date 參數時不檢查）。

休市日資料由 `stock:import-holidays {year}` 指令匯入（每年更新一次），定義在 `ImportMarketHolidays.php` 內。

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

## 2. 物理門檻篩選

定義於 `backend/app/Services/StockScreener.php`。

StockScreener 只負責排除「物理不可能進行當沖」的標的，不做品質評分。所有品質判斷交由後續 AI 階段。

### 2.1 硬排除條件

| 條件 | 閾值 | 設定鍵 | 說明 |
|------|------|--------|------|
| 成交量 | < 500 張 | `min_volume` | 量能太低無法成交 |
| 股價 | < 10 元 | `min_price` | 過低股價滑價大 |
| 5日均振幅 | < 0.5% | `min_amplitude` | 振幅幾乎為零，絕對無當沖價值 |
| 5日均量 | < 200 張 | `min_day_trading_volume` | 流動性不足 |
| 風報比 | < 0.8 | `min_risk_reward` | 風險遠大於報酬 |

> 閾值故意寬鬆，避免在物理篩選階段誤殺好標的。品質層面的判斷全部交給 Haiku 和 Opus。

### 2.2 輸出

- 通過物理門檻的標的，依**5日均量降冪**排序，取前 **80** 檔（`max_candidates`）
- `score = 0`（Haiku 預篩後才有真正分數）
- `reasons` 存**事實標籤**（3–5 個，非評分理由）：

| 標籤 | 觸發條件 |
|------|---------|
| `量放大` | 前日成交量 > 5日均量 × 1.5 |
| `外資買超` | 最近一日外資淨買 > 0 |
| `投信買超` | 最近一日投信淨買 > 0 |
| `突破前高` | 前日收盤 > 前5日最高價 |
| `融資減` | 最近一日融資變化 < 0 |
| `多頭排列` | MA5 > MA10 > MA20 且收盤 > MA5（intraday 專用） |
| `空頭排列` | MA5 < MA10 < MA20 且收盤 < MA5（intraday 專用） |
| `均線糾結` | MA5/MA10/MA20 spread < 收盤價 × 1.5%（intraday 專用） |
| `均線混排` | 非多排/空排/糾結的其餘情況（intraday 專用） |

自訂規則（`ScreeningRule`）符合時，亦將規則名稱加入 `reasons` 標籤，不做硬排除。

### 2.3 價格計算

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

| 股價區間 | 升降單位 |
|---------|---------|
| < 10 | 0.01 |
| 10 ~ 50 | 0.05 |
| 50 ~ 100 | 0.10 |
| 100 ~ 500 | 0.50 |
| 500 ~ 1000 | 1.00 |
| >= 1000 | 5.00 |

#### 風報比

```
獲利空間 = 目標價 - 建議買入價
虧損空間 = 建議買入價 - 停損價
風報比 = 獲利空間 / 虧損空間
```

低於 0.8 的標的在物理門檻階段即排除（AI 會依實際行情重算價格）。

### 2.4 消息面情緒修正（價格調整）

定義於 `StockScreener::calcNewsSentimentFactor()`。讀取最新 `NewsIndex`，計算**價格修正係數**（不影響 score）。

#### 價格修正係數 (price_factor)

| 條件 | 預設係數 | 效果 |
|------|---------|------|
| 整體情緒偏空（< 40） | ×0.90 | 目標價獲利空間打9折 |
| 整體情緒偏多（> 65） | ×1.05 | 目標價獲利空間放寬5% |
| 恐慌指標高（> 60） | ×0.92 | 額外壓縮8% |
| 產業情緒偏空（< 35） | -0.05 | 再減5% |
| 產業情緒偏多（> 65） | +0.05 | 再加5% |
| **係數範圍** | | 0.85 ~ 1.10 |

修正公式：
```
目標價 = 建議買入 + (原目標價 - 建議買入) × price_factor
停損（偏空時）= 建議買入 - (建議買入 - 原停損) × (2.0 - price_factor)
```

所有閾值可透過 `FormulaSetting` type = `news_sentiment` 配置。

---

## 3. AI 選股審核（三階段流程）

每日 08:00 由 `stock:ai-screen` 指令執行，依序完成三個階段。

### 架構總覽

```
全市場股票
    │
    ▼ StockScreener（物理門檻）
物理門檻通過，top 80 by 5日均量
    │
    ▼ HaikuPreFilterService（批量預篩）
每批 15 檔 → 1 次 Haiku API call（system prompt 快取）
更新 score（信度 0–100）、haiku_selected、haiku_reasoning
最多 30 檔通過 → 進入 Opus
    │
    ▼ AiScreenerService（精審）
每檔獨立 1 次 Opus API call（system prompt 快取）
更新 ai_selected、ai_score_adjustment、ai_reasoning
AI 可覆蓋 suggested_buy / target_price / stop_loss
    │
    ▼ 最終名單（10–15 檔）
Telegram 通知 + 候選頁顯示
```

### 3.1 Step 1：物理門檻篩選

定義於 `StockScreener::screen()`，詳見 §2。

- 輸出：最多 80 檔，依 5日均量降冪排序
- 每檔 `score = 0`，`reasons` = 事實標籤
- 存入 `candidates` 表（`haiku_selected`、`ai_selected` 均為 null）

### 3.2 Step 2：Haiku 批量預篩

定義於 `HaikuPreFilterService::filter()`。

**目的**：以低成本快速淘汰明顯不適合當日操作的標的，降低 Opus 審核量。

#### 批次處理

- 每批 **15 檔**，一次 Haiku API call
- 80 檔需約 **6 次** API call（200ms 間隔，避免 rate limit）

#### System Prompt（所有批次共用，Anthropic prompt caching 快取）

System prompt 包含：

| 資料 | 來源 |
|------|------|
| 當日日期與美股摘要 | `UsMarketIndex::getSummary()` |
| 近 2 日新聞標題 | `NewsArticle`（有產業標籤，限 20 篇） |
| 消息面指數 | `NewsIndex`（整體 + 各產業前5） |
| AI 歷史選股教訓 | `AiLesson::getScreeningLessons()` |
| 快速評估標準 | 量能、趨勢、籌碼、排除條件、趨勢排列提示（內嵌於 prompt） |

#### Per-batch User Message（每批 15 檔，不快取）

每檔標的提供：
- 代號、名稱、產業、策略分類、事實標籤
- 近 5 日 K 線（緊湊格式：日期、收盤、量、漲跌%）
- 近 2 日三大法人（外資、投信淨買賣張數）
- 參考買入 / 目標 / 停損 / 風報比

#### 回應格式

```json
[
  {"symbol":"2330","keep":true,"confidence":80,"reason":"量爆突破+法人連買，值得精審"},
  {"symbol":"2317","keep":false,"confidence":25,"reason":"均線空頭，外資連賣三日"}
]
```

- `keep`：是否送入 Opus 精審
- `confidence`：0–100，代表值得精審的把握度（存為 `score`）
- `reason`：一句話關鍵理由（存為 `haiku_reasoning`）

#### maxPassThrough 限制

所有批次完成後，若 `haiku_selected=true` 的數量超過上限（預設 **30**），將信度最低的多餘標的改標 `haiku_selected=false`，確保 Opus 最多審 30 檔。

#### Fallback

API 不可用時，全部標記 `haiku_selected=true`，讓 Opus 自行判斷。

### 3.3 Step 3：Opus 精審

定義於 `AiScreenerService::screen()`。

**只處理 `haiku_selected=true` 的標的**（最多 30 檔）。

#### 每檔獨立 API call

- 每檔標的各自呼叫一次 Opus API
- System prompt 快取（市場背景、新聞、教訓、任務說明）所有檔共用
- Per-stock user message 包含完整資料（10日K線、5日法人、5日融資融券、個股相關新聞）
- K 線/法人/融資融券資料於迴圈前批次預載（`preloadData()`），消除逐檔 N+1 查詢

#### AI 決策資訊

| 資料 | 來源 | 說明 |
|------|------|------|
| Haiku 信度與理由 | `candidates` | `score`、`haiku_reasoning` |
| 近 5 日 K 線 | `daily_quotes` | 開高低收量、漲跌%、振幅% |
| 近 5 日三大法人 | `institutional_trades` | 外資/投信/自營淨買賣張數 |
| 近 5 日融資融券 | `margin_trades` | 融資增減/餘額、融券增減/餘額 |
| 個股相關新聞 | `news_articles` | 近 3 日依產業/股名/代號配對的新聞（含情緒標籤） |
| 近期新聞標題 | `news_articles` | system prompt 中近 2 日有產業標籤的新聞 |
| 消息面指數 | `news_indices` | 整體情緒/恐慌/熱度 + 各產業情緒 |
| 國際市場收盤 | `us_market_indices` | 台指期夜盤（最高權重）+ 美股五大指數 |
| AI 歷史教訓 | `ai_lessons` | 近期選股教訓回饋 |

#### AI 輸出

- `ai_selected`：選入 / 排除
- `ai_score_adjustment`：對 Haiku 信度的加減分（±30）
- `ai_reasoning`：選股理由（含題材、籌碼、技術）
- `intraday_strategy`：策略標籤（見下）
- `reference_support` / `reference_resistance`：AI 設定的支撐/壓力位
- `ai_warnings`：警示事項
- **AI 價格覆蓋**：可覆蓋 `suggested_buy`、`target_price`、`stop_loss`，自動重算 `risk_reward_ratio`
- `ai_price_reasoning`：一句話解釋三個價格設定依據

#### 策略標籤

| 標籤 | 說明 |
|------|------|
| `breakout_fresh` | 首次突破 |
| `breakout_retest` | 突破回測 |
| `gap_pullback` | 跳空拉回 |
| `bounce` | 跌深反彈 |
| `momentum` | 量能動能 |

#### Fallback

API 失敗時，取 Haiku 信度前 15 名，預設 `intraday_strategy = 'momentum'`。

### 3.4 AiLesson — 唯一調優入口

`AiLesson` 是調整選股行為的**唯一入口**，不需改 PHP 程式碼。

- 每日 15:30 由 `stock:daily-review` 自動萃取新教訓（成功/失敗案例、參數建議）
- Haiku 和 Opus 的 system prompt 均包含 `AiLesson::getScreeningLessons()`
- 新增一條教訓 → 隔日選股行為即更新
- **不再調整評分權重或硬門檻**，所有策略微調皆透過 AiLesson 表達

### 3.5 AI Model 配置

各服務依任務特性使用不同 Claude model，定義於 `backend/config/services.php`：

| 服務 | 環境變數 | 預設 Model | 說明 |
|------|---------|-----------|------|
| Haiku 批量預篩 | `ANTHROPIC_HAIKU_MODEL` | claude-haiku-4-5-20251001 | 每批 15 檔，速度/成本優先 |
| Opus 精審 | `ANTHROPIC_SCREENING_MODEL` | claude-opus-4-6 | 深度推理，每檔獨立 call，最多 30 檔 |
| 盤中校準/滾動 | `ANTHROPIC_INTRADAY_MODEL` | claude-sonnet-4-6 | 快照每 30 秒，AI 建議每 10-15 分鐘，速度優先 |
| 新聞情緒分析 | `ANTHROPIC_SENTIMENT_MODEL` | claude-haiku-4-5 | 高頻量大，簡單分類任務 |
| 每日檢討 | `ANTHROPIC_MODEL` | claude-opus-4-6 | 深度分析，一天一次 |

### 3.6 成本估算

| 階段 | 呼叫次數 | 估算成本/天 |
|------|---------|-----------|
| Haiku 預篩（80檔 / 15檔一批） | ~6 次 | ~$0.03 |
| Opus 精審（最多 30 檔） | ~30 次 | ~$1.07 |
| **合計** | | **~$1.10/天** |

> Haiku 比 Opus 便宜約 10 倍，批量快取進一步降低成本。

---

## 3.7. 盤前確認規則（已由 AI 開盤校準取代）

> **注意**：09:35 的 `stock:screen-morning` 排程已停用，由 09:05 的 AI 開盤校準取代。
> MorningScreener 類別和指令保留可手動執行，其 4 條規則作為 AI 校準的 fallback 邏輯。

定義於 `backend/app/Services/MorningScreener.php`。

### 確認規則

#### 基本四規則（計分用）

| #  | 規則 | 條件 | 分數 |
|----|------|------|------|
| 1  | 預估量爆發 | 預估成交量 > 昨量 × 1.5 倍 | 30 |
| 2  | 開盤開高 | 開盤漲幅介於 2% ~ 5% | 25 |
| 3  | 突破首根5分K | 現價 > 第一根5分K高點 | 25 |
| 4  | 外盤比 | 外盤比 > 55% | 20 |

#### 額外驗證規則（否決用）

| #  | 規則 | 條件 | 效果 |
|----|------|------|------|
| 5  | 跳空風險 | 開盤漲幅 > 7% | 否決通過（隔日沖風險過高） |
| 6  | 支撐確認 | 突破型：現價需站穩買入價上方，盤中低點未跌破買入價×0.99 | 突破型未通過 → 否決 |

規則 6 僅適用於突破型標的（`strategy_type = breakout`，此欄位已棄用，目前規則 6 恆通過），跌深反彈型不受此規則約束。

### 通過條件

1. 基本四規則至少 3 項通過
2. 且「預估量爆發」必須通過（必要條件）
3. 若跳空風險未通過 → 強制否決
4. 若支撐確認未通過（僅限突破型）→ 否決

---

## 3.8 盤中 AI 監控系統

定義於 `backend/app/Services/MonitorService.php`、`IntradayAiAdvisor.php`，由 `stock:monitor-intraday` 指令驅動。

### 快照資料層

`backend/app/Services/FugleRealtimeClient.php` 負責 Fugle MarketData API 即時報價（每支股票獨立 REST call，150ms 間隔，使用 `FUGLE_API_KEY`）。

`stock:monitor-intraday` 為長駐 loop 進程，自 09:00 啟動直到 13:30 自行結束，**每 30 秒**執行一次快照週期。Scheduler 保留每分鐘觸發，搭配 `withoutOverlapping(60)` 確保：
- 進程存活時：scheduler 每分鐘觸發被擋掉，不產生重複執行
- 進程異常中斷時：最多 1 分鐘內 scheduler 自動重啟

快照寫入 `intraday_snapshots` 表（時序資料，always insert）。

#### 漲跌停現價判定規則

以 **昨日收盤 × 1.10 / 0.90** 為標準漲跌停價，優先使用 Fugle 回傳的 `isLimitUp` / `isLimitDown` 旗標。`closePrice` 為空時，漲停用 `limitUpPrice`、跌停用 `limitDownPrice`、一般用 bid/ask 中間價補齊現價。

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

### AI 開盤校準（09:05）— 分級制

由 `IntradayAiAdvisor::openingCalibration()` 執行，取代原 MorningScreener。

AI 依開盤數據將每檔標的分為四級：

| 等級 | 條件 | 動作 | monitor 狀態 |
|------|------|------|-------------|
| A（強力推薦） | score 高 + 前日漲停/強勢 + est_vol>3 + ext_ratio>70% | 全額進場 | `watching` |
| B（標準進場） | score 中上 + 盤中走勢確認 | 半倉進場 | `watching` |
| C（觀察） | score 尚可但有矛盾訊號 | 紙上交易追蹤；AI 滾動建議 entry 且時間 < 11:00 可自動升格為 B | `watching`（暫不進 evaluateWatching） |
| D（放棄） | 明確轉弱訊號 | 不進場 | `skipped` |

- `morning_grade`（A/B/C/D）存入 `candidates` 表
- `morning_confirmed` = A 或 B 時為 true（向下相容）
- A/B/C 級均設定 `entry_conditions`（C 級用於紙上追蹤）

**C 級升格**：AI 滾動建議（`rollingAdvice`）判斷進場（`action: entry`）且時間 < 11:00，自動將 `morning_grade` 升為 B、`morning_confirmed = true`，下次快照觸發進場邏輯，並發送 `[升格 C→B]` Telegram 通知。

**Fallback**：API 失敗時使用 MorningScreener 四條規則，依 morningScore 分級：≥85→A、≥70→B、≥50→C、其餘→D。

### 進場判定

由 `MonitorService::evaluateWatching()` 依策略標籤判斷：

| 策略 | 進場條件 |
|------|----------|
| breakout_fresh / momentum | 現價 > 參考壓力位 × 0.995（接近或突破） |
| breakout_retest / gap_pullback | 拉回至參考支撐位 ±0.5% 範圍後量縮止穩 |
| bounce | 觸及參考支撐位 + 最後 2 筆價格/外盤比均上升 |

共同前提條件：量能充足（預估量比 ≥ AI 設定值）、外盤比合理（≥ AI 設定值）、非弱勢走勢（連續 3+ 根下跌且下跌量 > 上漲量 × 1.5 判定為 weakness，不進場）。

#### 動態目標/停損公式

進場時依近 5 日平均振幅計算當日目標/停損（`evaluateWatching()` → `evaluateHolding()` 切換時設定）：

```
目標價 = round(進場價 × (1 + 5日均振幅% × 0.6), 2)
目標價 = min(目標價, 進場價 × 1.08)              // 振幅公式上限
若 AI 校準壓力位 > 進場價 且 ≤ 進場價 × 1.10:
    目標價 = max(目標價, AI 壓力位)              // AI 值可突破公式上限
目標價 = min(目標價, 進場價 × 1.10)             // 絕對上限

停損價 = round(進場價 × (1 - 5日均振幅% × 0.55), 2)
停損價 = max(停損價, 進場價 × 0.97)             // 下限 3%
```

AI 滾動建議隨時可透過 `adjustments.stop` 動態調整停損（鎖利）；WATCHING 狀態可透過 `adjustments.support` / `resistance` 更新支撐/壓力，影響下次進場判定。

### 出場判定

由 `MonitorService::evaluateHolding()` 執行：

| 條件 | 動作 |
|------|------|
| 現價 ≥ 目標價 | `target_hit` |
| 現價 ≤ 停損價 | `stop_hit` |
| 持有期最高價回落 50% | `trailing_stop`（移動停利） |
| 獲利 >2% 時提高停損 | 動態停損至進場價 +0.5% |
| 獲利 >4% 時進一步收緊停損 | 動態停損至進場價 +2%（鎖利） |
| 持有 >90 分鐘且仍虧損中 | `closed`（時間停損） |
| 13:25 | `closed`（強制平倉） |

### AI 滾動建議（依時段動態頻率）

由 `IntradayAiAdvisor::rollingAdvice()` 對所有 active monitors 執行：

| 時段 | 頻率 | 說明 |
|------|------|------|
| 09:05-09:30 | 每 10 分鐘 | 開盤最劇烈，需快速反應 |
| 09:30-10:30 | 每 15 分鐘 | 早盤仍活躍 |
| 10:30-13:00 | 每 15 分鐘 | 盤中仍需留意趨勢轉弱 |
| 13:00-13:25 | 每 10 分鐘 | 尾盤平倉決策 |

一天約 23 次定期 AI call（不含緊急觸發），Sonnet 約 NT$18-28/天。

#### Prompt 架構（System/User 分離 + Prompt Caching）

使用 `anthropic-beta: prompt-caching-2024-07-31`，靜態部分快取全天，節省 token：

**System Prompt（靜態，每日每股首次後快取）**
- 股票基本資訊（代號、名稱、產業、策略）
- 日K趨勢背景（MA 排列分類：多頭排列/空頭排列/均線糾結/均線混排，由 `TechnicalIndicator::maAlignment()` 計算，存於 `candidates.indicators.ma_alignment`）
- 近 5 日 K 線摘要（日期/開高低收/量/漲幅）
- 開盤校準結果（等級 / 支撐位 / 壓力位 / 進場門檻 / 備註）
- AiLesson 盤中教訓

**User Message（動態，每次重新計算）**
- 聚合後的 5 分 K 線（開/高/低/收/量張/外盤%）——從當日所有原始快照聚合
- 開盤區間（首根 5 分 K 高/低）：突破→多方確認 / 跌破→多方失守
- 狀態與距離標示：
  - **HOLDING**：進場價 + 進場時間、損益%、距目標、距停損、距今日最高
  - **WATCHING**：現價、距支撐、距壓力、進場條件文字描述、今日高低
- 狀態別任務提問（HOLDING 問走勢是否支持持有/出場訊號/日K趨勢排列是否支持；WATCHING 問進場觸發、支撐壓力調整、日K趨勢是否支持操作方向）

#### 回應格式

```json
{
  "action": "hold",
  "notes": "量能從 2.1x 降至 1.6x，支撐有效，繼續持有",
  "adjustments": {
    "target": null,
    "stop": null,
    "support": null,
    "resistance": null
  }
}
```

| action | 狀態 | 效果 |
|--------|------|------|
| `hold` | 任意 | 套用 adjustments（若有）|
| `exit` | HOLDING | 立即以現價出場（trailing_stop）|
| `skip` | WATCHING | 轉為 skipped，放棄追蹤 |
| `entry` | WATCHING | C 級且 < 11:00 → 升格 B；其餘僅記錄 |

`adjustments.target` / `stop`：HOLDING 中調整目標/停損（可鎖利）
`adjustments.support` / `resistance`：WATCHING 中更新 AI 支撐/壓力位，影響進場判定

Fallback（API 失敗）：回傳 `{action: 'hold', notes: 'AI 不可用，維持現狀'}`

### 緊急 AI 觸發

由 `MonitorService::detectEmergency()` 偵測，任一條件成立即對 HOLDING 標的立即觸發：

| 條件 | 閾值 | 說明 |
|------|------|------|
| 急殺 | 最近 2 筆快照跌幅 > 1.5% | 短時間內價格急速崩跌 |
| 外盤崩潰 | external_ratio < 35% 且 change_percent < -0.5% | 賣壓大量湧現 |
| 接近停損 | 現價 < 停損價 × 1.01 | 距停損不到 1% |

觸發後立即呼叫 `IntradayAiAdvisor::emergencyAdvice()`，不等下一個定期排程週期。System prompt 複用相同快取，user message 額外標注緊急原因，要求 AI 明確回覆 hold 或 exit。

**Rate limit**：每股每 5 分鐘最多觸發一次（cache key: `emergency_ai:{stock_id}:{date}:{5min_slot}`）

### Telegram 通知

所有狀態轉換皆發送 Telegram 通知，包括：

| 事件 | 通知內容 |
|------|---------|
| AI 選股完成 | 寬篩 N 檔 → Haiku M 檔 → Opus 選入 K 檔，附各標的摘要 |
| AI 校準通過/否決 | 標的代號、名稱、等級、支撐/壓力、AI 備註 |
| 進場訊號 | 標的、進場價、量比、外盤比、目標/停損 |
| 走弱到價 | 標的到達買入價但走勢偏弱，不進場（含外盤比） |
| C→B 升格 | C 級 AI 滾動建議進場，升格為 B（含 AI 備註）|
| 達標出場 | 標的、出場價、獲利%、持有時間 |
| 觸停損出場 | 標的、出場價、虧損%、持有時間 |
| 移動停利 | 標的、出場價、鎖利%、持有時間 |
| AI 調整 | 標的、調整內容（目標/停損/支撐/壓力）、AI 備註 |
| 漲停/跌停 | 監控中的標的觸及漲跌停（每日每檔一次）|
| 13:25 強制平倉 | 標的、平倉價 |

---

## 4. 消息面指數

### 資料來源

每日 06:00 / 08:00 / 12:00 / 18:00 透過 `news:fetch` 抓取，`news:compute-indices` 計算。

唯一來源為**鉅亨新聞** JSON API（`api.cnyes.com`），抓取三個分類：

| 分類 | API category | 對應 | 每次上限 |
|------|-------------|------|---------|
| 台股新聞 | `tw_stock` | `tw_stock` | 100 篇 × 2 頁 |
| 國際股市 | `wd_stock` | `international` | 100 篇 × 2 頁 |
| 外匯 | `forex` | `international` | 100 篇 × 2 頁 |

每個分類抓取第 1、2 頁（每頁 100 篇，頁間延遲 300ms），每次執行約 180+ 篇新聞（扣除重複）。無關鍵字過濾，全數收錄。

### 指數定義 (NewsIndex)

| 指數 | 欄位 | 範圍 | 說明 |
|------|------|------|------|
| 情緒指標 | `sentiment` | 0 ~ 100 | 整體市場情緒 |
| 熱度指標 | `heatmap` | 0 ~ 100 | 新聞關注度 |
| 恐慌指標 | `panic` | 0 ~ 100 | 市場恐慌程度 |
| 國際風向 | `international` | 0 ~ 100 | 國際市場氛圍 |

### 分類

| scope | 說明 |
|-------|------|
| `overall` | 整體市場（單筆） |
| `industry` | 按產業分（多筆，`scope_value` = 產業名） |

---

## 5. 回測系統

### 5.1 盤後結果回填（`stock:update-results`）

定義於 `UpdateCandidateResults`，每日 **15:00** 收盤後自動執行。

**觸發條件：** 當日有候選標的（`candidates`）且尚未建立對應結果（`candidate_results`）。

**判定邏輯：**

| 欄位 | 計算方式 |
|------|---------|
| `actual_open` | 當日 `daily_quotes.open` |
| `actual_high` | 當日 `daily_quotes.high` |
| `actual_low` | 當日 `daily_quotes.low` |
| `actual_close` | 當日 `daily_quotes.close` |
| `hit_target` | 有 monitor → monitor 狀態為 `target_hit`；無 monitor → 當日最高價 ≥ `target_price` |
| `hit_stop_loss` | 有 monitor → monitor 狀態為 `stop_hit`；無 monitor → 當日最低價 ≤ `stop_loss` |
| `max_profit_percent` | `(high - suggested_buy) / suggested_buy × 100` |
| `max_loss_percent` | `(suggested_buy - low) / suggested_buy × 100` |
| `buy_reachable` | 有 monitor → monitor 有實際進場；無 monitor → 當日最低價 ≤ 建議買入價 |
| `target_reachable` | 有 monitor → 同 `hit_target`；無 monitor → 當日最高價 ≥ 目標價 |
| `buy_gap_percent` | `(suggested_buy - low) / suggested_buy × 100`（正值=買得到）|
| `target_gap_percent` | `(high - effective_target) / effective_target × 100`（effective_target = monitor 最終目標 or 原始目標）|

**注意事項：**
- 需要當日 `daily_quotes` 資料才能計算（依賴 14:30 的 `stock:fetch-daily`）
- 若當日無行情資料（如該股停牌），則跳過不建立結果
- 已有結果的候選標的不會重複計算（`whereDoesntHave('result')`）

### 5.2 回測核心指標

定義於 `BacktestService::computeMetrics()`，由 `CandidateController::stats()` 呼叫。

| 指標 | 欄位 | 計算方式 |
|------|------|---------|
| 候選標的數 | `total_candidates` | 期間內 `candidates` 總筆數 |
| 已驗證 | `evaluated` | 有對應 `candidate_results` 的筆數 |
| 買入可達率 | `buy_reach_rate` | `buy_reachable = true` 數 / 已驗證數 × 100 |
| 目標可達率 | `target_reach_rate` | `target_reachable = true` 數 / 已驗證數 × 100 |
| 雙達率 | `dual_reach_rate` | 同時 `buy_reachable AND target_reachable` 數 / 已驗證數 × 100 |
| 期望值 | `expected_value` | 見下方計算公式 |
| 停損觸及率 | `hit_stop_loss_rate` | `hit_stop_loss = true` 數 / 已驗證數 × 100 |
| 平均買入間距 | `avg_buy_gap` | 所有已驗證標的 `buy_gap_percent` 平均值 |
| 平均目標間距 | `avg_target_gap` | 所有已驗證標的 `target_gap_percent` 平均值 |
| 平均風報比 | `avg_risk_reward` | 所有已驗證標的 `risk_reward_ratio` 平均值 |

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
| 進場後勝率 | `win_rate_after_entry` | 有效進場中，`monitor_status` 為 `target_hit` 或 `trailing_stop` 的比率 × 100 |
| 平均 MFE | `avg_mfe` | 持有期間最大有利偏移（%）平均 |
| 平均 MAE | `avg_mae` | 持有期間最大不利偏移（%）平均 |
| 弱勢轉換率 | `weak_to_price_rate` | 弱勢走勢到達停損的比率 |
| 有效進場期望值 | `profit_if_valid_entry` | 只算有效進場的平均損益（%） |
| 平均持有時間 | `avg_holding_minutes` | 進場到出場的平均分鐘數 |
| AI 介入準確率 | `ai_override_accuracy` | AI 調整後結果為達標或停利的比率（%） |
| 改良版風報比 | `effective_rr` | `(target_hit_rate × avg_profit_per_hit) / (stop_hit_rate × avg_loss_per_hit)` |

這些指標僅在系統產生 monitor 資料後才會出現（向後相容）。

#### 策略分類分析

指標可依 `intraday_strategy` 分別統計，回傳於 `by_strategy` 欄位。支援類型：`bounce` / `breakout_fresh` / `breakout_retest` / `gap_pullback` / `momentum`。

#### 日別趨勢

回傳 `daily` 陣列，每日包含 `buy_reach_rate`、`target_reach_rate`、`dual_reach_rate`，供前端繪製趨勢圖。

---

## 6. 隔日沖選股系統

### 6.1 概覽

隔日沖（Overnight）選股是獨立於當沖的第二條選股流水線，目的是在今日收盤前（13:00–13:25）建倉，持有至明日（T+1）收盤前平倉。

**關鍵時序：**

```
12:45  抓取類股指數（stock:fetch-sector-indices）
         │
12:50  三階段 AI 選股（stock:ai-screen-overnight）
         │  ← 啟動時等待類股指數就緒（最多 5 分鐘）
         │
         ├─ Step 1: StockScreener overnight 模式（物理門檻）
         ├─ Step 2: HaikuPreFilterService overnight 模式（→ 最多 20 檔）
         └─ Step 3: AiScreenerService overnight 模式（Opus 精審 → 設定三個價格）
         │
13:00-13:25  使用者下單（今日收盤前建倉）
         │
T+1 15:05  stock:update-overnight-results（記錄實際開高低收 + 跳空數據）
T+1 15:35  stock:daily-review --mode=overnight（AI 檢討報告 + 萃取教訓）
週日 22:00  stock:compute-strategy-stats（策略績效統計更新）
```

### 6.2 雙日期設計

隔日沖流程中有兩個關鍵日期：

| 變數 | 值 | 用途 |
|------|----|------|
| `$snapshotDate` | T+0（今日） | 查詢 `IntradaySnapshot`、`SectorIndex` |
| `$tradeDate` | T+1（明日） | 寫入 `candidates.trade_date`、查詢 `DailyQuote` |

`candidates` 表的唯一鍵為 `[stock_id, trade_date]`，因此隔日沖（trade_date = T+1）與當沖（trade_date = T）自然不衝突。

### 6.3 StockScreener overnight 模式

讀取 `screen_thresholds_overnight` FormulaSetting（若不存在則 fallback 至 `screen_thresholds`）。

**overnight 模式與 intraday 的主要差異：**
- 跳過所有價格公式計算（`suggested_buy`、`target_price`、`stop_loss` 均為 null）
- 跳過風報比過濾（Opus 負責設定三個價格）
- 新增三個 overnight 專用事實標籤：

| 標籤 | 觸發條件 |
|------|---------|
| `法人連買3日` | 近3日外資淨買均 > 0 |
| `蓄勢整理` | 近3日振幅**各自**均 < 2%，且收盤在 MA5 ±1% 範圍內（`abs(close - MA5) / MA5 < 0.01`） |
| `強勢排列` | MA5 > MA10 > MA20 且收盤 > MA5 |
| `空頭排列` | MA5 < MA10 < MA20 且收盤 < MA5（持有過夜風險高） |
| `均線糾結` | MA5/MA10/MA20 spread < 收盤價 × 1.5%（方向不明） |

overnight 模式的物理篩選上限為 **top 100**（`max_candidates = 100`，高於當沖的 80），因後續 Opus 會從更大池中過濾。

`candidates.mode` 欄位設為 `'overnight'`。

### 6.4 HaikuPreFilterService overnight 模式

批次快速預篩，最多放行 **20 檔** 給 Opus 精審。

**System Prompt 額外資訊：**
- 類股強弱（`SectorIndex::getSectorSummary($snapshotDate)`）
- 隔日沖教訓（`AiLesson::getOvernightLessons()`）

**每檔 User Message 包含：**
- 近5日 K 線
- 近2日法人籌碼
- 今日盤中摘要（最新快照：現價、漲幅、量比、外盤比、走勢標籤）
- 類股今日漲跌幅（`SectorIndex::getChangeForIndustry()`）
- 衍生特徵：連漲天數、今日量能倍數

**評估基準（隔日沖）：** 今日收盤強 + 爆量 + 類股領先 → 優先；今日弱勢/法人賣超/融資大增 → 排除。

### 6.5 AiScreenerService overnight 模式（Opus 深度審核）

每檔獨立 1 次 Opus API call，**Opus 全權負責設定三個關鍵價格**。

**System Prompt 額外資訊：**
- 類股強弱（`SectorIndex::getSectorSummary($snapshotDate)`）
- 隔日沖教訓（`AiLesson::getOvernightLessons()`）
- 策略績效統計（`StrategyPerformanceStat::getPromptSummary('overnight')`）

**每檔 User Message 包含：**
- 近10日 K 線（OHLCV + 漲幅 + 振幅）
- 技術指標：RSI(14)、KD(9)、MA5/MA10、ATR(10)、布林通道(20)
- 今日盤中走勢（每30分鐘一筆快照：時間/現價/漲幅/外盤比/量比）
- 現況摘要（現價、開盤漲幅、日高低、走勢標籤）
- 今日K線型態（強勢長紅/長黑/長上影線/長下影線/十字星等）
- 衍生特徵：連漲天數、今日量能倍數
- 類股強弱 + 排名（`SectorIndex::getChangeForIndustry()` + `getRankForIndustry()`）
- 近5日法人籌碼
- 近5日融資融券
- 基本面估值（`StockValuation::getSummaryForStock()`：本益比/股價淨值比/殖利率/EPS TTM）
- 個股相關新聞（近5日，依類股名稱/股票名稱/代號過濾，最多6篇）

**Opus 回應 JSON 格式：**

```json
{
  "selected": true,
  "reasoning": "一句話選入/排除理由",
  "overnight_strategy": "完整進場策略說明",
  "entry_type": "gap_up_open|pullback_entry|open_follow_through|limit_up_chase",
  "gap_potential_percent": 1.5,
  "suggested_buy": 788.0,
  "target_price": 802.0,
  "stop_loss": 776.0,
  "price_reasoning": "三個價格設定依據（含技術位說明）",
  "warnings": ["注意事項"]
}
```

**entry_type 說明：**

| entry_type | 說明 |
|-----------|------|
| `gap_up_open` | 明日預期跳空高開後追強 |
| `pullback_entry` | 今日拉回整理，明日回升 |
| `open_follow_through` | 今日收盤強勢，明日延續開盤動能 |
| `limit_up_chase` | 今日漲停收盤，明日開盤追強 |

**DB 寫入欄位（`applyResultOvernight`）：**
- `overnight_strategy` ← `entry_type`（枚舉）
- `overnight_reasoning` ← `overnight_strategy`（完整說明文字）
- `overnight_key_levels` ← `key_levels`（JSON 陣列，含明日重要支撐/壓力位及理由）
- `gap_potential_percent`、`suggested_buy`、`target_price`、`stop_loss`
- `risk_reward_ratio`（自動計算）
- `ai_price_reasoning`（三個價格設定依據一句話）
- `ai_warnings`（注意事項陣列）
- `intraday_strategy` 強制設為 null（隔日沖不設當沖策略）
- 邊界保護：若 target ≤ buy，修正為 buy × 1.03；若 stop ≥ buy，修正為 buy × 0.97

### 6.6 T+1 盤中出場監控（`stock:monitor-overnight-exit`）

定義於 `MonitorOvernightExit` 指令與 `OvernightExitMonitorService`。

每日 **T+1 的 09:05、09:15 起每 15 分鐘至 13:15**（共 18 個時段）各執行一次，檢查所有 `ai_selected=true` 且 `CandidateMonitor.status` 尚未終止的隔日沖持倉。09:05/09:15 為開盤快速檢查（偵測跳空達標/停損）；12:45–13:15 為尾盤平倉決策。

#### 執行流程

1. 查詢 `candidates.mode=overnight`、`trade_date=T+1`、`ai_selected=true`、`monitor` 未終止的標的
2. 批次抓取 Fugle 即時報價（open、high、low、current、accumulated_volume）
3. 每檔依序判斷：

| 條件 | 動作 | monitor 狀態 |
|------|------|------------|
| `high >= current_target` | 自動達標 | `target_hit`（終止） |
| `low <= current_stop` | 自動觸停損 | `stop_hit`（終止）；`exit_price = min(stop, open)`（跳空跌破停損時以開盤價計） |
| 無觸發 | 呼叫 Sonnet AI 滾動判斷 | 依 Sonnet 回應決定 |

> **即時到價檢查**：除了每 15 分鐘的排程監控，`stock:monitor-intraday` 每 30 秒快照後也會呼叫 `OvernightExitMonitorService::checkPriceHits()` 做純規則到價檢查（不含 AI），確保目標/停損觸發不會有 15 分鐘延遲。

#### Sonnet 滾動判斷

Prompt 包含：時段標籤（09:05~13:15）、昨日收盤（建倉參考）、建議買入價、原始目標/停損、當前目標/停損、Fugle 開高低收及量、開盤跳空%、距離買入%、5 分 K 線（快照或 Fugle fallback）、先前調整紀錄（最多3筆）。

| action | 效果 |
|--------|------|
| `hold` | 記錄 AI 建議，維持現狀 |
| `adjust` | 更新 `current_target` / `current_stop`，記錄調整紀錄 |
| `exit` | 建議提前出場，轉為 `closed`（終止） |

所有轉換皆寫入 `monitor.state_log`；AI 建議寫入 `monitor.ai_advice_log`（累加，不覆蓋）。

Fallback（API 失敗）：回傳 `{action: "hold"}`，維持現狀。

---

### 6.7 盤後結果回填（`stock:update-overnight-results`）

每日 **15:05** 在 T+1 收盤後執行。

查詢 `candidates.trade_date = T+1, mode = 'overnight'` 的候選，寫入 `candidate_results`：

| 欄位 | 說明 |
|------|------|
| `actual_open/high/low/close` | T+1 實際 OHLC |
| `hit_target` / `hit_stop_loss` | 當日高點 >= 目標 / 低點 <= 停損 |
| `open_gap_percent` | (T+1 開盤 - T+0 收盤) / T+0 收盤 × 100 |
| `gap_predicted_correctly` | 跳空方向與 `entry_type` 預測是否一致 |
| `overnight_outcome` | hit_target / hit_stop / gap_up_strong / gap_up / gap_down / up / down / neutral |

### 6.8 AI 每日檢討（overnight 模式）

`stock:daily-review --mode=overnight` 在 T+1 15:35 執行。

- 比較 `gap_potential_percent` vs 實際 `open_gap_percent` 的預測準確率
- 分析 `entry_type` 策略與實際開盤表現的匹配度
- 萃取教訓存入 `ai_lessons`（`mode = 'overnight'`），供下次選股 Prompt 使用

### 6.9 策略績效統計

`stock:compute-strategy-stats` 每週日 22:00 計算近 30/60 天的量化統計，存入 `strategy_performance_stats`：

| 維度 | 說明 |
|------|------|
| `strategy`（dimension_type） | 依 `overnight_strategy`（entry_type）分組 |
| `feature`（dimension_type） | 依 `reasons` 標籤組合分組（爆量+法人買超等） |
| `market_condition`（dimension_type） | 依當日台指期漲跌幅分組（大盤>+1%、-1~+1%、<-1%）|

統計欄位：`target_reach_rate`（達標率）、`expected_value`（期望報酬%）、`avg_risk_reward`（平均風報比）。

這些統計資料會注入 Opus overnight 選股的 System Prompt，提供量化基準。

### 6.10 類股指數（SectorIndex）

資料來源：TWSE OpenAPI `https://openapi.twse.com.tw/v1/indicesReport/MI_5MINS`

每日 **12:45** 由 `stock:fetch-sector-indices` 抓取並存入 `sector_indices` 表。

涵蓋25個類股（IX0007–IX0056），包含：電子工業、半導體業、金融保險、鋼鐵工業等主要類股。

`SectorIndex` 模型提供三個便利方法：
- `getSectorSummary(string $date): string` — 所有類股漲跌幅（格式化供 AI prompt 使用）
- `getChangeForIndustry(string $date, string $industry): ?float` — 取特定類股今日漲跌幅
- `getRankForIndustry(string $date, string $industry): ?int` — 取類股強弱排名

### 6.11 基本面估值（StockValuation）

定義於 `backend/app/Models/StockValuation.php`，由 `stock:fetch-valuations` 每日 **17:00** 更新。

**資料來源：** TWSE OpenAPI `https://opendata.twse.com.tw/v1/exchangeReport/BWIBBU_ALL`（TWSE 每日收盤後約 17:00 更新）

**欄位：**

| 欄位 | 說明 |
|------|------|
| `pe_ratio` | 本益比（P/E ratio） |
| `pb_ratio` | 股價淨值比（P/B ratio） |
| `dividend_yield` | 殖利率（%） |
| `eps_ttm` | 近 12 個月 EPS（目前 TWSE API 未直接提供，欄位保留） |

**使用方式：** `StockValuation::getSummaryForStock($stockId, $beforeDate)` 回傳格式化字串（如「本益比 15.3x　淨值比 2.1x　殖利率 4.2%」），直接注入 Opus overnight 選股的 per-stock user message。

---

### 6.12 前端顯示

候選頁（`CandidatesView.vue`）新增當日沖/隔日沖切換 Tab：
- 切換後呼叫 `GET /api/candidates?date=...&mode=overnight`
- 隔日沖卡片額外顯示：`gap_potential_percent`（預測跳空幅度）、`overnight_reasoning`（完整策略說明）、`overnight_strategy` 進場類型標籤

### 6.13 Phase 2（未來規劃）

分點/主力資料整合（需開通第三方資料服務）。

---

## 7. 使用者管理與權限系統

### 7.1 概述

系統採 Laravel Sanctum Bearer Token 驗證。Token 存於前端 `localStorage`，每次 API 請求附加 `Authorization: Bearer <token>` header。

前端所有非登入頁面皆需驗證，未登入自動導向 `/login`。

### 7.2 角色定義

| 角色 | 說明 | 可存取頁面 |
|------|------|----------|
| `admin` | 管理員，完整功能 | 全部頁面（候選標的、隔日沖、績效統計、隔日績效、消息面、設定、規格書、用戶管理） |
| `viewer` | 觀看者，唯讀受限 | 候選標的（`/`）、隔日沖（`/overnight`）、個股詳情（`/stock/:id`） |

Viewer 無法觸發任何寫入或 AI 操作（data-sync、news/fetch、backtest optimize 等）。

### 7.3 認證流程

1. 前端以 **用戶 ID**（數字）+ 密碼 `POST /api/auth/login`
2. 後端驗證後刪除舊 Token、建立新 Token，回傳 `{token, user}`
3. 前端存 token 至 `localStorage`，注入 `axios.defaults.headers.common['Authorization']`
4. App 掛載前呼叫 `GET /api/auth/me` 取得用戶資訊（Pinia auth store hydration）
5. 401 攔截器：自動清除 token 並導向 `/login`（有 `_loggingOut` 旗標防重複觸發）

### 7.4 路由權限（後端）

```
POST /api/auth/login                ← 公開（唯一不需驗證）

auth:sanctum middleware {
  GET  /api/auth/me
  POST /api/auth/logout
  GET  /api/candidates/*            ← viewer + admin
  GET  /api/stocks/*                ← viewer + admin
  GET  /api/formula-settings
  GET  /api/news/dashboard, /api/news/fetch-status
  GET  /api/backtest/daily-review*, /api/backtest/analyze-tip

  admin middleware {
    /api/users CRUD
    POST /api/data-sync
    POST /api/news/fetch
    PUT  /api/formula-settings/{type}
    POST /api/backtest/optimize, /api/backtest/rounds/{round}/apply
    apiResource /api/screening-rules
    GET  /api/spec
  }
}
```

Admin middleware 定義於 `app/Http/Middleware/EnsureAdmin.php`，以 `admin` 別名註冊於 `bootstrap/app.php`。

### 7.5 SSE（Server-Sent Events）Token 傳遞

`EventSource` API 不支援自訂 Request header，無法附加 Bearer Token。

**解法：** 前端建立 SSE URL 時附加 `?token=<localStorage token>` query string，後端 `BacktestController` 在 `analyzeTip()` 和 `dailyReview()` 開頭手動注入：

```php
if ($request->query('token') && !$request->bearerToken()) {
    $request->headers->set('Authorization', 'Bearer ' . $request->query('token'));
}
```

### 7.6 釘選功能（Pin）

用戶可對候選標的釘選，釘選資料存於資料庫（`user_pins` 表），支援跨裝置同步。

**Schema：**

| 欄位 | 說明 |
|------|------|
| `user_id` | FK → users.id（cascade delete） |
| `candidate_id` | FK → candidates.id（cascade delete） |
| `created_at` | 釘選時間 |

Unique constraint：`(user_id, candidate_id)`

**API：**
- `GET /api/pins?date=&mode=` — 取得當前用戶指定日期/模式的釘選 ID 清單
- `POST /api/pins/{candidate}` — 釘選（firstOrCreate）
- `DELETE /api/pins/{candidate}` — 取消釘選

釘選的標的在前端卡片左側顯示橘色邊框，並排序至列表最前。

### 7.7 預設帳號

執行 `php artisan db:seed --class=AdminSeeder` 後建立：

| 欄位 | 值 |
|------|----|
| 用戶 ID | `1` |
| 電子郵件 | `admin@trading.local` |
| 密碼 | `changeme123` |
| 角色 | `admin` |

### 7.8 用戶管理頁（`/users`）

Admin 專用，提供：
- 列表（ID、姓名、電子郵件、角色標籤、建立日期）
- 新增/編輯 Dialog（電子郵件選填，編輯時密碼留空表示不修改）
- 刪除（自己的帳號無法刪除）

---

## 8. 即時報價頁（`/quote`）

定義於 `frontend/src/views/QuoteView.vue` 與 `backend/app/Http/Controllers/Api/QuoteController.php`。

### 8.1 資料來源優先順序（DB 優先 + API Fallback）

報價查詢採 **DB 優先** 策略，減少 API 呼叫並提升可靠性：

```
使用者輸入股票代號或名稱（支援 autocomplete 模糊搜尋）
    │
    ▼ 非純數字時先呼叫 /api/quote/search?q= 解析代號
    ▼ 查詢 IntradaySnapshot（今日最新）
DB 有資料？──── 是 ──→ 用 DB 快照回傳主報價
    │                      │
    │                      ▼ 嘗試補抓 Fugle 5分K（graceful failure）
    │
    否 ──→ 呼叫 Fugle API 取得完整報價 + 5分K
```

| 資料來源 | 主報價（OHLCV、外盤比） | 五檔 | 5分K | 適用情境 |
|---------|----------------------|------|------|---------|
| DB（`IntradaySnapshot`） | 完整 | 僅 best_bid/best_ask | 從 API 補抓（可失敗） | 候選標的在監控時段內 |
| API（Fugle MarketData） | 完整 | 完整五檔 | 完整 | DB 無資料時 fallback |

**限制：** `IntradaySnapshot` 僅記錄被系統監控的候選標的（每 30 秒快照），非候選標的一律走 API。

前端以橘色「快照」標籤標示資料來自 DB，回傳 JSON 包含 `source` 欄位（`db` 或 `api`）。

### 8.2 API 端點

| 方法 | 路徑 | 說明 |
|------|------|------|
| `GET` | `/api/quote/search?q=` | 股票名稱/代號模糊搜尋（autocomplete，回傳前 10 筆） |
| `GET` | `/api/quote/{symbol}` | 取得即時報價 + 5分K |
| `POST` | `/api/quote/{symbol}/analyze` | AI 線上問診（需傳 `cost` 參數） |

搜尋端點支援代號前綴匹配及名稱模糊匹配，前端使用 `el-autocomplete` 元件提供即時建議。三個端點皆採用相同的 DB 優先策略。

### 8.3 AI 線上問診

使用者輸入成本價後，系統將報價數據（OHLCV、外盤比、近5日日K、5分K、五檔）送至 Claude API，一次回傳短線與波段兩個建議：

- **短線**：今日收盤前結束。建議動作：續抱 / 止損 / 觀望。分析重點：盤中走勢、時間壓力、開盤型態。
- **波段**：可持有數天到數週。建議動作：續抱 / 減碼 / 止損 / 加碼 / 觀望。分析重點：日K趨勢、量價結構、關鍵支撐壓力位。

各自回傳：建議動作、分析內容（100字內）、停利/停損價位（如適用）。AI 回應格式為 JSON，包含 `short` 和 `long` 兩個區塊。

AI model 使用 `ANTHROPIC_MODEL` 環境變數設定（預設 `claude-opus-4-6`）。

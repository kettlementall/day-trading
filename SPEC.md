# 系統規格書

> 本文件為系統完整規格，任何規則、排程、公式的異動都必須同步更新此文件。

## 文件導覽

本文件目前維持單檔，作為系統規格的 authoritative source。為避免後續繼續 append-only，維護時依下列分區放置內容：

| 章節 | 範圍 | 維護原則 |
|------|------|----------|
| §1 | 排程、資料依賴、休市日、資料修復 | 只放跨策略共用流程與摘要；細節放到各策略章節 |
| §2–§3 | 當沖篩選、AI 選股、盤中監控、盤中動態加入 | intraday 專屬邏輯集中在此 |
| §4–§5 | 新聞指數、回測與盤後結果 | 共用統計口徑與 intraday 結果口徑 |
| §6 | 隔日沖（overnight） | T+0/T+1 日期語意、選股、出場監控、回填、實際績效的主規格 |
| §7–§8 | 使用者/權限、即時報價頁 | 產品功能與 API 行為 |
| §9 | 短線（swing） | universe、候選、持倉、教訓回流與短線健康檢查 |

重複資訊處理方式：

- §1 可保留三條策略的日程與資料流摘要，但不要放完整規則。
- 策略細節只在該策略章節維護；其他章節以「見 §x.y」引用。
- Prompt、欄位、結果口徑若與程式碼綁定，需標明對應 service / command。

---

## 1. 每日排程

排程定義於 `backend/routes/console.php`。

| 時間  | 指令                        | 說明                                                        |
|-------|-----------------------------|-----------------------------------------------------------|
| **05:01** | **`stock:fetch-us-indices --tx-night-close`** | **抓台指期夜盤收盤**（symbol=TX_NIGHT，change vs T-1 日盤收的正確「夜盤漲跌」；供 08:30 簡報、MarketContext 讀） |
| 06:00 | `stock:fetch-us-indices --no-tx` | 抓取美股指數（S&P 500、費半、道瓊、那斯達克、美元指數）；**TX 改由 05:01 / 14:00 / 08:45 三點抓** |
| 06:00 | `news:fetch`                | 抓取隔夜國際新聞                                                  |
| 06:15 | `news:compute-indices`      | 計算新聞指數（供選股用）                                              |
| 08:00 | `stock:ai-screen`           | 三階段 AI 選股：物理門檻寬篩（intraday top 100，依當沖複合分數降冪 — 見 §2.5）→ Haiku 批量預篩（→ 最多 30 檔）→ Opus 精審，最終選出 10–15 檔 |
| 08:45 | `stock:fetch-us-indices --tx-only` | 更新台指期日盤開盤價（symbol=TX，候選頁即時報價用；不影響 TX_NIGHT/TX_DAY） |
| **14:00** | **`stock:fetch-us-indices --tx-day-close`** | **抓台指期日盤收盤**（symbol=TX_DAY，供隔天 05:01 TX_NIGHT 算 change 基準） |
| 08:00 | `news:fetch`                | 開盤前新聞抓取                                                   |
| 08:15 | `news:compute-indices`      | 計算新聞指數                                                    |
| **08:30** | **`stock:premarket-briefing`** | **盤前方向簡報（Opus 聚合美股/夜盤/MarketContext/NewsIndex/法人 T-1 → Telegram，見 §3.0）** |
| 09:05 | `stock:fetch-intraday`      | 盤中即時行情（5分K）                                               |
| 09:30 | `stock:fetch-intraday`      | 盤中即時行情（30分鐘後狀態）                                           |
| 09:00-13:30 | `stock:monitor-intraday` | 盤中即時監控（每 30 秒快照；command 內部 loop，scheduler 每分鐘觸發作為當機重啟保底） |
| **09:35** | **`stock:scan-intraday-movers`** | **腿 2：盤中動態加入候選（4+1 軸聯集 → Fugle 即時報價 → 4 條規則 → Haiku 快評 → 寫入 candidates — 見 §3.9）** |
| 12:00 | `news:fetch`                | 午間新聞抓取                                                    |
| 12:15 | `news:compute-indices`      | 計算新聞指數                                                    |
| **15:30** | **`stock:fetch-sector-indices`** | **抓取 TWSE 類股指數收盤（用帶日期端點為主、OpenAPI fallback；供隔日沖 12:50 ai-screen-overnight 以 T-1 形式取用，供 18:50 swing 持倉檢討看當日類股強弱）** |
| **12:50** | **`stock:ai-screen-overnight`** | **隔日沖三階段 AI 選股（用今日盤中資料選明日建倉標的）** |
| 14:30 | `stock:fetch-daily`         | 收盤後抓取每日行情                                                 |
| 15:00 | `stock:update-results`      | 更新當日當沖候選標的的盤後結果                                           |
| **15:05** | **`stock:update-overnight-results`** | **更新隔日沖候選標的盤後實際結果（T+1 收盤後）；`--force-recompute` 旗標供歷史回填強制重算 `buy_reachable` / `unreachable_reason` / 實際出場欄位** |
| 15:30 | `stock:daily-review`        | 自動產出當日 AI 檢討報告（依賴 15:00 結果回填，不含教訓萃取）                     |
| **15:35** | **`stock:daily-review --mode=overnight`** | **自動產出隔日沖 AI 檢討報告（不含教訓萃取）** |
| 16:30 | `stock:fetch-institutional` | 抓取三大法人買賣超（TWSE 約 16:15~16:30 上線）                          |
| 17:00 | `stock:fetch-margin`        | 抓取融資融券                                                    |
| **17:15** | **`stock:fetch-valuations`** | **從 TWSE 抓取本益比/殖利率/股價淨值比（BWIBBU_ALL），供隔日沖 Opus 估值判斷使用** |
| 18:00 | `news:fetch`                | 盤後新聞抓取                                                    |
| 18:15 | `news:compute-indices`      | 計算新聞指數                                                    |
| **18:20** | **`stock:research-investment-theses`** | **AI 自動研究/更新短線產業投資論點** |
| **18:50** | **`stock:update-swing-positions`** | **每日盤後更新使用者短線持倉與損益快照** |
| **19:00** | **`stock:ai-screen-swing`** | **AI 理專型短線選股（產業論點 + 技術/籌碼/估值；週一~週五寫入當日 trade_date，週日/連假最後一晚追加跑一次並以 `previousTradingDay` 為 trade_date 覆蓋最近交易日候選，讓使用者開盤前看到最新 thesis 選股）** |
| 22:00 | `stock:health-check`        | 健康檢查（資料完整性 + 卡住 monitor 強制收尾 + 當沖/隔日沖結果與檢討補跑 + 三大法人當日缺漏補跑 + 短線候選/持倉快照/教訓新鮮度 + API 連通性 + Log 大小警告） |
| 週日 03:00 | `stock:cleanup`             | 清理過期資料（快照保留 30 天、AI 教訓過期刪除）                               |
| 週一 06:00 | `stock:fill-industry`       | 從 TWSE/TPEX 公司基本資料補上 `stocks.industry`（產業別），供類股強弱、新聞題材配對使用 |
| **週一 17:30** | **`stock:refresh-swing-universe`** | **依流動性／價格／資料完整度／ETF 類型重算 `stocks.is_swing_eligible`，把短線選股池跟當沖名單解耦** |
| **T+1 09:05–13:25** | **`stock:monitor-overnight-exit --slot={time}`** | **隔日沖 T+1 出場監控，09:05-09:25 每 5 分鐘 + 09:30 後每 15 分鐘，13:25 強制平倉（獨立 Fugle 報價抓取；目標/停損到價自動終止；Sonnet 滾動判斷 hold/adjust/exit）** |
| **週日 22:00** | **`stock:compute-strategy-stats`** | **計算當沖/隔日沖策略量化績效統計（30/60 天窗口）；短線維度暫停（20 天 paper 模擬已移除，待 realized 真實樣本足夠再重建，見 §9.5d）** |

> `stock:backtest --validated` 已停用自動排程。指令保留可手動執行回測指標檢視。

### 台指期三符號架構（TX / TX_DAY / TX_NIGHT）

`us_market_indices` 表內台指期分三個 symbol，因為一筆 row 塞不下「日盤盤中價」「日盤收盤」「夜盤收盤」三種不同語意：

| symbol | 寫入時點 | 語意 | 用途 |
|---|---|---|---|
| `TX` | 08:45 `--tx-only`（日盤開盤後） | 日盤即時/開盤後盤中價 | 候選頁、即時報價顯示 |
| `TX_DAY` | 14:00 `--tx-day-close` | 日盤收盤（CLast at 14:00 = 13:45 收盤後最後一筆） | 給隔天 TX_NIGHT 算 change 基準 |
| `TX_NIGHT` | 05:01 `--tx-night-close` | 夜盤收盤（CLast at 05:01 = 夜盤剛收的最後一筆） | **盤前簡報、MarketContext 讀「夜盤漲跌」** |

**Why 三符號**：

期交所即時 API 的 `CRefPrice` 在 session 切換時點不可靠（5/29 06:00 觀察到 CRef 仍掛 5/27 收 44794，而非預期的 5/28 收 43839），所以**不能信任 API 的 prev_close**。改採「**自己存日盤收盤 → 夜盤收盤算 change vs 自己存的日盤收盤**」雙時點抓取 + 自家算 change 的方式，避免 CRef 切換延遲帶來的語意錯位。

**change_percent 計算**：
- `TX_DAY[T]` change vs `TX_DAY[T-1]` = 日盤漲跌
- `TX_NIGHT[T]` change vs 最近一筆 `TX_DAY[<T]`（= T-1 日盤收）= 真實「夜盤漲跌」

**讀者層**：
- `PremarketBriefingService::collectInputs`：優先讀 `TX_NIGHT`、fallback `TX`，傳給 Opus 時 symbol/name 統一改回 `TX`/`台指期夜盤`，避免 AI 看到多個 symbol 混淆
- `MarketContextService::detect`：同上 fallback 邏輯讀 TX_NIGHT

**5/29 案例**：
- 戰爭新聞在 5/28 早盤、5/28 日盤收 43839（-2.13% vs 5/27 收 44794）
- 5/28-5/29 夜盤翻多回升、收 44798（+2.19% vs 5/28 收 43839）
- 修正前 briefing 看到的 TX 是 06:00 抓的 43842（CRef 還掛 44794 → -2.13%），把**前一天日盤崩跌錯標成今日夜盤跌幅**，判偏空
- 修正後 briefing 看到 TX_NIGHT=44798（+2.19% vs T-1 TX_DAY 43839），判 bullish_catalyst，方向正確

### 市場情境判斷（MarketContextService）

`MarketContextService::detect($tradeDate)` 由兩條獨立訊號合成：

**(A) 隔夜訊號**（永遠執行，讀 `us_market_indices`）：

| 情境 | 判斷條件 | 選股調整 |
|------|----------|----------|
| `normal` | 費半 ±3%、台指期 ±1.5% | 不調整 |
| `bullish_catalyst` | 費半 > +3% 或 台指期 > +1.5% | 放寬空頭排列篩選、加入超跌反彈候選、Haiku/Opus prompt 注入催化提示 |
| `bearish_panic` | 費半 < -3% 或 台指期 < -1.5% | 收緊選股標準 |

**(B) 當日台股廣度訊號**（盤後 `daily_quotes` 已寫入時才執行；少於 500 檔有量視為未寫入直接跳過）：

從當日 `daily_quotes` 聚合大盤廣度（漲:跌、平均漲跌、>5% 跌停/漲停檔數），判定 panic / bull / normal：

| 訊號 | 任一條件成立即觸發 |
|------|---------|
| 當日 panic | (a) 跌>5% ≥50 檔 **且** ≥ 漲>5% 檔數 × 2；(b) 全體平均 ≤ -1%；(c) 跌:漲 ≥ 1.8 **且** 平均 ≤ -0.3% |
| 當日 bull | 對稱條件（漲>5% ≥50 且 ≥ 跌>5% ×2；平均 ≥ +1%；漲:跌 ≥ 1.8 且平均 ≥ +0.3%） |

> 三條都要求方向明確（比例 + 廣度雙重門檻），避免「分歧日」(eg. 跌 78、漲 60) panic/bull 同時 fire 互相抵銷。

**合成規則**（當日台股優先於隔夜）：

| 隔夜 | 當日台股 | 最終 | 觸發描述 |
|------|---------|------|---------|
| any | normal | 維持隔夜 | — |
| 同向 | 同向 | 維持隔夜 | triggers 補充「台股當日同步走強/恐慌」 |
| 衝突 | panic | **bearish_panic**（覆蓋） | triggers 顯示「台股當日恐慌覆蓋海外訊號」+「海外隔夜：xxx（不採信，當日台股已反向）」+ hint 要求 AI 以「市場拖累」為基底解釋個股暴跌、修復條件以大盤先止穩為前提 |
| 衝突 | bull | **bullish_catalyst**（覆蓋） | 對稱描述 |

**Why 加當日台股**：原本 `detect()` 只看隔夜美股/TX，盤後（18:50 `SwingPositionUpdateService`）執行時，無視今日台股實況。5/28 案例：費半 +4.09% → 標 `bullish_catalyst`，但當日台股因戰爭新聞崩跌（多檔 -6~-9%、群創成交 12 億股），AI 仍帶 bullish 標籤推理，把 2313 -8.16% 歸因為「現增稀釋的結構性賣壓」（個股因素），而非市場性恐慌拖累。

**時機自動切換**：盤前（08:00 / 08:30）執行時，今日 `daily_quotes` 尚未寫入（14:30 才寫），`detectTaiwanIntraday` 回 null → 完全走原隔夜邏輯，當沖選股不受影響；盤後（18:50 持倉檢討）`daily_quotes` 已寫入，自動疊加當日台股訊號。

**利多催化日特別邏輯：**
- 物理篩選額外標記「超跌反彈候選」：5日跌幅>10%（或>7%+外資買超），保證入選 top 100
- Haiku prompt 注入催化日評分標準，不因空頭排列給低信度
- Opus prompt 新增 `gap_reversal` 策略選項及催化反轉評估原則
- gap_reversal 候選跳過風報比門檻（跳空格局下公式 RR 不準）

### 資料依賴流程

**當沖流程：**
```
14:30 行情 ──┐
16:00 法人 ──┤
16:30 融資 ──┼── 06:00 隔夜新聞 → 06:15 算指數 → 08:00 AI 三階段選股
18:00 新聞 ──┘                                 │
                                               ├─ 市場情境判斷（MarketContextService）
                                               ├─ Step 1: 物理門檻寬篩（top 100 + gap_reversal 候選；依當沖複合分數排序）
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
12:00 新聞（T+0）──┼── 15:30 類股指數 → 12:50 隔日沖 AI 三階段選股（取最近交易日類股，即 T-1）
12:50 盤中快照 ────┘                   │
                                       ├─ Step 1: overnight 物理篩選（top 100）
                                       ├─ Step 2: Haiku overnight（→ 最多 20 檔）
                                       └─ Step 3: Opus overnight（→ 設定三個價格）
                                                        │
                  13:00–13:25 使用者下單建倉（T+0）
                                                        │
                  T+1 09:05~13:25 出場監控（Fugle + Sonnet 滾動；13:25 強制平倉）
                                                        │
                  T+1 15:05 盤後結果回填 → 15:35 AI 隔日沖檢討
```

**短線流程（Swing，AI 動態決定持有期；建倉時的 `max_holding_days` 取 AI 建議的 `swing_time_horizon_days`，無建議時 fallback 20 個交易日；超過上限僅標記 `time_pressure=expired` 餵 AI 重估，不會強制平倉）：**
```
週一 17:30 → stock:refresh-swing-universe → 重算 stocks.is_swing_eligible
            （規則：60 天日K + 過去 20 日均量 ≥ 1000 張 + 收盤 ≥ 10 元 + 排除衍生型 ETF）
            (此股票池與當沖 is_day_trading 解耦，獨立維護)

14:30 日K ─┬─ 16:30 法人 ─ 17:00 融資 ─ 17:15 估值
18:00 新聞 ─ 18:15 新聞指數
             │
             ├─ 18:20 AI 研究/更新 investment_theses（confidence 衰退與 inactive）
             ├─ 18:50 更新 user 專屬 swing_positions + snapshots（hold/adjust/exit）
             └─ 19:00 swing AI 選股（讀 is_swing_eligible；全域 candidates.mode=swing；週日/連假最後一晚會以最新 thesis 重跑一次覆蓋最近交易日候選）
                         │
                         └─ 使用者於 /swing 手動確認買入，建立自己的持倉
```

### 休市日檢查

`stock:ai-screen`、`stock:ai-screen-overnight`、`stock:fetch-intraday`、`stock:monitor-intraday`、`stock:monitor-overnight-exit`、`stock:scan-intraday-movers`、`stock:update-results`、`stock:update-overnight-results`、`stock:daily-review`、`stock:fetch-margin`、`stock:fetch-valuations` 開頭檢查 `MarketHoliday::isHoliday()`，週末或國定假日自動跳過。

`stock:ai-screen-swing` 例外：排程改為每日 19:00 觸發，命令內判定「今日與明日皆為休市日才跳過」。其餘狀況都會跑 — 今日為交易日時 `trade_date` 寫入當日；今日休市但明日是交易日（典型：週日、連假最後一晚）則以 `previousTradingDay(today)` 為 `trade_date`，刷新「最近一次交易日」的候選名單，讓 `/swing` 頁面在開盤前看到最新 thesis 選股。

手動補跑例外：`stock:ai-screen-overnight {date}`、`stock:update-results {date}`、`stock:update-overnight-results {date}`、`stock:daily-review {date}` 顯式傳入 date 時不擋，保留歷史補跑彈性；`stock:scan-intraday-movers --date=...` 為測試/模擬日期，顯式傳入時不做休市日阻擋。其餘交易時段或交易所資料抓取指令遇休市日一律跳過，避免打即時 API 或將上一交易日資料寫到休市日期。

休市日資料由 `stock:import-holidays {year}` 指令匯入（每年更新一次），定義在 `ImportMarketHolidays.php` 內。

### 排程日誌

所有排程指令的 stdout 輸出皆追加寫入 `storage/logs/schedule.log`，可用於確認各指令是否有實際執行。

### 非交易日處理

市場資料抓取指令依資料來源採兩種非交易日保護：

1. `stock:fetch-margin`、`stock:fetch-valuations` 先以 `MarketHoliday::isHoliday()` 跳過休市日。
2. `stock:fetch-daily`、`stock:fetch-institutional` 以交易所 API 回傳日期驗證：

- 從 TWSE/TPEX API 回傳的 `date` / `reportDate` 欄位取得**實際交易日**
- 若實際交易日與請求日期不符（代表該日為假日），自動跳過不存入
- 避免假日期間 API 回傳上一交易日資料被存到錯誤日期的問題

排程在假日仍會執行，但因上述驗證機制，不會產生錯誤資料。

### 資料修復

若發現歷史資料日期錯位，可使用修復指令：

```bash
php artisan stock:repair-quotes --from=2026-03-01 --to=2026-04-08 --dry-run

php artisan stock:repair-quotes --from=2026-03-01 --to=2026-04-08
```

第一行為檢查模式（不修改）；第二行會執行修復（刪除假日錯誤資料後重新抓取正確交易日）。

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

通過物理門檻的標的依模式不同採用不同排序：

| 模式 | 排序鍵 | 取數 | 設計理念 |
|------|------|------|----------|
| **intraday** | **當沖複合分數降冪**（見 §2.5） | top **100** | 振幅/流動性/日內活躍/籌碼/動能/突破六項加權 + 漲停/過熱/跌停/弱勢負分。當沖核心是「今天有來回」，不是「強勢延續」 |
| **overnight** | 5 日均量降冪 | top 100 | 強勢延續邏輯下，流動性大的權值股優先讓 Opus 看 |

`gap_reversal` 候選保證入選（不被排序截斷）。

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

### 2.5 當沖複合分數（intraday only）

當沖物理門檻排序不再使用「5 日均量降冪」（會偏向大型權值股，把中小型題材股排除在 80 名外），改用**複合分數降冪**。設計理念：物理門檻排序應該回答「**今天值不值得當沖**」，不是「流動性高不高」。

#### 子分數（每項 0–100）

| 子分數 | 計算 | 設計動機 |
|------|------|----------|
| **振幅 (amplitude)** | `5日均振幅 × 20` | 當沖核心利潤來源，沒振幅就沒手續費覆蓋空間 |
| **流動性 (liquidity)** | `log10(5日均量張) × 25` | log 飽和：100 張→50 分、1000 張→75、≥10000 張→100。避免大型股用流動性壟斷分數 |
| **日內活躍 (pattern)** | `近 10 日 ≥5% 振幅天數 × 20` | 區分「真當沖標的」（3 天 5% + 2 天 5%）vs 死水股（平均 2% 但每天小震） |
| **籌碼 (chips)** | 法人合計買超 +40／連 2 日買 +30／融資減 +20／融券增 +10 | 籌碼乾淨度 |
| **動能 (momentum)** | `max(0, 前日漲幅) × 15 + min(20, max(0, 近 3 日累計) × 2)` | 當沖視角下動能權重較低，避免追到漲停買不到 |
| **突破 (breakout)** | 突破前 5 日最高 +50／量 > 5 日均量 ×1.5 +30／站上 MA5 且 MA5 > MA10 +20 | 強烈但不重要：突破型態是隔日沖訊號，當沖追多空間有限 |

#### 加權

```
compound_intraday = 
    amplitude × 0.35
  + liquidity × 0.20
  + pattern   × 0.15
  + chips     × 0.15
  + momentum  × 0.10
  + breakout  × 0.05
  - penalty
```

#### 負分機制（penalty）

| 條件 | 預設扣分 | 設計動機 |
|------|------|----------|
| 前日漲停（前日漲幅 ≥ 9.8%） | 25 | 今日大機率跳空，買不到（這類標的應由盤中動態加入抓） |
| 連漲 ≥ 3 日且近 3 日累計 ≥ 15% | 20 | 過熱反轉風險（對齊 AiLesson 硬閾值） |
| 近 5 日有跌停（單日跌幅 ≤ -9.8%） | 15 | 主力倒貨痕跡 |
| 近 3 日累計跌幅 > 8% | 10 | 弱勢，不適合做多當沖 |

#### 設計取捨

- **沒有「核心保底」機制**：複合分數本身就是「值不值得當沖」的判斷，硬保留平盤大型股（如 4/29 的 2330 台積電）會浪費 Haiku/Opus 的 token 預算。真活躍的大型股（聯發科、台達電、國巨）會自己進前 30；若有突發爆發訊號，由盤中動態加入抓，不該在 08:00 物理門檻就預判。
- **隔日沖維持原邏輯**：`overnight` 模式仍依 5 日均量降冪排序，因隔日沖選的是「強勢延續到明天」的標的，與「今天有來回」的當沖目標不同。

#### 設定

| FormulaSetting type | 內容 |
|---------------------|------|
| `screener_compound_weights` | 6 項權重（amplitude/liquidity/pattern/chips/momentum/breakout） |
| `screener_penalties`        | 4 項負分閾值（prev_limit_up/hot_streak/limit_down_5d/weak_3d） |

於 `/settings` 頁面可調整，無需改 PHP。

#### Dry-run 對比工具

```bash
docker compose exec php php artisan stock:dry-run-screener --date=YYYY-MM-DD --watch=代號1,代號2 --top=20 --max=100
```

不寫入 DB，同時輸出「5 日均量榜 vs 複合分數榜」對比表 + 觀察名單排名變化，方便調整權重時驗證。

---

## 3.0 盤前方向簡報（DailyBriefing）

每日 08:30 由 `stock:premarket-briefing` 指令執行（休市日自動跳過），用 Opus 聚合隔夜素材產出當日交易方向，僅推 Telegram，個股選擇仍由 §3 AI 選股負責。

### 觸發時點

08:30 — 落在 08:15 NewsIndex 重算之後、08:45 台指期日盤更新之前。早於開盤 30 分鐘給操盤者消化空間，且不與 08:00 `stock:ai-screen` 衝突（兩者可並行）。

### 資料來源（皆只讀，不重抓 API）

| 區塊 | 來源 | 取用範圍 |
|------|------|---------|
| 市場情境 | `MarketContextService::detect($tradeDate)` | normal / bullish_catalyst / bearish_panic + triggers |
| 美股 + 夜盤 | `us_market_indices` (06:00 寫入) | trade_date 當日所有 symbol |
| 新聞情緒 | `news_indices` (08:15 寫入) | overall 一筆 + industry 與前一交易日 sentiment 差異 top 8 |
| 法人籌碼 T-1 | `institutional_trades` (16:30 寫入) | trade_date 之前最近一筆，total_net 買超 top 5 / 賣超 top 5 |
| **類股強弱 T-1** | `sector_indices` (前一交易日 15:30 寫入) | trade_date 之前最近一筆，change_percent top 5 強 / top 5 弱 |
| **大盤節奏代理** | `sector_indices` 近 5 個交易日 | 電子工業 / 金融保險 / 半導體業 5 日累計變化 + trend 標籤（strong_up / mild_up / sideways / mild_down / strong_down） |
| **重大事件新聞** | `news_articles` 近 24 小時 | `ai_analysis.impact='high'` 或 `panic_signal=true` 的 top 5（依 panic 優先、`ABS(sentiment_score)` 排序），含 industries / risk_type |

> 為何用「電子工業 / 金融保險 / 半導體業」做大盤節奏代理：`sector_indices` 不含加權指數（TWSE MI_INDEX 純類股），但這三類佔 TAIEX 約 70–80% 權重，足以反映大盤節奏。盤前 08:30 無法即時抓 TAIEX，採用 T-1 收盤前 5 日累計作為近似。

### Opus 輸出 schema

prompt 要求 Opus 回傳純 JSON：

```json
{
  "direction": "bullish | neutral | bearish",
  "headline": "≤ 30 字一句結論",
  "drivers": ["≤ 50 字 × 最多 3 條，依重要性排序"],
  "sectors": ["≤ 3 個產業類股名稱（依 direction 對應追擊/迴避/觀察）"],
  "cautions": ["≤ 60 字 × 最多 2 條，至少 1 條必須是具體操作建議（倉位/停損/節奏）"]
}
```

**sectors 欄位語意依 direction 切換**：
- `bullish` → 追擊類股（今日主流、相對抗跌、有催化）
- `bearish` → 迴避類股（最受國際利空衝擊、權值股拖累對象）
- `neutral` → 觀察類股（量能集中、可能領漲領跌）

**cautions 強制至少 1 條具體動作建議**，例如「建議倉位 ≤ 30%、停損縮緊至 2%、跳空後 15 分鐘止穩才考慮進場」。避免「謹慎觀察」這類含糊用語。

`PremarketBriefingService::parseResponse()` 容錯 markdown code fence、提取 `{...}` 區段；同時兼容舊欄位 `focus_sectors`。解析失敗時寫入 fallback 內容 + 標記 cautions，仍會推 Telegram。

### Telegram 格式（signal 級，所有啟用通知的用戶都收得到）

```
🌅 盤前方向 MM-DD

📉 偏空｜headline

驅動因素
1. ...
2. ...
3. ...

🚫 迴避類股：A、B、C        ← bullish=🎯 追擊類股 / neutral=🔍 觀察類股

⚠️ 操作建議 / 風險
- 倉位 ≤ 30%、停損 2%、開盤跳空後 15 分鐘止穩才進場
- 外資連賣面板族群，勿搶反彈

情境：bearish_panic（費半-3.57%、台指期-1.66%）
大盤節奏 5 日累計：電子工業 +5.36% / 金融保險 +4.48% / 半導體業 +5.9%
```

### 持久化

`premarket_briefings` 表（每日 1 筆，`trade_date` 唯一）保留：
- 結構化欄位 `direction / headline / drivers / focus_sectors / cautions`（`focus_sectors` 欄位名稱維持向後相容，儲存 Opus 回傳的 `sectors`）
- `raw_markdown`（Telegram 推播原文）
- `input_payload`（餵給 Opus 的完整 JSON snapshot，含 sector_strength / market_rhythm / key_events，保留供未來盤後校對閉環）
- `model / prompt_tokens / completion_tokens / cost_usd`（成本與用量）

### 設計原則

- **不重複個股推薦**：sectors 僅給類股，避免與 §3 AI 選股推薦的 10–15 檔重疊或互相干擾。
- **資料缺漏不阻斷**：即使美股、類股、或新聞無資料，Opus 仍會產出簡報，並在 cautions 註明缺口。
- **休市日跳過**：command 在 schedule 觸發時檢查 `MarketHoliday::isHoliday()`；手動傳 date 不檢查（保留補跑彈性）。
- **重複跑保護**：同 trade_date 已有 briefing 直接跳過，可用 `--force` 強制重跑覆蓋。
- **強制動作建議**：cautions 中至少 1 條為可執行操作（倉位/停損/節奏），避免簡報只給「方向」不給「動作」。
- **API retry**：`callOpus()` 內建 3 次重試，429 / 500 / 502 / 503 / 504 / 529 退避 5s / 10s；非可重試狀態直接中止；連續 3 次失敗才丟例外讓 command 走失敗路徑（schedule 會發 Telegram 系統失敗通知）。

### 成本

Opus 4.6：input ~4.6K token（含類股、節奏、事件）、output ~330 token → 約 **$0.09 / 天**（~$25 / 年，依交易日 250 天計）。

---

## 3. AI 選股審核（三階段流程）

每日 08:00 由 `stock:ai-screen` 指令執行，依序完成三個階段。

### 架構總覽

```
全市場股票
    │
    ▼ StockScreener（物理門檻）
物理門檻通過，top 100 by 當沖複合分數（intraday）／5日均量（overnight）
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

- 輸出：最多 100 檔，依當沖複合分數降冪排序（見 §2.5）
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

`stock:monitor-intraday` 為長駐 loop 進程，自 09:00 啟動直到 13:30 自行結束，**每 30 秒**執行一次快照週期。**只抓 AI 選入（`ai_selected=true`）的當沖候選**，不再包含隔日沖標的（隔日沖由 `stock:monitor-overnight-exit` 獨立抓取報價）。Scheduler 保留每分鐘觸發，搭配 `withoutOverlapping(60)` 確保：
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
         → skipped (AI 校準否決 / 收盤未進場)
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
| `skipped` | AI 校準否決 / 收盤前未進場 |

**初始化**：`MonitorService::initializeMonitors()` 使用 `firstOrCreate`（非 `updateOrCreate`），確保 scheduler 重啟時不會把已進場的 monitor 重置回 pending。

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
| **gap_reversal** | 跳空 ≥2% + 缺口不回補（最近低價 > 昨收+1%）+ 不連續下跌 |

共同前提條件：量能充足（預估量比 ≥ AI 設定值）、外盤比合理（≥ AI 設定值）、非弱勢走勢（連續 3+ 根下跌且下跌量 > 上漲量 × 1.5 判定為 weakness，不進場）。

**策略正規化**：AI 回傳無效策略名時（如 "breakout_momentum"），自動 fallback 為 momentum。

**strategy_override 套用**：AI 校準結果的 `strategy_override` 欄位若為合法策略名，會覆寫候選標的的 `intraday_strategy`。

**gap_reversal 特殊規則**：跳過 weakness 軌跡檢查（超跌反彈不適用常規走勢分類）。

#### 規則層進場前的 AI 即時確認（握手機制）

`evaluateWatching` 走完所有客觀條件（量比/外盤比/策略觸發/trajectory）、即將觸發進場前，呼叫 `IntradayAiAdvisor::confirmRuleEntry()` 做最終 second opinion。AI 看「最近 2 筆 rolling advice notes + 當前 5 分 K 結構」回覆：

| action | 規則層動作 |
|---|---|
| `go` | 繼續執行進場流程（transition 到 `entry_signal` → `enterPosition`）|
| `wait` | 不進場，下一輪 tick 重新評估（仍維持 `watching` 狀態）|
| `skip` | 走 `skipByAiAdvice`，轉為 `skipped`（終態）|

**模型**：`claude-haiku-4-5`（速度 ~1 秒，rolling advice 已用 Sonnet 做深度分析，本層只需「閱讀+表態」，Haiku 勝任且延遲低）。

**Fallback**：API 失敗或解析失敗時回傳 `{action: 'wait', fallback: true}` — **保守不進場**。誤殺成本（延後一輪）遠小於誤進場成本（觸停損 -2~3%）。

**結果儲存**：寫入 `monitor.entry_confirm_log`（**獨立於 `ai_advice_log`**），格式 `[{time, action, notes, fallback}]`。獨立欄位避免 rolling advice / emergencyAdvice / BacktestService / DailyReviewService 讀 ai_advice_log 時混到非 rolling 內容。

**設計動機**：5/6 3481 群創在 AI 連兩輪 hold 警示「不宜強追」「等止穩確認」之後，規則層仍因 isPullbackEntry 觸發而進場，3 分鐘後 AI 立刻 exit。過去 36 天 20 件短命進場（≤30 分鐘）平均 PnL -0.40%、勝率 25%。確認握手機制讓規則層每次觸發都拿到 AI 對當下盤勢的最新意見，從根本解決規則 vs AI 脫鉤問題。

**與 `emergencyAdvice` 的區別**：emergencyAdvice 用於 HOLDING 中的緊急狀況（急殺/量崩等）做出場判斷；confirmRuleEntry 用於 WATCHING → 進場前的最終把關。兩者是不同階段、不同決策的獨立機制。

#### 動態目標/停損公式

進場時依近 5 日平均振幅計算當日目標/停損（`evaluateWatching()` → `evaluateHolding()` 切換時設定）：

```
目標價 = round(進場價 × (1 + 5日均振幅% × 0.6), 2)
目標價 = min(目標價, 進場價 × 1.08)              // 振幅公式上限
若 AI 校準壓力位 > 進場價 且 ≤ 漲停價（昨收 × 1.10）:
    目標價 = max(目標價, AI 壓力位)              // AI 值可突破公式上限
目標價 = min(目標價, 漲停價)                    // 不可超過漲停

停損價 = round(進場價 × (1 - 5日均振幅% × 0.55), 2)
停損價 = max(停損價, 進場價 × 0.97)             // 下限 3%
```

**gap_reversal 策略另有公式**：
```
目標價 = AI 壓力位（若有且 ≤ 漲停）或 進場價 × 1.05
目標價 = min(目標價, 漲停價)
停損價 = max(缺口中點, 進場價 × 0.98)           // 缺口不可回補
停損價 = max(停損價, 跌停價)
```

AI 滾動建議隨時可透過 `adjustments.stop` 動態調整停損（鎖利）；**HOLDING 狀態停損只能往上調（鎖利），AI 試圖降低停損會被忽略（防止放大風險）**。WATCHING 狀態可透過 `adjustments.support` / `resistance` 更新支撐/壓力，影響下次進場判定。

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
| 13:25（HOLDING） | `closed`（強制平倉） |
| 13:25（WATCHING/ENTRY_SIGNAL） | `skipped`（收盤未進場） |

### AI 滾動建議（依時段動態頻率）

由 `IntradayAiAdvisor::rollingAdvice()` 對所有 active monitors 執行：

| 時段 | 頻率 | 說明 |
|------|------|------|
| 09:15-09:30 | 每 3 分鐘 | 開盤定調期，走勢最關鍵 |
| 09:30-10:30 | 每 5 分鐘 | 早盤活躍期 |
| 10:30-12:00 | 每 10 分鐘 | 盤中相對穩定 |
| 12:00-13:25 | 每 5 分鐘 | 尾盤決策期 |

一天約 60-70 次定期 AI call（不含緊急觸發），Sonnet 約 NT$50-80/天。

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
  "strategy": "momentum",
  "notes": "跳空已超過壓力位，gap_pullback 不適用，切換 momentum",
  "adjustments": {
    "target": 160.0,
    "stop": 152.0,
    "support": null,
    "resistance": null
  }
}
```

`strategy` 欄位為選填，任何 action 都可附帶。

| action | 狀態 | 效果 |
|--------|------|------|
| `hold` | 任意 | 套用 adjustments（若有）|
| `exit` | HOLDING | 立即以現價出場（獲利→trailing_stop / 虧損→closed）|
| `skip` | WATCHING | 轉為 skipped，放棄追蹤 |
| `entry` | WATCHING | C 級且 < 11:00 → 升格 B；套用 adjustments |

`adjustments.target` / `stop`：HOLDING 中調整目標/停損（可鎖利）
`adjustments.support` / `resistance`：WATCHING 中更新 AI 支撐/壓力位，影響進場判定

**策略切換**：任何 action 都可附帶 `strategy` 欄位，系統獨立於 action 處理策略切換。限制：A/B 級 + WATCHING 或 HOLDING 狀態。切換後同時套用 adjustments，下次 tick 的規則式監控即使用新策略觸發條件。HOLDING 時策略切換影響 AI 滾動建議的判斷框架（system prompt 中的 `## 策略` 標籤）。

#### Skip 是最後選項（決策框架）

WATCHING 任務段以「優先順序決策樹」設計：**(1) 策略適配檢查 → (2) 進場觸發評估 → (3) skip 是最後選項 → (4) 日 K 趨勢檢核**。System prompt 額外加入「**skip 的非對稱成本**」段落（skip 放棄全部上漲潛力 vs hold 受 stop 保護），引導 AI 對疑似失效訊號優先 hold 或 strategy 切換而非直接 skip。

僅以下情況才應 skip：(a) 連跌破多支撐 + 量縮、(b) 上方絕對無獲利空間（壓力位 = 漲停且無階段性壓力可設）、(c) 走勢分類為 weakness。「壓力位剩 +1% 以內」「量比偶爾低於門檻」「短期 K 線回落但未破支撐」等小瑕疵不應作為 skip 理由 — 應以 `adjustments.target` 設更近的階段性壓力或保持 hold 觀察。

**無條件 skip 的判斷原則（凌駕「skip 是最後選項」的對沖機制）**：當「結構性失敗」明確成立時直接 skip — 延後決策觸停損的實際虧損 > 放棄機會成本。**刻意採質性原則 + 典型參考值而非硬性門檻**，避免規則本身過度死板誤殺策略例外（如 gap_reversal 超跌反彈場景）：(1) 趨勢明確走弱（5 分 K 連續走低 + 量縮無接手；典型參考 -2%，但 gap_reversal 例外）、(2) 流動性風險（瀕跌停）、(3) 結構性失敗（原策略前提徹底破壞）、(4) 多方失守延續（破首根 K 低點且無止穩）。設計動機：避免「skip 是最後選項」原則被無限延伸，造成過度 hold 觸停損實際虧錢；同時避免硬性門檻（如「-2% 必 skip」）誤殺合理進場時機。

WATCHING 任務段同步附 5 條策略切換對照表（**只列「原策略失效改用其他策略」場景**：跳空超壓力 → momentum、突破回測 → breakout_retest、突破失敗反彈 → bounce、超跌反彈跳空 → gap_reversal、連跌破多支撐 → 直接 skip），刻意不列原策略仍生效的觸發場景，避免 AI 在原策略還能用時誤切換。設計動機：避免 5/5 出現的「30 檔當沖 25 檔被 AI rolling 主動 skip」假性放棄問題。

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

通知系統支援**多用戶分級推播**，每個用戶可在管理頁面設定 Telegram Chat ID 與通知開關。

**通知分級：**
- **signal（交易信號）**：發送給所有啟用通知的用戶（admin + viewer）
- **system（系統狀態）**：僅發送給啟用通知的 admin 用戶
- 無用戶設定 Chat ID 時，fallback 到 `.env` 的 `TELEGRAM_CHAT_ID`

**signal 級通知（所有人）：**

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

**system 級通知（僅 admin）：**
排程成功/失敗、資料抓取完成、健康檢查異常、Haiku 批次失敗等系統運維訊息。

---

## 3.9 腿 2：盤中動態加入候選（Intraday Mover）

### 背景

08:00 AI 選股基於前一日資料鎖定 10–15 檔當沖候選，但「今日才起飛」的標的不在名單上。腿 2 在 09:35 動態掃描盤中強勢股並加入候選。

### 觸發時點

- **09:35**：09:30 五分 K 收後 5 分鐘，抓「主流第一波」

### 待掃描池選擇邏輯（4+1 軸聯集）

從前一交易日 `DailyQuote` 取聯集，排除今日已在 candidates：

| 軸 | 說明 | 取 top N |
|----|------|---------|
| 前一日漲幅 | `change_percent` 降冪 | 100 |
| 前一日量比 | `volume / avg5_vol` 降冪 | 100 |
| 5 日均量 | `AVG(volume)` 降冪 | 100 |
| 5 日累計漲幅 | `SUM(change_percent)` 降冪 | 100 |
| 5 日內單日 ≥5% | 所有符合標的 | 全部 |

聯集後 ≈ 250–340 檔。只取 `is_day_trading=true` 且有 `industry` 的標的。

### 規則過濾（4 條）

透過 Fugle 即時報價後套用：

| 規則 | 閾值（FormulaSetting `intraday_mover_thresholds`） |
|------|------|
| 漲幅 ≥ X% | `min_change_pct` = 3.0 |
| 量比 ≥ X（盤中累計量 / 同時段 5 日均量估算） | `min_vol_ratio` = 1.5 |
| 不在漲停價 ±X% | `limit_up_buffer_pct` = 1.5 |
| 外盤比 ≥ X% | `min_external_ratio` = 55 |

### Haiku 快評

通過規則的 5–15 檔交由 Haiku 判斷「真突破 vs 假反彈/騙線」：

- 輸入：即時報價 + 5 分 K 序列（最近 8 根）+ 前日 K 棒 + 類股今日漲跌幅（`SectorIndex::getChangeForIndustry()`）
- 判斷重點：5 分 K 結構、量價配合、前日 K 位置、類股輪動（有實際數據支撐）
- 通過條件：`confidence ≥ min_haiku_confidence`（預設 60）
- **跳過 Opus**：盤中標的「價格動作本身就是訊號」，不需精審

### 進場價公式

```
suggested_buy = current_price（tick round）
target_price  = max(current × 1.03, prev_close × 1.07)，clamp 漲停
stop_loss     = current × 0.97，clamp 跌停
RR ≥ 0.8 → 不過則丟棄
```

### PENDING 陷阱避免

寫入 `Candidate` 時同步建立 `CandidateMonitor`（status=watching + target/stop），確保 `monitor-intraday` 下一輪 processSnapshot 立即接手。

Candidate 重要欄位設定：
- `source = 'intraday_mover'`
- `intraday_added_at = now()`
- `morning_grade = 'B'`、`morning_confirmed = true`
- `ai_selected = true`、`haiku_selected = true`

### Fugle 鎖機制

`Cache::lock('fugle_bulk', 120)` 避免 scan command 與 monitor-intraday 同時打 Fugle API：
- scan command：等 30 秒取鎖，報價完即釋放
- monitor-intraday：等 2 秒取鎖，取不到跳過此輪（30 秒後重試）

### 覆蓋率限制

MVP 測試 4/29 真實資料：對當日漲幅 ≥5% 的標的覆蓋率 ~65%。剩下 35% 是「完全無徵兆突然漲停」的股票，需付費資料源才能補。

### 手動指令

```bash
docker compose exec php php artisan stock:scan-intraday-movers

docker compose exec php php artisan stock:dry-run-movers --date=2026-04-29 --watch=1597,2049
```

第一行為盤中手動觸發；第二行為 dry-run（不打 API，用日 K 模擬）。

---

## 4. 消息面指數

### 資料來源

每日 06:00 / 08:00 / 12:00 / 18:00 透過 `news:fetch` 抓取，`news:compute-indices` 計算。

新聞主要來源為**鉅亨新聞** JSON API（`api.cnyes.com`），抓取三個分類：

| 分類 | API category | 對應 | 每次上限 |
|------|-------------|------|---------|
| 台股新聞 | `tw_stock` | `tw_stock` | 100 篇 × 2 頁 |
| 國際股市 | `wd_stock` | `international` | 100 篇 × 2 頁 |
| 外匯 | `forex` | `international` | 100 篇 × 2 頁 |

每個分類抓取第 1、2 頁（每頁 100 篇，頁間延遲 300ms），每次執行約 180+ 篇新聞（扣除重複）。無關鍵字過濾，全數收錄。

另每日 12:05 / 18:05 透過 `news:fetch-mops`（`FetchMopsAnnouncements`）抓取**上市櫃每日重大訊息 (MOPS)**，存為 `source='mops'` 的 `NewsArticle`，自動流入 `news:compute-indices` 的 Haiku 情緒分析與 `research()` 論點生成：

| 市場 | OpenAPI 端點 |
|------|------|
| 上市 | `openapi.twse.com.tw/v1/opendata/t187ap04_L` |
| 上櫃 | `tpex.org.tw/openapi/v1/mopsfin_t187ap04_O` |

- 重訊自帶**公司代號 + 結構化主旨/說明**，是一手、個股層級訊號，補 cnyes 綜合新聞在「個股早期訊號」上的弱項；`title` 組為「公司名(代號) 主旨」，`StockNewsRiskContextService` 的股名 like 比對能自動關聯到個股。
- **當日快照**端點（只回當日出表的重訊），每天抓累積、無法回補歷史。重訊發布後內容固定 → `firstOrNew(source + title + fetched_date)` 已存在即跳過，不覆蓋 compute-indices 已優化的 industry / 情緒。
- 民國年日期（`1150524`）轉西元、發言時間（`210422`）組 `published_at`；上櫃欄位名英文化（`SecuritiesCompanyCode` / `CompanyName`）、上市「主旨」key 帶尾隨空格，均做容錯 normalize。
- **全抓不預過濾**，題材價值交給 Haiku 在情緒分析時判 `impact` / `short_term_risk`（符合「物理層不塞硬閾值、判斷交給 LLM」原則）。
- 時序銜接：18:05 抓重訊 → 18:15 `compute-indices` 分析情緒並歸類 → 18:20 `research` 論點即可用上當日重訊。

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

### 產業歸類（NewsIndustryMap）

`NewsArticle.industry` 歸入 15 個產業類（`NewsIndustryMap::INDUSTRIES`）：半導體、AI與雲端、機器人、電子零組件、散熱、面板光電、通訊網路、金融、傳產、生技醫療、綠能車用、重電儲能、地緣政治、軍工航太、總體經濟。

**歸類優先序（`ComputeNewsIndices::analyzeArticles`）：Haiku 語義歸類優先、關鍵字 fallback。**

- `FetchNews` 抓取當下先用 `NewsIndustryMap::classify()`（關鍵字 best-match）設一個初值。
- `news:compute-indices` 跑 `SentimentAnalyzer` 時，Haiku 讀過內文摘錄後輸出 `industries`（語義判斷，能處理死關鍵字抓不到/歸錯的新題材），**先按頓號/逗號/斜線拆開連寫**（Haiku 偶把多產業連寫成單一字串如 `半導體、電子零組件`）、再經**白名單過濾**（只收清單內的類，擋對不上 `stocks.industry` 的雜值）後取首項覆蓋初值；Haiku 無對應時才退回關鍵字初值。
- **清單單一事實來源**：`SentimentAnalyzer` 兩個 prompt 的可選產業清單由 `array_keys(NewsIndustryMap::INDUSTRIES)` 動態生成，新增產業類只需改 `NewsIndustryMap` 一處，prompt 自動同步。

> 擴充產業類只影響往後抓取/分析的新聞；歷史 `NewsArticle.industry` 不回填。「散熱」「重電儲能」分別由「電子零組件」「綠能車用」拆出獨立成類（移走 `散熱` / `儲能` 關鍵字），避免 AI 伺服器散熱、資料中心電力等大題材被母類稀釋。

### 新聞內文 (`NewsArticle.content`)

`news_articles` 表新增兩欄（migration `2026_05_15_000001_add_content_to_news_articles`）：

| 欄位 | 型別 | 說明 |
|---|---|---|
| `content` | LONGTEXT NULL | 鉅亨新聞內文（最多 12000 字元） |
| `content_fetched_at` | TIMESTAMP NULL | 內文抓取時間，供差異化重跑判斷 |

`FetchNews` 抓 cnyes 列表時對 `tw_stock` / `wd_stock` 兩個 category 同步打 detail API `https://api.cnyes.com/media/api/v1/news/{newsId}` 抓內文；detail 失敗 fallback 抓 HTML 後 `strip_tags` 清理。每篇抓完 `usleep(200_000)` 節流避免被擋。`forex` 因實務上短線參考價值低，listing 不抓 content（backfill 因 `category` 二分（`tw_stock` / `international`）會順帶補到 forex，但不視為錯誤）。

`backfillMissingCnyesContent` 在每次 `news:fetch` 跑完列表後，掃當日 `content IS NULL AND url IS NOT NULL` 的前 30 篇補回，並把 `sentiment_score` / `sentiment_label` / `ai_analysis` 清空，讓 15 分鐘後的 `news:compute-indices` 用既有 `whereNull('sentiment_score')` 路徑自動重分析。每篇也加 `usleep(300_000)` 節流。

### SentimentAnalyzer 短線風險欄位

`SentimentAnalyzer::analyze()` / `analyzeBatch()` 的 prompt 新增「內文摘錄」（單篇 1200 字、批次 900 字）並要求 AI 同時輸出三個短線風險欄位，存進 `news_articles.ai_analysis`：

| 欄位 | 值域 | 說明 |
|---|---|---|
| `short_term_risk` | bool | 1-5 個交易日的短線風險旗標 |
| `risk_type` | `margin_pressure` / `earnings_quality` / `guidance_uncertainty` / `order_delay` / `cost_pressure` / `event_risk` / `none` | 風險類型白名單，越界值正規化為 `none` |
| `risk_reason` | string \| null | 一句話說明短線風險，若無則 null |

`risk_type` 中 `margin_pressure` / `earnings_quality` / `guidance_uncertainty` / `order_delay` / `cost_pressure` 屬財報週期相關利空；`event_risk` 是 catch-all，涵蓋減資、現金增資、GDR/ECB、大股東申讓、內部人賣超、鎖股解禁、訴訟、監管調查、工安、停工、罷工、火災、重大人事異動等財報外的單檔事件。

`defaultResult()` / `normalizeResult()` 統一補齊缺漏欄位、把 `risk_type` 框在白名單內，並強制三欄位一致：`risk_type=none` 或 `short_term_risk=false` 必同時清空對方與 `risk_reason`，避免 AI 回「有風險但分不出類」（如 `short_term_risk=true` 但 `risk_type=none`）的矛盾狀態。`max_tokens` 從 300 / 2000 提高到 600 / 3000。

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
| `buy_reachable` | 有 monitor → monitor 有實際進場；無 monitor → 當日最低價 ≤ 建議買入價 **AND T+0 未鎖死漲停**（隔日沖：T+0 鎖死漲停物理上無法成交 → false）|
| `unreachable_reason` | 為何 `buy_reachable=false`。當前值域：`t0_limit_up_locked`（T+0 漲幅 ≥9.5% 且 high==close 且 close>low）/ NULL（可成交或仍可能 T+1 觸及 suggested_buy）|
| `target_reachable` | 有 monitor → 同 `hit_target`；無 monitor → 當日最高價 ≥ 目標價 |
| `buy_gap_percent` | `(suggested_buy - low) / suggested_buy × 100`（正值=買得到）|
| `target_gap_percent` | `(high - effective_target) / effective_target × 100`（effective_target = monitor 最終目標 or 原始目標）|

**注意事項：**
- 需要當日 `daily_quotes` 資料才能計算（依賴 14:30 的 `stock:fetch-daily`）
- 若當日無行情資料（如該股停牌），則跳過不建立結果
- 已有結果的候選標的不會重複計算（`whereDoesntHave('result')`）
- 隔日沖結果回填採不同邏輯，會補齊既有 `candidate_results` 中缺漏的 overnight 欄位，見 §6.7。

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

> 本章是 overnight 行為的主規格；§1 只保留排程與資料流摘要。

### 6.1 概覽

隔日沖（Overnight）選股是獨立於當沖的第二條選股流水線，目的是在今日收盤前（13:00–13:25）建倉，持有至明日（T+1）收盤前平倉。

**關鍵時序：**

```
15:30  抓取類股指數（stock:fetch-sector-indices；當日盤後資料）
         │
12:50  三階段 AI 選股（stock:ai-screen-overnight）
         │  ← 12:50 早於 15:30 sector 抓取，所以取的是 T-1 類股
         │     （自動 fallback：latestDateOn 回最近一筆，符合「隔夜選股看 T-1」語意）
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

**重跑 / 補跑保護：**

`stock:ai-screen-overnight` 在 Step 1 之前會 DELETE 所有 `trade_date=$tradeDate` 的 overnight 候選（避免殘留），這意味著若有人在 12:50 之後手動重跑、且中途打斷（例如 Ctrl+C 或 API 失敗），原批次會被刪光但新批次沒進來。為此命令提供兩個 flag：

- `--force`：當 `trade_date` 已有完成過 Opus 審核（`ai_reasoning` 非空）的紀錄時，預設拒絕執行；加 `--force` 才能覆寫。
- `--backfill`：補跑模式，跳過 `CandidateMonitor` 初始化與 Telegram 通知。當 `trade_date` 已是過去日期時自動進入此模式（避免為舊批次建立 holding monitor 污染查詢）。

例：補跑 2026-04-29 的批次（trade_date=2026-04-30，已過）：

```
docker compose exec php php artisan stock:ai-screen-overnight 2026-04-29
```

此日期會自動進補跑模式；若已有完整批次需再加 `--force`。

### 6.2 雙日期設計

隔日沖流程中有兩個關鍵日期：

| 變數 | 值 | 用途 |
|------|----|------|
| `$snapshotDate` | T+0（今日 / 建倉日） | 查詢 `IntradaySnapshot`、`SectorIndex` |
| `$tradeDate` | T+1（出場日，跳過假日/週末） | 寫入 `candidates.trade_date`、查詢 `DailyQuote` |

`candidates` 表的唯一鍵為 `[stock_id, trade_date, mode]`，因此同一檔股票在同一個 `trade_date` 可同時存在 intraday 與 overnight 兩種模式。

**T+1 由 `MarketHoliday::nextTradingDay($snapshotDate)` 計算**，會跳過週末與國定假日。例如 2026-04-30（四）建倉，因 05/01 勞動節 + 週末，T+1 = 2026-05-04（一）。

**API `/api/candidates` 對 overnight 模式的日期語意：** 前端傳入的 `date` 參數一律代表「建倉日 T+0」；`CandidateController` 自動以 `MarketHoliday::nextTradingDay()` 換算為 `trade_date` 後再查詢，回傳 JSON 中同時包含 `date`（建倉日）與 `trade_date`（出場日）。`PinController` 採同樣語意。intraday 模式不受影響（`date` = `trade_date`）。

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

overnight 模式的物理篩選上限為 **top 100**（`max_candidates = 100`，與當沖一致），依 5 日均量降冪排序，不套用 §2.5 的當沖複合分數。

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

**T+0 鎖死漲停 hard rule：**
若 per-stock 訊息出現「T+0 鎖死漲停警示」（盤中漲幅 ≥9.5% 且現價=日高），entry_type **禁止回傳 `limit_up_chase`**——鎖死漲停的標的當日盤後無法以 suggested_buy 成交，計入此策略會污染回測。Opus 應改用 `gap_up_open` 或 `selected=false`；若 Opus 違反，`applyResultOvernight` 會自動降級為 `gap_up_open` 並 log warning。

> **觀察期決策點**：此規則上線後等 15 個交易日新樣本，若「可成交」的 limit_up_chase 仍負期望值或樣本 <5，應正式從 entry_type enum 拿掉（同步刪除 `buildSystemPromptOvernight` 的選項與 `BacktestService::computeOvernightMetrics` 的列舉）。

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

每日 **T+1 的 09:05 起至 13:25** 執行，早盤 09:05-09:25 每 5 分鐘、09:30 後每 15 分鐘，13:25 對未出場部位強制平倉，檢查所有 `ai_selected=true` 且 `CandidateMonitor.status` 尚未終止的隔日沖持倉。各時段獨立抓取 Fugle 報價（不依賴 `stock:monitor-intraday`），先做到價檢查再做 AI 滾動判斷。09:05-09:25 加密頻率確保開盤跳空達標/停損能在 5 分鐘內偵測。

#### 執行流程

1. 查詢 `candidates.mode=overnight`、`trade_date=T+1`、`ai_selected=true`、`monitor` 未終止的標的
2. 批次抓取 Fugle 即時報價（open、high、low、current、accumulated_volume）
3. 每檔依序判斷：

| 條件 | 動作 | monitor 狀態 |
|------|------|------------|
| `high >= current_target` | 自動達標 | `target_hit`（終止） |
| `low <= current_stop` | 自動觸停損 | `stop_hit`（終止）；`exit_price = min(stop, open)`（跳空跌破停損時以開盤價計） |
| 無觸發 | 呼叫 Sonnet AI 滾動判斷 | 依 Sonnet 回應決定 |
| 13:25 仍未終止 | 強制平倉 | `closed`（終止）；`exit_price = current_price ?? close ?? open` |

> **到價檢查**：早盤（09:05-09:25）每 5 分鐘排程，09:30 後每 15 分鐘。每次 `checkTimeSlot` 先做到價檢查再做 AI 滾動判斷。不再由 `stock:monitor-intraday` 代抓報價（避免 API rate limit 拖垮當沖校準）。

#### Sonnet 滾動判斷

Prompt 包含：時段標籤（09:05~13:25）、昨日收盤（建倉參考）、建議買入價、原始目標/停損、當前目標/停損、Fugle 開高低收及量、開盤跳空%（差異標籤五段細分：顯著超預期/小幅超預期/符合預期/小幅偏弱（雜訊範圍）/顯著不及預期）、距離買入%、5 分 K 線（快照或 Fugle fallback）、先前調整紀錄（最多3筆）。`max_tokens=1536` 確保 reasoning 能完整呈現「策略檢核+正負向訊號+結論」三段（先前 768 因中文三段論常超出 ~1000 tokens 而被截斷，造成 ~46% AI 回應 fallback 為「AI 不可用」）。HTTP 成功後同步檢查 `stop_reason === 'max_tokens'`，命中即記為截斷並 fallback，避免被誤判為「無法解析」。

System prompt 內含「**策略狀態與出場框架**」：每次先判斷 `strategy_state`（`valid` / `adjusted` / `uncertain` / `failed`），`exit` 只用在 `strategy_state=failed`，或尾盤時間不足且走勢沒有明確向上攻擊。跳空不如預期不是單獨 exit 理由，須結合首根 K、量比、支撐/停損與策略容忍度。

System prompt 同時保留「**entry_type 容忍度框架**」（`gap_up_open` / `pullback_entry` / `open_follow_through` / `limit_up_chase` 各自有不同容忍度）與「**早盤觀察期紀律（09:05~09:25）**」：早盤資訊有限，除非直接跌破停損且無收復、或量價結構明確失守，否則優先 `uncertain` / `hold` / `adjust`，避免在策略未展開前就退出。

| action | 效果 |
|--------|------|
| `hold` | 記錄 AI 建議，維持現狀 |
| `adjust` | 更新 `current_target` / `current_stop`，記錄調整紀錄 |
| `exit` | 建議提前出場，轉為 `closed`（終止），並以當下報價寫入 `exit_price = current_price ?? close ?? open` |

所有轉換皆寫入 `monitor.state_log`；AI 建議寫入 `monitor.ai_advice_log`（累加，不覆蓋）。

Fallback（API 失敗）：回傳 `{action: "hold"}`，維持現狀。

---

### 6.7 盤後結果回填（`stock:update-overnight-results`）

每日 **15:05** 在 T+1 收盤後執行。

查詢 `candidates.trade_date = T+1, mode = 'overnight'` 且尚未建立結果，或既有 `candidate_results` 缺少 `overnight_outcome` / `open_gap_percent` 的候選，寫入或補齊 `candidate_results`。若候選有 `overnight_strategy` 但 `gap_predicted_correctly` 缺漏，也會一併補齊；未選入且沒有 entry type 的標的允許該欄位維持 null。若候選有 `CandidateMonitor`，同步寫入 `monitor_status`、`entry_price_actual`、`exit_price_actual`、`entry_time`、`exit_time`，供實際出場績效報表使用；這些欄位不改變本節既有理論 outcome 口徑。

帶 `--force-recompute` 旗標時跳過上述「whereNull 補齊條件鏈」，對 `trade_date` 當日所有 overnight 候選強制重算所有欄位，並 `updateOrCreate` 覆寫——供歷史回填（如 `buy_reachable` 規則調整、新增欄位）使用。

判斷 T+0 是否鎖死漲停所需的 T+0 / T-1 日 K 採批次預載（`whereIn stock_ids`），避免每筆 candidate 各別 query。

| 欄位 | 說明 |
|------|------|
| `actual_open/high/low/close` | T+1 實際 OHLC |
| `hit_target` / `hit_stop_loss` | 當日高點 >= 目標 / 低點 <= 停損 |
| `open_gap_percent` | (T+1 開盤 - T+0 收盤) / T+0 收盤 × 100 |
| `gap_predicted_correctly` | 跳空方向與 `entry_type` 預測是否一致 |
| `overnight_outcome` | hit_target / hit_stop / gap_up_strong / gap_up / gap_down / up / down / neutral |
| `buy_reachable` / `unreachable_reason` | T+1 low ≤ suggested_buy **AND T+0 未鎖死漲停**；若 T+0 鎖死漲停（漲幅 ≥9.5% 且 high==close 且 close>low），`buy_reachable=false`、`unreachable_reason='t0_limit_up_locked'` |
| `monitor_status` / `entry_price_actual` / `exit_price_actual` | 實際出場績效用欄位；entry price 優先採 monitor，若隔日沖 monitor 未記錄進場價則以 `suggested_buy` 作為建倉參考價。**鎖漲停樣本不清空 entry/exit 價格**，僅以 `buy_reachable=false` 過濾，保留資料供「假設追進去多慘」分析 |

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

> **過濾規則**：`StrategyStatsService` 各 dimension 統一加 `where buy_reachable = true`，因此 `unreachable_reason='t0_limit_up_locked'` 的樣本不計入任何 `target_reach_rate` / `expected_value` / `avg_risk_reward`。物理上買不到的「假成交」不污染績效，也不會被注入 Opus prompt 誤導下一輪選股。

這些統計資料會注入 Opus overnight 選股的 System Prompt，提供量化基準。

`/overnight/stats` 另顯示實際出場績效（`actual_exit_rate`、`actual_win_rate`、`actual_stop_rate`、`avg_actual_return`），由 `candidate_results` 的 monitor 實際出場欄位計算。`actual_exit_rate` 以 AI 選入標的為分母；`actual_win_rate`、`actual_stop_rate`、`avg_actual_return` 只計入同時有實際 entry/exit 價格的樣本。v1 僅作報表並列顯示，不寫入 `strategy_performance_stats`，也不改變 `StrategyPerformanceStat::getPromptSummary('overnight')` 注入 Opus 的策略績效口徑。

### 6.10 類股指數（SectorIndex）

**資料來源**（雙端點、帶日期端點優先）：

1. **主要**：TWSE 帶日期端點 `https://www.twse.com.tw/exchangeReport/MI_INDEX?response=json&date={YYYYMMDD}&type=IND`
   - 回應 `stat:OK` + 包含當日資料；`stat` 非 OK 視為「該日尚未發佈」回 null。
   - 不會誤回 T-1（不像 OpenAPI 端點）。
2. **Fallback**：TWSE OpenAPI `https://openapi.twse.com.tw/v1/indicesReport/MI_INDEX`
   - 帶日期端點失敗時使用；保證有資料但可能為 T-1。

每日 **15:30**（weekdays）由 `stock:fetch-sector-indices` 抓取並存入 `sector_indices` 表。

**Why 15:30 而非更早**：TWSE OpenAPI MI_INDEX 端點實測 14:45 仍回 T-1，需給 TWSE 一段發佈時間；帶日期端點 15:30 後查當日多能拿到 stat=OK。

**Why 雙端點**：原本只用 OpenAPI 導致**每天 sector_indices 都是 T-1 資料**（5/28 抓到 5/27、5/27 抓到 5/26 …），對 18:50 swing 持倉檢討影響嚴重——AI 看到「電子零組件業 +2.73%」以為今日強勢，但其實是昨日資料、當日實為 -3.79%。

> **個股 ↔ 類股關聯依賴 `stocks.industry` 欄位**：個股查詢類股漲跌、類股排名、新聞題材配對，皆使用 `stocks.industry` 字串比對 `sector_indices.sector_name`。
> 此欄位由 `stock:fill-industry` 從 TWSE/TPEX OpenAPI（`t187ap03_L` / `mopsfin_t187ap03_O`）抓取的 MOPS 產業別代碼對映成中文類股名（如 `24` → `半導體業`）。
> 對映表 `INDUSTRY_NAME_MAP` 內建 36 種代碼，命名與 `sector_indices.sector_name` 對齊，化學工業（21）併入「化學生技醫療」（與 TWSE 公布的合併指數一致）。
> 排程於**週一 06:00**執行（產業分類極少變動，每週一次足夠）；亦可帶 `--force` 重新覆蓋既有資料。

> **資料日期保護**：所有查詢方法已內建 `latestDateOn` fallback：指定日期無資料時自動使用最近一個有資料的交易日。`SwingPositionUpdateService::buildSectorContext` 額外於回傳 dict 加 `data_date` + `is_previous_day` 欄位、prompt 明示「is_previous_day=true 代表前一交易日資料、不可當作當日強弱」，避免 AI 把 T-1 誤讀為當日。

涵蓋 29 個類股，包含：電子工業、半導體業、金融保險、鋼鐵工業等主要類股。

`SectorIndex` 模型提供便利方法：
- `latestDateOn(string $date): ?string` — 取得指定日期（含）以前最近有資料的日期
- `getSectorSummary(string $date): string` — 所有類股漲跌幅（自動 fallback，格式化供 AI prompt）
- `getChangeForIndustry(string $date, string $industry): ?float` — 取特定類股漲跌幅（自動 fallback）
- `getRankForIndustry(string $date, string $industry): ?int` — 取類股強弱排名（自動 fallback）

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
- 當沖/隔日沖均載入 `monitor` 關聯，若 AI 監控調整了目標價或停損價（`current_target` / `current_stop` 與原始值不同），卡片上以粗體顯示調整後價格，原始價格以刪除線灰字標示於右側

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

---

## 9. 短線配置頁（`/swing`）

### 9.1 持倉現價分鐘級即時更新

短線持倉卡（`SwingView.vue` → 「我的短線持倉」）的現價來源不再用 `DailyQuote`（昨收盤），改為三段 fallback：

1. **當日 `intraday_snapshots` 最新一筆**（持有檔同時是當沖 AI 選入時最便宜）
2. **Fugle realtime quote**（`fetchRawQuote` 取 `closePrice` + `referencePrice`）
3. **DailyQuote 最近一筆**（盤前 / API 失敗保底）

實作於 `SwingController::resolveLivePrice(stockId, symbol)`，回傳 `{current_price, prev_close, change_pct, source, fetched_at}`。結果以 `swing:live-price:{symbol}` 為 key Cache 30 秒（前端輪詢 60 秒，多帳號持同檔股票共用一份，避免 Fugle rate limit）。

### 9.2 API：`GET /api/swing/positions/live-prices`

輕量端點，只回 active 持倉的即時報價。前端用 `setInterval(60_000)` 在盤中時段（週一至五 09:00–13:45）輪詢；非市場時段或沒有 active 持倉時暫停。

回傳：
```json
{
  "server_time": "2026-05-12 09:37:22",
  "data": [
    {
      "id": 22,
      "symbol": "2313",
      "current_price": 258.5,
      "prev_close": 242,
      "change_pct": 6.82,
      "unrealized_profit_percent": 5.1,
      "market_value": 517000,
      "source": "fugle",
      "fetched_at": "2026-05-12 09:37:22"
    }
  ]
}
```

`source` 三種值：`snapshot` / `fugle` / `daily_close`（或 `none` 無法取得時）。

`SwingController::positions()` 也改用同一個 `resolveLivePrice`，所以手動「刷新」按鈕也會拿到即時。

### 9.3 UI

每張持倉卡片右上角為「即時現價區塊」（取代原本只顯示 PnL 的位置）：

- 大字體現價（30px）+ 漲跌幅 %
- 下方一行：浮盈 % + 更新時間（`HH:mm:ss`）
- 上漲紅 / 下跌綠（沿用 `--c-up` / `--c-down`）
- 報價變動時 800ms `livePulse` 動畫高亮邊框
- 卡片下方 5 欄 stats 改為「成本 / 股數 / 停損 / 目標 / 市值」（現價已拉到右上不再重複）

### 9.3a AI 檢討卡（advice-callout）資訊優先順序

`SwingPositionUpdateService::askAi()` 回傳的 `latest_advice` JSON 欄位豐富（≈ 25 個 key），前端按「**結論 → 狀態 → 下一步觀察 → 深度**」分四層顯示，把可立即執行的訊號從長文 reasoning 裡抽出來：

**Layer 1 — 結論（永遠顯示）：**
- `action`（續抱／調整／減碼／出場）+ ⚠️停損已破 badge（`stop_breached=true` 時整個 callout 換紅左邊條 + 淡紅底）+ 技術 fallback badge（`is_fallback=true`）+ 快照時間
- `decision_summary`：白底圓角一句話結論（💬 開頭），最該被掃到的 1 個欄位

**Layer 2 — 狀態（永遠顯示）：**
- `stop_changed` / `target_changed` 變化列（沿用既有 adjust-line）
- health-grid：論點 / 技術 / 籌碼 / 風險
- time-grid：預估持有 / 目標 ETA / 時間壓力

**Layer 3 — 下一步觀察（條件式顯示）：**
- `repair_condition` / `failure_condition`：綠／紅雙欄並排（行動驅動，告訴使用者明天看到什麼要續抱 vs 出場；停損審查 / 風險區觀察情境才會有值）
- `chip_risk_notes[]`：白底框 + bullet list，把「籌碼🔴」的具體成因列出來（法人賣超、融資增、現增稀釋等）

**Layer 4 — 深度（`<details>` 折疊）：**
- `volume_price_signal`、`target_price_reasoning`、`eta_reasoning`、`why_not_exit`、`why_not_hold`、完整 `reasoning`

**Why 這個順序：** 過去整個 advice 平鋪展示，使用者要讀 800+ 字 reasoning 才能找出「明天要看什麼」。新分層讓 `repair_condition` / `failure_condition` / `decision_summary` / `chip_risk_notes` 從 reasoning 裡浮出來，使用者打開卡片就能掌握「結論 + 狀態 + 該觀察什麼」，深度推理留在折疊區供需要時展開。

### 9.3b admin 手動重跑選股

頁首 admin-only「重新選股」按鈕(`SwingView.vue`,`v-if="authStore.isAdmin"`),用頁首日期選擇器的 `currentDate` 當參數——切到哪天就重跑哪天(對應 19:00 排程失敗、或凌晨手動補某交易日的情境)。

**Why:** `ai-screen-swing` 靠 Opus,偶爾連續 3 次回覆不通過 `assertValidAiSelections` 校驗 → `askAiWithRetry` 耗盡 throw → 當日零候選(「寧缺毋濫」設計,不退回規則分)。原本只有次日 22:00 health check 標 `短線候選未產出` 事後告警,admin 無法即時補。

**流程**(全照 `ProcessNewsFetch` / `NewsController::fetch` 模式):

1. `POST /api/swing/rescreen`(admin only):cache `swing_rescreen_status:{date}` 若 `running` 則擋重複派發,否則 dispatch `RescreenSwing` job、立即回「已觸發」。
2. `RescreenSwing` job(redis queue,timeout 600):跑 `Artisan::call('stock:ai-screen-swing', ['date'=>$date])`,前後寫 cache 狀態。**`try/catch` 包起來**——順手補了 command 本身沒有 try/catch、失敗會靜默 crash 的洞;Opus 失格時寫 `success=false` + 友善訊息。
3. `GET /api/swing/rescreen-status`(admin only):前端每 3 秒輪詢,`done` 後依 `success` 彈成功/失敗提示,成功則重新拉 `/swing/candidates`。

`date` 經 `ai-screen-swing` 既有邏輯:非休市日 `trade_date = date`,所以補哪天就精準寫哪天的 `trade_date`。

### 9.4 AI 短線教訓回流系統（AiLesson mode=swing）

當沖 / 隔日沖已有 `AiLesson` 教訓系統（每週五 16:00 從 `daily_reviews` 萃取），短線（swing）原本沒有對應機制 — 使用者按平倉的瞬間，那筆持倉就從 AI 視野裡完全消失：`SwingPositionUpdateService` 與 `DailyReviewService::buildSwingReview()` 都只看 `ACTIVE_STATUSES`，`SwingScreenerService::askAi()` 與 `SwingPositionUpdateService::askAi()` 兩支 AI prompt 也沒有教訓注入點。導致使用者好決策（提前停利避過拉回）與壞決策（過早殺低錯失反彈）都無法回流到 AI。

短線教訓系統補上這條迴路。

#### 9.4.1 資料模型

`ai_lessons` 表沿用，新增 `mode='swing'` 值。

`swing_positions` 新增兩欄（migration `2026_05_12_000001_add_exit_reason_to_swing_positions`）：

| 欄位 | 型別 | 說明 |
|---|---|---|
| `exit_reason` | VARCHAR(32) NULL INDEX | enum 白名單（PHP 端驗證） |
| `exit_note` | TEXT NULL | 使用者選填補充說明，≤200 字 |

`exit_reason` 合法值：

| 值 | 語意 |
|---|---|
| `target_hit` | 觸及 current_target 自動出場 |
| `stop_hit` | 觸及 current_stop |
| `take_profit_manual` | 主動停利（中間區間獲利出場） |
| `cut_loss_manual` | 主動停損（中間區間虧損出場） |
| `thesis_broken` | 論點失效 |
| `time_stop` | 持有時間到期 |
| `switch_position` | 換倉 |
| `other` | 其他 |

`SwingController::updatePosition()` 接受 `exit_reason` + `exit_note`；若未傳，依 `exit_price vs current_target / current_stop / entry_price` 自動推算預設值（觸目標/觸停損優先，否則以 entry 為基準分主動停利/停損）。前端 `markClosed()` 開啟 dialog（radio 8 選項 + textarea 補充說明），預設值由前端做相同推算。

#### 9.4.2 萃取流程

排程：每週日 17:00 `stock:extract-swing-lessons`（`routes/console.php`），避開週五 16:00 `stock:extract-weekly-lessons`，並確保前一交易週已完整收盤、5 日 forward window 有資料。

實作於 `App\Services\SwingLessonExtractor::extract($weekEnd, $dryRun, $log)`。

**輸入窗口**：`endOfWeek(SUNDAY)` 回推 7 天，**只納入 `exit_date <= sunday - 5`** 的持倉，確保每筆都有完整 5 日 forward 報價。

**讀的資料**：
- `swing_positions` (status closed/stopped + exit_date 落於窗口) join `candidates`+`stocks`
- 每筆 `advice_log` 取最後 3 筆（last_ai_action 軌跡）
- `daily_quotes` 每檔出場後 5 個交易日的 high/low/close

**PHP 端預聚合**（先算好再餵 AI，省 token 也防幻覺）：
- per-position：`realized_pct`、`forward_5d_pct`、`forward_max_pct`、`forward_min_pct`、`last_ai_actions[]`、`user_vs_ai_divergence`（最後 AI action ∈ [hold, trim] AND status=closed）
- 整週彙總：總筆數、勝率、平均持有日、平均報酬、exit_reason 分布、divergence 數

**Prompt 結構**（送 Opus 4.6）：
1. Role：「台股短線策略檢討顧問」
2. `# 本週統計` — 彙總數字
3. `# 個別持倉` — 每筆一行，**個股代號脫敏**為 `[產業-序號]`
4. `# 本週短線檢討報告` — 最多 2 筆，每筆截 1200 字
5. `# 重點分析角度` — 鎖死思考方向（take_profit_manual 後續走勢、cut_loss_manual 後續走勢、user_vs_ai_divergence、strategy / benefit_level 命中率）
6. 萃取規則（嚴格）：最多 5 條、跨多檔重複才算、禁具體代號/日期、type 限定 `[screening, entry, exit, market]`、category 白名單

**寫入規則**：
- 先 `delete()` 同週 `mode=swing AND source != 'tip'` 舊資料（保留人工 tip）
- 新筆 `mode='swing'`, `source='weekly'`, `trade_date=$sunday`, `expires_at=now + 14 days`
- 寫入前正則 `\b\d{4}\b` 掃 content，命中即丟棄該條（防代號洩漏）
- type 不在白名單即丟棄

**樣本不足策略**：
- 0 筆 → 跳過、不污染（不寫 placeholder）
- 1-2 筆 → prompt 加註「樣本極少」，要求只輸出 type=market 大方向最多 2 條

#### 9.4.3 注入點

兩支 AI 取教訓方法（`AiLesson` model）：

| Method | 過濾條件 | 上限 | 用於 |
|---|---|---|---|
| `getSwingScreeningLessons($limit=15)` | mode∈[swing,both] + type∈[screening,market,entry] | 15 | 選股 prompt |
| `getSwingAdviceLessons($limit=12)` | mode∈[swing,both] + type∈[entry,exit,market] | 12 | 滾動建議 prompt |

兩者皆按 `priority DESC, trade_date DESC` 排序，DB 空時回空字串（prompt 不會出現空標題）。

**Prompt 插入位置**：
- `SwingScreenerService::askAi()`：插在 `# 產業論點` 之前（讓 AI 思考論點時就帶教訓）
- `SwingPositionUpdateService::askAi()`：插在 `# 基礎約束` 之前（context 之後、規則之前）

#### 9.4.4 讀 API + 前端展示

`GET /api/swing/lessons`（viewer + admin）：

```json
{
  "count": 4,
  "data": [
    {
      "id": 123,
      "trade_date": "2026-05-10",
      "type": "exit",
      "category": "divergence_from_ai",
      "mode": "swing",
      "source": "weekly",
      "priority": 0,
      "content": "...",
      "expires_at": "2026-05-24",
      "days_left": 12
    }
  ]
}
```

短線績效頁 (`SwingStatsView.vue`) 新增「AI 教訓」section，放在「實現績效」之後、「走勢趨勢」之前。每條卡片顯示：type tag（選股/進場/出場/大盤）+ category + 來源（每週萃取 / ★明牌）+ 剩餘天數 + 完整 content。

#### 9.4.5 健康檢查覆蓋

`stock:health-check`（每日 22:00）新增 3 項與短線相關檢查（`HealthCheck.php` 5c4-5c6）：

| 檢查項 | 觸發條件 | 等級 |
|---|---|---|
| **短線候選** | 工作日 19:00 ai-screen-swing 應產出；20:00 後仍無 candidates 列入當日 trade_date → warn | ok / warn |
| **短線持倉快照** | 有 active 持倉時，當日 `swing_position_snapshots` 數應等於 active 持倉數；20:00 後缺漏 → warn（提示 update-swing-positions 漏跑）| info / warn |
| **短線教訓新鮮度** | `ai_lessons mode=swing source!=tip` 最新 trade_date 距今 > 14 天 → warn（提示 extractor 連續失敗）| ok / warn / info |

20:00 後的時間閾值避免 09:50 跑健康檢查時誤報「當日沒有 19:00 才產出的東西」。

### 9.5 停損審查模式

`SwingPositionUpdateService::buildAdvice()` 將 `close <= current_stop` 視為最高優先級警訊，但不再等同於必然出場。短線 stop 是「原始交易假設需要重新審查的界線」，不是單點死亡線。

現行規則：

1. **停損跌破仍由機械判斷**（`$stopBreached = $close <= $stop`）並標記為旗標。
2. **風險區觀察獨立判斷**：近 7 日碰過停損附近或出現 exit 壓力，但今日沒有跌破 stop 時，標記 `risk_zone_touched=true`，不得填 `stop_review_state`。
3. **強制呼叫 AI** 走 askAi 完整流程，傳入 `stop_breached` 與 `risk_zone_touched`。
4. 只有 `stop_breached=true` 時，Prompt 進入「停損審查模式」，要求 AI 重新判斷：
   - 原始 thesis 是否仍成立
   - 跌破停損是個股問題、類股拖累、市場拖累，或混合因素
   - 資金是否撤退（量價 / 法人 / 融資 / 同族群表現）
   - 若不出場，下次檢討要看到什麼才算修復；若沒有發生什麼就該出場
5. `risk_zone_touched=true` 只代表近期受壓，近 7 日 exit 訊號是風險提醒，不是出場命令。若 thesis 仍有效、價格已收回關鍵位置、籌碼沒有惡化，可以 `hold`；若尚未確認修復，才 `trim` / `adjust`；若核心支柱破壞才 `exit`。
6. AI 可回 `hold` / `trim` / `adjust` / `exit`：
   - `exit`：原始進場假設失效，或技術/籌碼/論點核心支柱明確破壞
   - `trim`：假設未完全失效，但風險升高，先降部位
   - `adjust`：可調整目標、縮短 ETA 或上移停損；不可下修停損
   - `hold`：只允許在 thesis 仍成立且有清楚 `repair_condition` 時使用
7. 非 `exit` 情境保留既有保護：`current_stop` 只能維持或上移，不得下修，避免停損審查或風險觀察變成凹單。
8. AI 回傳會保存檢討欄位於 `latest_advice` / `advice_log`：
   - `decision_summary`
   - `why_not_exit`
   - `why_not_hold`
   - `stop_breached`
   - `risk_zone_touched`
   - `stop_review_state`
   - `stop_review_reasoning`
   - `repair_condition`
   - `failure_condition`
   - `market_vs_stock_issue`
   - `previous_stop_review`
9. `normalizeAdvice()` 會強制規範：`stop_breached=false` 時，`stop_review_state` / `stop_review_reasoning` 一律歸 `null`；只有真正跌破 stop 的 advice 才會成為 `previous_stop_review`。
10. 若上一輪已因停損給過觀察，本輪 prompt 會注入上次 `repair_condition` / `failure_condition`；若修復條件未發生，預設應 `trim` 或 `exit`，避免無限延後出場。
11. AI 呼叫失敗時仍採保守退路：`askAi()` 內含 **3 次重試**（HTTP 429/5xx/529 等暫態錯誤 sleep 5/10s、JSON 解析失敗 sleep 3/6s），且預先 strip markdown code fence (```` ```json ````) 後才嘗試解析。3 次都失敗才走 fallback；若已跌破停損但無法完成 AI 審查，回 `exit` 並提醒人工檢視。每次失敗都會寫 `Log::warning` 並記下 attempt 次數、HTTP code、回應前 200-300 字，避免靜默假象 exit。
12. **Fallback advice 標記**：AI 連續 3 次失敗時，service 寫 fallback advice（`action=exit` 或 `trim` 視觸發路徑）會額外帶 `is_fallback: true` 旗標。`lastStopReviewAdvice()` 撈過往停損審查決策時跳過 `is_fallback=true` 的 entries — 避免技術 fallback 被當作「上次 AI 審查結論」污染後續真實 AI 的決策（規則 10 不該因 fallback 觸發強制 exit）。Fallback advice 仍保留在 `advice_log` 供 audit；`Log::info` 會記跳過 entry 的 position_id / time / action 方便事後 grep。前端 `SwingView.vue` 在 advice 區顯示「技術 fallback」黃底 badge 提示使用者該決策非 AI 判斷。

   **`is_fallback` 語意**：表示「AI 未完成審查」，**不代表決策本身錯誤**。三條 fallback 路徑（A: 停損 + AI 失敗 → exit；B: 論點失效 + 技術破壞 + AI 失敗 → exit；C: 論點失效 + AI 失敗 → trim）的 action 本身仍然是合理的風控選擇；標記僅用來防止這些「機器決策」被當作「AI 已審慎判斷」綁定後續真實 AI 的選擇空間。

### 9.5a 持倉↔論點對齊：`thesis_id` 權威鍵

持倉與其進場論點的綁定**以 `thesis_id` 為權威鍵、`title` 為 fallback**，不再單靠 title 字串對齊。

**Why：** `InvestmentThesisResearchService` 的 research prompt 雖要求「命名穩定」，但 title 是 Opus 自由產生的字串，無法保證不漂移。一旦論點被改名（例如「…HBM/PCB/散熱鏈」→「…HBM/PCB/散熱/CCL 鏈」），舊靠 `swing_thesis['title']` 撈論點的邏輯就會撈不到 → `resolveThesisStatus` 誤判 `missing` → 若當天技術面剛好轉弱，§9.5 fallback 路徑 B（論點失效＋技術破壞）就誤觸 `exit`，把基本面仍健康的持倉洗出場。

**機制：**

1. **寫入端一致性**（`SwingScreenerService::screen()`）：`swing_thesis` 快照的 `thesis_id` 與 `title` 強制同源於物理層比對結果（`topThesis`），不讓選股 AI 自由發揮的 `title` 蓋掉物理層值造成 id/title 脫鉤。AI 的 `benefit_level` / `role`（個股角色判斷）仍保留。`topThesis` 為 null（該股無任何 thesis link）時快照無 `thesis_id`，下游走 title fallback。
2. **解析 helper**（`InvestmentThesis::resolveFromSnapshot(?array $snapshot)`）：`thesis_id` 優先 `find()`、撈不到才用 `title` 查；id 與 title 都撈不到回 `null`。相容沒有 `thesis_id` 的舊快照，**無需回填歷史資料**。
3. **持倉複查**（`resolveThesisStatus`）：改用 `resolveFromSnapshot`。只有 id 與 title 都撈不到才標 `status=missing` / `invalidation_signal=true`，`invalidation_reason` 改為 `thesis_not_found_in_db`（語意：論點以 id 對齊後仍找不到，多半已被淘汰或人工移除，非必然基本面壞，仍不可單獨 exit）。
4. **統計/顯示分組**（`SwingController::riskExposure` 曝險、`SwingLessonExtractor` 教訓萃取）：groupBy 鍵改為 `thesis_id ?? title`，同一論點即使改過名也聚在同一組，曝險/教訓不被拆成兩條失真。（原 `BacktestService` 的 by_thesis 績效也用此鍵，但已隨 paper 移除而一併刪除，見 §9.5d。）

### 9.5b 持倉過熱事實對稱化

定義於 `SwingPositionUpdateService::buildTechnicalContext()` 與 `askAi()` 基礎約束段。

**Why：** §9.7 讓選股 Opus 看到 4 條過熱事實，但 daily review 的 `buildTechnicalContext` 原本只回 `close / ma10 / ma20 / ma60 / atr / volume_ratio_20d / health`，**完全沒有對稱事實**。且 `health` 只看下檔（`close < ma20 → weak`、`close < ma60 → broken`），持倉拉到距 MA20 +11%、RSI 74 仍標 `health=healthy`，與訊號矛盾，導致 AI 容易把該 `trim` 鎖利的持倉判成 `hold`。

**4 條對稱事實**（永遠輸出、無門檻，與 §9.7 reasons 標籤對應）：

| Key | 計算 | 對應 §9.7 標籤 |
|---|---|---|
| `gain_3d_pct` | `(closes[0] - closes[3]) / closes[3] × 100`，round 至 1 位小數 | `近3日±X%` |
| `price_streak_days` | `TechnicalIndicator::priceStreak($closes)`（正=連漲、負=連跌） | `連漲/連跌 N 日` |
| `ma20_dist_pct` | `(close - ma20) / ma20 × 100` | `距MA20 ±X%` |
| `rsi` | `(int) round(TechnicalIndicator::rsi($closes))` | `RSI <value>` |

daily review 用結構化 JSON key 而非 chip 字串，因為 prompt context 是 `json_encode($technicalContext)` 餵入，不是 csv。

**`TechnicalIndicator::priceStreak()`** 為 swing screener (`buildCandidatePayload`) 與 daily review (`buildTechnicalContext`) 共用 helper，避免兩處重複實作。

**Prompt 規則**（原則性、無硬閾值）：

> 「技術 context 中的 `gain_3d_pct / price_streak_days / ma20_dist_pct / rsi / volume_ratio_20d` 反映持倉的動能延伸、位置偏離與量能狀態。`health` 為單向下檔指標（跌破 MA20 才 weak），不會反映上檔過熱。若這些事實整合顯示持倉已遠離均線、動能轉強過熱、或量價背離，請判斷是否該 `trim` 鎖利或上移 `current_stop`；過熱與否由你綜合考量，不給硬閾值。」

**`volume_ratio_20d`** 既有欄位（line 691），本次規則一併納入「動能 + 位置 + 量」三軸整合。

### 9.5c 大盤情境與持倉軌跡注入

定義於 `SwingPositionUpdateService::askAi()`。

**Why：** swing daily review 過去 30 天 70% advice 是 exit、過去 14 天 22 筆 real-AI exit 中 55% 賣早。根因之一為 AI 看不到「大盤背景」與「持倉軌跡」：
- **缺大盤情境**：panic 日（如費半 -6.39%）時，AI 無法區分「個股 thesis 失效」vs「全市場拖累」→ 傾向歸因個股 → exit
- **缺持倉軌跡**：AI 只看當日 close vs entry_price，看不出「曾經 +12% 現在 +3%」vs「持平到現在」差別 → 沒有 trim 鎖利的判斷依據

**Feature 1：MarketContext 注入**

`askAi` 開頭呼叫 `MarketContextService::detect($tradeDate)` 與 `toPromptSection($context)`，注入 `# Context` 區塊頂端。reuse 既有 service（與 `AiScreenerService` / `HaikuPreFilterService` / `PremarketBriefingService` 共用，避免重複實作）。`normal` label 時 `toPromptSection` 回空字串 → 不加 noise；`bullish_catalyst` / `bearish_panic` / `sector_rotation` 時注入完整 label + triggers + hint + 受益產業。

> 自 2026-05-28 起，`MarketContextService::detect()` 盤後執行時會自動疊加當日台股大盤廣度訊號（見 §1「市場情境判斷」），台股當日恐慌可覆蓋海外利多（反之亦然）。這對 swing daily review 直接受益 — 5/28 案例：費半 +4.09% 但台股戰爭崩跌時，AI 收到的不再是 `bullish_catalyst`，而是 `bearish_panic` + 「個股暴跌先以市場拖累為基底解釋」hint，避免把市場性恐慌歸因為個股 thesis 失效。

**Feature 2：持倉軌跡（peak + drawdown）**

從 `swing_position_snapshots` 撈 `position->entry_date` 至前一日的所有 snapshot，取 `unrealized_profit_percent` 最大值為 `peak_profit_pct`。`drawdown_from_peak_pct = max(0, peak - currentProfit)`。注入「持倉」區塊內：

```
進場後高水位：{peak}% | 目前浮盈：{current}% | 從高水位回撤：{drawdown}%
```

**Caveat 1（精度）**：peak 用當日 close 算的 unrealized_profit_percent，不是盤中 daily_high。真實最高水位可能高 1-3%（極端波動日更大）。本次接受精度誤差換實作簡單；若日後發現影響 AI 判斷，再改用 `DailyQuote::high` 自 entry_date 起 max。

**Caveat 2（語意）**：持倉從未轉正（一直浮虧）時 peak 可能為負值（例：entry +0%、最低 -5%、現在 -3% → peak=0%、drawdown=3%）。Drawdown 描述「從 entry 點以來的回撤」而非「lock-in 機會回吐」。Prompt 不額外解釋，由 AI 從 `目前浮盈` 為負自行判讀。

**首日退路**：無 snapshot 歷史時 peak = currentProfit、drawdown = 0；AI 該從 `holding_days=0` 推斷首日。

**Prompt 規則注入**

`# 進階仲裁` 段加第 11 條（原則性、無硬閾值）：

> 「`市場情境` 為 `bearish_panic` / `bullish_catalyst` 時，股價短期表現相當程度受大盤拖累/帶動；判 thesis_health 與 market_vs_stock_issue 時請整合考量。`進場後高水位` vs `目前浮盈` 顯著回撤（無硬閾值，由你判讀）時，請評估是否 trim 鎖利或上移 stop 而非直接 exit。」

**Feature 3 deferred**：「過去 advice 準確率」（AI 自我修正）需新增預計算統計表（風格類似 §9.6.1 `swing-news-risk-stats`），實作成本高，另開議題。

### 9.5c-1 執行時點語意（T+1 開盤生效）

`SwingPositionUpdateService::askAi()` 於 18:50 跑（盤後），但 AI 過去措辭常寫「**今日收盤後出場**」「**立即下修停損**」這類字眼，盤後時點已無法執行，使用者實際只能在下一交易日（T+1）09:00 開盤後手動執行。

**Why：** prompt 沒明示時間語意，AI 用日常自然語言推理，容易把「現在」誤認為可即時動作的時點。

**修法**：`# 基礎約束` 段第 1 條（最頂）強制：

> 「執行時點：本檢討於 T 日盤後執行，所有 action 與 stop/target 調整於 **T+1 開盤後**由使用者執行。`decision_summary` 與 `reasoning` 提及執行時點時，請用『明日開盤』『下一交易日』等字眼，禁止使用『今日收盤後』『立即』『即刻』『現在』這類盤後無法執行的措辭。`repair_condition` / `failure_condition` 描述的觀察點，也應以『明日』或具體交易日為基準。」

放在基礎約束最頂的原因：時間語意是 reasoning / decision_summary / repair / failure 等多個欄位共同的約束，提前放才能影響整段輸出。

### 9.5d 短線回測：移除 20 天 paper 模擬，只保留 realized

`BacktestService::computeSwingMetrics` 原本有兩套指標:**paper(紙上模擬)** 與 **realized(實現績效)**。paper 已**整段移除**,只保留 realized + daily 候選數趨勢。

**Why 移除 paper:** paper 對每個候選模擬「機械持有 20 個交易日、碰 target/stop 才出、否則第 20 天收盤平倉」。但使用者真實操作是 AI 每日複查、動態調停損、提前停利、換倉——**paper 衡量的是一個不會照做的假想抱法**,對操盤決策無幫助;它回答「選股訊號準不準」,不回答「使用者能不能賺」。而且 paper 直接用 `suggested_buy` 當進場價、不檢查隔天是否跳空/漲停買不到,會**高估**;又沒扣手續費税。留著只會用好看的假數字誤導「以為有在對帳」。

**移除範圍:**
- 刪 `computeSwingPaperOutcomes()`、`calcSwingMetricsFromCollection()`。
- `computeSwingMetrics` 只回 `total_candidates` / `ai_selected` / `realized` / `daily` / `period`;移除 paper 頂層指標、`by_strategy`、`by_thesis`(都是 paper 基礎)。
- `StrategyStatsService::computeSwingDimensions` 改為 no-op(原本存的 swing 策略/論點統計是 paper 假數據且無下游讀取)。
- 前端 `SwingStatsView` 移除紙上績效卡、策略分析、論點命中率區塊;實現績效區加「樣本不足」提示。

**realized 是唯一可信的對帳基礎**,但前提是使用者有在系統記錄真實進出。**現況樣本僅約 5 筆,不具統計意義**(勝率 80% 也只是 4 勝 1 負,再一筆就跳動)。

**重建條件:** 待 realized 樣本累積足夠(30+ 筆),再以 **realized(真實平倉)** 重建 `by_strategy` / `by_thesis` 拆解,並加盈虧比、最大回撤、扣交易成本。在那之前,回測的價值跟真實樣本數綁死,不是靠程式精巧——當務之急是累積真實交易,不是加回測功能。

> 每週教訓萃取(`SwingLessonExtractor`)吃真實持倉 + 出場後股價,不依賴回測 paper。(註:swing 的**單日 AI 檢討**已另外移除,見 §9.5e。)

### 9.5e 移除單日 AI 檢討（swing）

`DailyReviewService` 的 swing 單日檢討(`reviewSwing`)已移除。它原本每日 19:30 抓當日候選 + 持倉餵 Opus,要它點評候選品質/持倉管理/組合風險/明日注意,產文字報告。

**Why 移除:** 選股就是 Opus 做的,讓**同一個 Opus 盤後再點評自己剛選的股**——同模型、同框架、更少資訊(只給 TSV)、當天無結果——結構上是**自我背書**(確認偏誤),不是檢討。有意義的複核必須引入**新資訊**(事後真實結果)或**不同視角**(不同模型/對抗質疑),它都沒有。而且兩塊功能都冗餘:檢討候選 = 重疊選股(`SwingScreenerService`);檢討持倉 = 重疊每日 18:50 `update-swing-positions` 的持倉複查(後者帶技術/籌碼/估值/類股/新聞/大盤情境,資訊全得多)。

**移除範圍:** `DailyReviewService::review()` 的 swing 分支 + `reviewSwing` 方法、排程 19:30 `daily-review --mode=swing`、HealthCheck 5c3 短線檢討檢查、前端 `SwingStatsView` 單日檢討區塊、`SwingLessonExtractor` 引用的 swing 檢討上下文(原 `daily_reviews` mode=swing 區塊)。

**保留:** **每週教訓萃取**(`SwingLessonExtractor`)——對照出場後真實 5 日股價 + 決策軌跡、且回流選股 prompt,是真正的學習閉環。`DailyReviewService` 仍服務 intraday/overnight;`DailyReview` 表 / API / 教訓系統保留。

> 與 §9.5d(移除 paper)同一脈絡:清掉「看起來在做事、實際是 AI 自評/冗餘」的功能,只留真正有反饋/驗證的部分。

### 9.6 個股新聞風險訊號（`StockNewsRiskContextService`）

短線（screener + 每日持倉審查）獨立於 §4 的總體 NewsIndex，需要看單檔層級「未來 1-5 個交易日是否有財報／訂單／成本／展望相關利空」。`StockNewsRiskContextService::build($stock, $date, $days=5, $limit=6)` 統一構建：

**撈取邏輯：**
1. **直接命中**（最多 `$limit` 篇）：近 5 日內 `title` / `summary` 命中 `stocks.name`（中文 LIKE）或 `stocks.symbol`（MySQL `REGEXP '(^|[^0-9A-Za-z])symbol(?!年\|月\|日\|點\|萬\|億\|兆\|元)([^0-9A-Za-z]|$)'` — 邊界匹配 + negative lookahead 排除年份/單位，避免 `2330` 誤命中 `23300萬`、`2027` 誤命中「2027 年」）。
2. **同產業負面新聞**（最多 3 篇）：近 5 日 `industry = stocks.industry` 且 `sentiment_label='negative'` 或 `ai_analysis.short_term_risk=true` 或 `risk_type≠none`。
3. **合併排序**：直接命中優先，再按 `published_at` DESC，取前 `$limit` 篇。

**輸出結構：**

```json
{
  "has_short_term_risk": true,
  "risk_type": "margin_pressure",
  "risk_reason": "毛利率連兩季下滑且 Q2 展望保守",
  "articles": [
    {
      "id": 123, "published_at": "05/14 18:30", "title": "...",
      "sentiment_label": "negative",
      "short_term_risk": true, "risk_type": "margin_pressure", "risk_reason": "...",
      "summary": "..."
    }
  ]
}
```

`toPrompt($context)` 將其格式化成行內文字餵 AI prompt。

**注入點：**

| 流程 | 注入位置 | 用途 |
|---|---|---|
| `SwingScreenerService::askAi` | 每檔候選 user message 內 `news_risk=...` | 新增規則第 7 條「單檔新聞風險必須處理」：若 short_term_risk=true 或負面新聞，必須在 `score` / `selected` / `reasoning` / `risk_notes` 反映；不可只因產業論點正面而忽略單檔利空 |
| `SwingPositionUpdateService::askAi` | `# 個股新聞風險` 段 | 「進階仲裁」段強制：若 short_term_risk=true 或負面新聞，必須判斷是 thesis 失效、獲利品質風險，還是短線價格風險；不可只用技術面忽略法說/財報/訂單利空 |
| `SwingController::resolveRiskTags` | 讀 `candidate.swing_entry_plan.news_risk` | candidate 列表加 `danger` 級 tag，risk_type → 中文標籤（`獲利率風險` / `獲利品質` / `展望不確定` / `訂單遞延` / `成本壓力` / `事件風險`），未知 fallback `新聞風險` |
| `InvestmentThesisResearchService::askAi` | 論點研究 prompt 內每則新聞 | 多帶內文摘錄（300 字）與 `短線風險=risk_type：risk_reason`，讓 Opus 寫論點時也看到單檔層級利空 |

`SwingScreenerService` 把 `news_risk` 寫進 `candidates.swing_entry_plan.news_risk`，供前端 tag 與隔日審查共用（`SwingPositionUpdateService` 每日重新呼叫 `build()` 取最新一份，不依賴選股當下凍結的快照）。

#### 9.6.1 訊號預測力校驗（`stock:swing-news-risk-stats`）

news_risk 是新增資料源 + AI prompt 規則，**無法用歷史資料回測**——只能事後校驗。`stock:swing-news-risk-stats [--days=60] [--notify]` 用既有 `candidates.swing_entry_plan.news_risk` + `daily_quotes` 計算紙上 forward 表現：

**樣本選取：** `mode=swing AND ai_selected=true AND trade_date BETWEEN today-days AND today-5`（留 5 日 forward window）。

**分組：**
- **整體**：`no_news_risk` vs `has_news_risk`
- **By risk\_type**：六類 + `unknown`（AI 回未在白名單的值）

**指標：**

| 欄位 | 計算 | 樣本門檻 |
|---|---|---|
| `forward_5d_max%` | (forward 5 日最高 - suggested_buy) / suggested_buy × 100 | 需 ≥5 交易日 |
| `forward_5d_close%` | (forward 第 5 日收盤 - suggested_buy) / suggested_buy × 100 | 需 ≥5 交易日 |
| `forward_20d_max%` | (forward 20 日最高 - suggested_buy) / suggested_buy × 100 | 需 ≥15 交易日 |
| `forward_20d_close%` | (forward 第 N 日收盤 - suggested_buy) / suggested_buy × 100，N=min(20, 可得 bars) | 需 ≥15 交易日 |

**解讀：** 若「無風險組 5d close - 有風險組 5d close」為正，代表 news_risk=true 的候選平均表現較差，訊號**有預測力**；為負或接近 0，代表訊號雜訊大需重新檢討。

**Diagnostic：** 命令會先列「原始候選 N 檔｜帶 news_risk 欄位 M 檔｜完整 5 日 forward K 檔」，協助判斷是「資料未累積」還是「真的沒預測力」。news_risk feature 上線初期所有舊候選欄位皆 NULL，需累積 1-2 個月才可信。

**使用建議：** 不排程，每兩週手動跑一次，連跑 2-3 次都顯示「差距 < 0.5%」則考慮回退到 §4 簡化版（只用 `short_term_risk` bool 旗標、不分類、不上 UI tag）。

### 9.7 物理層事實標籤 + 連續入選 streak

定義於 `SwingScreenerService::buildCandidatePayload()` 與 `priorSelectionStreak()`。

**設計原則：** 物理層只丟事實、不扣分、不設顯示門檻。動能/位置是否過熱由 Opus 整合判斷，避免硬閾值。呼應 §2.2「reasons 為事實標籤」原則；§2.5 當沖複合分數的 4 條硬閾值 penalty 是反例，列為未來重構議題。

#### 4 條動態事實標籤（永遠輸出，無顯示門檻）

| 標籤 | 計算 | 範例 |
|---|---|---|
| `近3日±X%` | `(closes[0] - closes[3]) / closes[3] × 100`，round 至 1 位小數 | `近3日+12%` / `近3日-7%` |
| `連漲/連跌 N 日` | `computePriceStreak(closes)` — 從今日往前比較相鄰收盤，方向相同+1，**平盤即中斷**（streak=0），cap ±20 | `連漲5日` / `連跌3日` / `連漲0日`（首日平盤） |
| `距MA20 ±X%` | `(close - ma20) / ma20 × 100` | `距MA20 +8%` / `距MA20 -4%` |
| `RSI <value>` | 直接 round | `RSI 82` / `RSI 55` |

無論值大小一律輸出，由 Opus 自行判斷意義。塞進 `candidates.reasons` JSON，與原 4 條定性標籤（中期趨勢向上 / 靠近均線支撐 / 法人買超 / 產業論點關聯）並列。

**`pullbackScore` bug 修正：** 原 `abs(close-ma20)/ma20 < 0.05` 不分正負乖離（追高位置也算回檔加分）。修正為 `close <= ma20 * 1.01 && close >= ma20 * 0.95`（價格在 MA20 +1% 噪音容差內到 -5% 之間才算回檔）。

#### 連續入選 streak（容忍斷訊日）

`SwingScreenerService::priorSelectionStreak()` 從今日往前回推：
- 該日有跑 swing screening（`candidates` 表有 `mode=swing` 任一筆）且本檔未入選 → **真正打斷**
- 該日整個 swing 沒跑（API/系統故障、空白） → **跨過不打斷**，繼續回推
- Cap 30 天

**注意 streak 語意：** streak ≠「人類連續看到入選的日數」，而是「未被 AI 主動剔除的累計交易日數」。極端情況（系統一週沒跑）streak 會跨過該段空白繼續累加。

寫入 `candidates.swing_thesis.consecutive_days_selected`：
- AI `selected=true` → `prior + 1`
- AI `selected=false` → `0`

#### Prompt 注入

`SwingScreenerService::askAi()` 每檔多兩段 `| reasons=t1,t2,t3,... | streak=N日 |`（streak 永遠輸出，包含 `streak=0日`，避免欄位有無造成 prompt 結構不一致）。

第 10 條規則為**原則性描述**（不含硬閾值）：「reasons 中的事實標籤反映動能與位置狀態，streak 反映 thesis 連續入選日數。請整合判斷是否過熱、是否仍適合 4 週短線；過熱判斷不給硬閾值，由你綜合考量。」

#### UI 顯示

- `SwingView.vue`：每張候選卡 risk-tag-strip 後加 `入選 N 日` badge（N ≥ 2 才顯示），thesis-line 下加 reasons line（8 條 chip 含原 4 + 新 4 動態，淡灰 dashed border）
- `CandidatesView.vue`：reasons chips 區自動帶出新標籤；streak badge 加 `mode === 'swing'` guard，避免當沖/隔日沖卡片污染

#### cohort 切點警告

`pullbackScore` bug 修正日為分水嶺。修正前的 `trend_pullback` 樣本含追高股污染，與修正後不可直接比較；`compute-strategy-stats`（§1 排程表）、`BacktestService` 等 by_strategy 統計做跨期分析時，請以該日為切點分段或加註說明。

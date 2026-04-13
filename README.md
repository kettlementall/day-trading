# 台股當沖選股助手

AI 驅動的台股當沖全流程系統：選股 → 開盤校準 → 盤中監控 → 動態出場 → 每日覆盤學習。

## 系統架構

- **後端**: Laravel 11 + PHP 8.3
- **前端**: Vue 3 + Vite + Element Plus + ECharts（手機優先 RWD）
- **資料庫**: MySQL 8 + Redis
- **AI**: Claude API — 選股審核（Sonnet）、開盤校準（Sonnet）、盤中滾動（Sonnet）、新聞情緒（Haiku）、每日覆盤（Opus）
- **通知**: Telegram Bot
- **部署**: Docker Compose

## 核心流程

```
06:00  抓取美股指數 + 隔夜新聞
08:00  規則式寬篩（~50 檔）→ AI 審核選出 10-15 檔 + 策略標籤 + 價格覆蓋
09:00  盤中快照開始（動態頻率 1-3 分鐘）
09:05  AI 開盤校準（通過/否決 + 調整目標停損）
09:05+ 規則式監控 + AI 滾動建議（10-20 分鐘）
13:25  強制平倉
15:00  盤後結果回填
15:30  AI 每日檢討 → 萃取教訓 → 注入隔天 AI prompt
```

## 前置需求

- Docker Engine + Docker Compose V2 plugin
- 確認 Docker daemon 正在執行：
  ```bash
  sudo systemctl start docker
  ```
- 確認目前使用者有 Docker 權限（擇一）：
  ```bash
  # 方法 A：每次加 sudo
  sudo docker compose up -d

  # 方法 B：加入 docker 群組（永久，重新登入後生效）
  sudo usermod -aG docker $USER
  newgrp docker
  ```

## 快速啟動

```bash
cp backend/.env.example backend/.env
# 設定 ANTHROPIC_API_KEY, TELEGRAM_BOT_TOKEN
# 可選: ANTHROPIC_SCREENING_MODEL, ANTHROPIC_INTRADAY_MODEL, ANTHROPIC_SENTIMENT_MODEL

docker compose up -d
docker compose exec php composer install
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate --seed

# 匯入休市日（每年執行一次）
docker compose exec php php artisan stock:import-holidays 2026
```

## 存取

- 前端: http://localhost:5173
- 後端 API: http://localhost:8000/api

## 前端頁面

| 路由 | 說明 |
|------|------|
| `/` | 候選標的（美股指數、盤中監控、AI 標籤） |
| `/history` | 歷史紀錄 |
| `/stats` | 績效統計 + 單日 AI 檢討報告 |
| `/news` | 消息面儀表板 |
| `/settings` | 篩選規則 + 公式設定 |
| `/spec` | 系統規格書 |
| `/stock/:id` | 個股 K 線詳情 |

## 手動指令

```bash
# AI 選股（通常由排程自動執行）
docker compose exec php php artisan stock:ai-screen

# 抓取當日行情
docker compose exec php php artisan stock:fetch-daily

# 盤中監控（排程每分鐘觸發）
docker compose exec php php artisan stock:monitor-intraday

# 產出指定日期的 AI 檢討報告
docker compose exec php php artisan stock:daily-review 2026-04-10

# 健康檢查（卡住 monitor 收尾 + 結果補跑）
docker compose exec php php artisan stock:health-check

# 回測指標查看
docker compose exec php php artisan stock:backtest --from=2026-03-01 --to=2026-04-10
```

所有排程已設定 Laravel Scheduler，Docker 啟動後自動執行。

## 移植 / 備份

整個專案移到新機器只需要三樣東西：**git repo + SQL dump + .env**。

### 備份（舊機器）

```bash
# 匯出資料庫（含所有歷史資料）
docker compose exec mysql mysqldump -u root -psecret day_trading | gzip > day_trading_$(date +%Y%m%d).sql.gz

# 傳到新機器
scp day_trading_*.sql.gz user@new-server:~/
```

### 還原（新機器）

```bash
# 1. 拉程式碼
git clone git@github.com:kettlementall/day-trading.git
cd day-trading

# 2. 設定環境變數
cp backend/.env.example backend/.env
# 填入 ANTHROPIC_API_KEY, TELEGRAM_BOT_TOKEN 等

# 3. 啟動容器
docker compose up -d

# 4. 安裝依賴 + 初始化
docker compose exec php composer install
docker compose exec php php artisan key:generate

# 5. 匯入資料庫
gunzip -c ~/day_trading_*.sql.gz | docker compose exec -T mysql mysql -u root -psecret day_trading

# 6. 跑尚未執行的 migration（如有新版）
docker compose exec php php artisan migrate
```

> Redis 快取不需備份，重啟後自動重建。

完整排程、選股規則、價格公式、AI 監控狀態機、消息面修正等系統規格請參考 **[SPEC.md](SPEC.md)**。

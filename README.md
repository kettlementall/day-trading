# 台股當沖選股助手

每日自動篩選可操作的當沖標的，包含建議買入價、目標獲利價、停損價。

## 技術架構

- **後端**: Laravel 11 + PHP 8.3
- **前端**: Vue 3 + Vite + Element Plus + ECharts（手機優先 RWD）
- **資料庫**: MySQL 8 + Redis
- **部署**: Docker Compose

## 快速啟動

```bash
cd day-trading

# 複製環境設定
cp backend/.env.example backend/.env

# 啟動所有服務
docker compose up -d

# 安裝 PHP 依賴
docker compose exec php composer install

# 產生 APP KEY
docker compose exec php php artisan key:generate

# 執行資料庫遷移 + 預設資料
docker compose exec php php artisan migrate --seed
```

## 存取

- 前端: http://localhost:5173
- 後端 API: http://localhost:8000/api

## 每日操作指令

```bash
# 抓取當日行情（收盤後執行）
docker compose exec php php artisan stock:fetch-daily

# 抓取三大法人
docker compose exec php php artisan stock:fetch-institutional

# 抓取融資融券
docker compose exec php php artisan stock:fetch-margin

# 執行選股（產出隔日候選清單）
docker compose exec php php artisan stock:screen-candidates

# 更新盤後結果
docker compose exec php php artisan stock:update-results
```

以上指令已設定 Laravel Scheduler 自動排程，Docker 啟動後會自動執行。

完整排程、選股規則、價格公式、消息面修正等系統規格請參考 **[SPEC.md](SPEC.md)**。

<?php

use App\Services\TelegramService;
use Illuminate\Support\Facades\Schedule;

$scheduleLog = storage_path('logs/schedule.log');

/**
 * 註冊排程任務並自動加上 Telegram 通知
 */
if (!function_exists('scheduledCommand')) {
    function scheduledCommand(string $command, string $label, bool $selfNotify = false): \Illuminate\Console\Scheduling\Event
    {
        $scheduleLog = storage_path('logs/schedule.log');

        $event = Schedule::command($command)
            ->appendOutputTo($scheduleLog)
            ->onFailure(function () use ($label) {
                app(TelegramService::class)->broadcast("❌ *{$label}* 失敗，請檢查 logs", 'system');
            });

        // 若 command 自行發送詳細通知，就不再發 generic 成功通知
        if (!$selfNotify) {
            $event->onSuccess(function () use ($label) {
                app(TelegramService::class)->broadcast("✅ *{$label}* 完成", 'system');
            });
        }

        return $event;
    }
}

// 每日 14:30 收盤後抓取行情資料
scheduledCommand('stock:fetch-daily', '每日行情抓取', selfNotify: true)->dailyAt('14:30');

// 每日 17:30 補抓每日行情：TWSE 收盤行情有時 14:30 尚未發布而撲空。
// 收盤行情是所有下游的地基（結果回填、AI 檢討、短線持倉檢討都依賴 daily_quotes），
// 缺漏時下游會靜默退回前一交易日股價，故補一道趕在晚間短線排程之前。
// updateOrCreate 冪等，14:30 已成功時重跑只是覆寫同值。
scheduledCommand('stock:fetch-daily', '每日行情補抓', selfNotify: true)->dailyAt('17:30');

// 每日 16:30 抓取三大法人（TWSE 通常 16:15~16:30 才上線）
scheduledCommand('stock:fetch-institutional', '三大法人抓取', selfNotify: true)->dailyAt('16:30');

// 每日 18:00 補抓三大法人：16:30 常因 TWSE 尚未發布而撲空，補一道確保晚間
// 短線排程（18:20 論點 / 18:50 持倉檢討 / 19:00 選股）跑之前當天籌碼已就位。
// updateOrCreate 冪等，16:30 已成功時重跑只是覆寫同值。
scheduledCommand('stock:fetch-institutional', '三大法人補抓', selfNotify: true)->dailyAt('18:00');

// 每日 17:00 抓取融資融券
scheduledCommand('stock:fetch-margin', '融資融券抓取', selfNotify: true)->dailyAt('17:00');

// 每日 17:05 抓取除權息預告表（TWT48U）：除權息日當天就從表上消失，故須每日抓取累積成行事曆，
// 供短線持倉檢討與隔日沖 gap 計算在除息日校正股價基準，避免把除權息調整誤判成真實漲跌。
scheduledCommand('stock:fetch-dividends', '除權息行事曆', selfNotify: true)->dailyAt('17:05');

// 每日 08:00 執行 AI 選股（規則式寬篩 + AI 審核）
// 原 stock:screen-candidates 保留可手動執行
scheduledCommand('stock:ai-screen', 'AI 選股審核')->dailyAt('08:00');

// 每日 09:05 & 09:30 抓取盤中即時行情
scheduledCommand('stock:fetch-intraday', '盤中行情(09:05)', selfNotify: true)->dailyAt('09:05');
scheduledCommand('stock:fetch-intraday', '盤中行情(09:30)', selfNotify: true)->dailyAt('09:30');

// 09:35 盤前確認已由 stock:monitor-intraday 的 09:05 AI 開盤校準取代
// 原 stock:screen-morning 指令保留可手動執行
// scheduledCommand('stock:screen-morning', '盤前確認篩選')->dailyAt('09:35');

// 每日 15:00 更新前日候選標的的實際結果
scheduledCommand('stock:update-results', '候選結果回填')->dailyAt('15:00');

// 每日 15:30 自動產出前日 AI 檢討報告（依賴 15:00 結果回填）
scheduledCommand('stock:daily-review', 'AI 每日檢討')->dailyAt('15:30');

// 每日 06:00 抓取隔夜國際新聞 + 美股指數（供 08:00 選股用）
// 06:00 跳過 TX：期交所 API CRef 此時尚未切換到正確「T-1 日盤收」基準，會抓到語意錯誤的 -2.13%
scheduledCommand('stock:fetch-us-indices --no-tx', '美股指數抓取', selfNotify: true)->dailyAt('06:00');
// 每日 14:00 抓台指期「日盤收盤」（symbol=TX_DAY）— 給隔天夜盤算 change 基準用
scheduledCommand('stock:fetch-us-indices --tx-day-close', '台指期日盤收盤', selfNotify: true)
    ->dailyAt('14:00')->weekdays();
// 每日 05:01 抓台指期「夜盤收盤」（symbol=TX_NIGHT，change vs 前一交易日 TX_DAY = 正確的「夜盤漲跌」）— 給 08:30 簡報用
scheduledCommand('stock:fetch-us-indices --tx-night-close', '台指期夜盤收盤', selfNotify: true)
    ->dailyAt('05:01')->weekdays();
// 每日 08:45 更新台指期日盤開盤價（symbol=TX，候選頁即時報價用）
scheduledCommand('stock:fetch-us-indices --tx-only', '台指期日盤更新', selfNotify: true)->dailyAt('08:45');
scheduledCommand('news:fetch', '新聞抓取(06:00)', selfNotify: true)->dailyAt('06:00');
scheduledCommand('news:compute-indices', '新聞指數(06:15)', selfNotify: true)->dailyAt('06:15');

// 每日 08:00 / 12:00 / 18:00 抓取新聞並分析
scheduledCommand('news:fetch', '新聞抓取(08:00)', selfNotify: true)->dailyAt('08:00');
scheduledCommand('news:compute-indices', '新聞指數(08:15)', selfNotify: true)->dailyAt('08:15');

// 每日 08:30 盤前方向簡報（Opus 聚合美股/夜盤/MarketContext/NewsIndex/法人 T-1 → Telegram）
scheduledCommand('stock:premarket-briefing', '盤前方向簡報', selfNotify: true)
    ->dailyAt('08:30')->weekdays();
scheduledCommand('news:fetch', '新聞抓取(12:00)', selfNotify: true)->dailyAt('12:00');
scheduledCommand('news:fetch-mops', '重大訊息抓取(12:05)', selfNotify: true)->dailyAt('12:05');
scheduledCommand('news:compute-indices', '新聞指數(12:15)', selfNotify: true)->dailyAt('12:15');
scheduledCommand('news:fetch', '新聞抓取(18:00)', selfNotify: true)->dailyAt('18:00');
// 18:05 抓盤後重訊 → 18:15 compute-indices 分析情緒 → 18:20 research 論點用上，時序銜接
scheduledCommand('news:fetch-mops', '重大訊息抓取(18:05)', selfNotify: true)->dailyAt('18:05');
scheduledCommand('news:compute-indices', '新聞指數(18:15)', selfNotify: true)->dailyAt('18:15');

// 每週一 17:30 重算短線股票池（流動性、價格、ETF 類型）
scheduledCommand('stock:refresh-swing-universe', '短線股票池重算', selfNotify: true)
    ->weeklyOn(1, '17:30');

// 短線配置：盤後研究論點 → 更新既有持倉 → 產生新候選
scheduledCommand('stock:research-investment-theses', 'AI 產業論點研究', selfNotify: true)
    ->dailyAt('18:20');
scheduledCommand('stock:update-swing-positions', '短線持倉更新', selfNotify: true)
    ->dailyAt('18:50')->weekdays();
scheduledCommand('stock:ai-screen-swing', '短線 AI 選股', selfNotify: true)
    ->dailyAt('19:00');

// 腿 2：盤中動態加入（4+1 軸聯集 → Fugle 即時報價 → 4 條規則 → Haiku 快評 → 寫入 candidates）
// 09:35 觸發 = 09:30 5 分 K 收後 5 分鐘，足夠抓老師 09:37 報的明牌
scheduledCommand('stock:scan-intraday-movers', '盤中加入(09:35)', selfNotify: true)
    ->dailyAt('09:35')->weekdays();

// 盤中即時監控：command 內部每 30 秒 loop，scheduler 每分鐘觸發作為當機重啟保底
// withoutOverlapping(60)：若 process 存活中，新觸發直接跳過；異常中斷後最多 60 分鐘內重啟
// runInBackground：避免長時間 loop 阻塞 scheduler，導致同分鐘的其他排程（如隔日沖出場監控）被卡住
Schedule::command('stock:monitor-intraday')
    ->everyMinute()
    ->between('9:00', '13:30')
    ->weekdays()
    ->withoutOverlapping(60)
    ->runInBackground()
    ->appendOutputTo($scheduleLog);

// ---- 隔日沖選股流程（每個交易日執行）----
// 收盤後抓取 TWSE 類股指數
// 改用帶日期端點為主、OpenAPI 為 fallback；15:30 給 TWSE 留發佈時間
// 14:45 OpenAPI 經常仍回 T-1，導致 swing 18:50 持倉檢討看到的「今日類股」其實是昨日 → 嚴重誤導 AI
scheduledCommand('stock:fetch-sector-indices', '類股指數抓取', selfNotify: true)
    ->dailyAt('15:30')->weekdays();

// 12:50 隔日沖 AI 選股（Screener → Haiku → Opus → Final Ranking，完成後可於 13:00-13:25 下單）
scheduledCommand('stock:ai-screen-overnight', '隔日沖 AI 選股')
    ->dailyAt('12:50')->weekdays();

// T+1 盤中出場監控：09:00-09:30 每 5 分鐘（開盤最關鍵） + 09:30-13:15 每 15 分鐘 + 13:25 強制平倉
// 含 Fugle 報價抓取 + 目標/停損到價檢查 + AI 滾動調整（不再依賴 monitor-intraday）
foreach ([
    '905', '910', '915', '920', '925',
    '930', '945', '1000', '1015', '1030', '1045',
    '1100', '1115', '1130', '1145',
    '1200', '1215', '1230',
    '1245', '1300', '1315', '1325',
] as $slot) {
    $h = intdiv((int) $slot, 100);
    $m = (int) $slot % 100;
    $time = sprintf('%02d:%02d', $h, $m);
    scheduledCommand("stock:monitor-overnight-exit --slot={$slot}", "隔日沖出場監控 {$time}")
        ->dailyAt($time)->weekdays();
}

// 17:15 抓取 TWSE 本益比/殖利率/股價淨值比（TWSE 每日收盤後更新）
scheduledCommand('stock:fetch-valuations', 'TWSE 估值資料抓取', selfNotify: true)
    ->dailyAt('17:15')->weekdays();

// 15:05 隔日沖結果回填（T+1 收盤後記錄實際開高低收 + 跳空數據）
scheduledCommand('stock:update-overnight-results', '隔日沖結果回填')
    ->dailyAt('15:05')->weekdays();

// 15:35 隔日沖 AI 檢討報告（依賴 15:05 結果回填）
scheduledCommand('stock:daily-review --mode=overnight', '隔日沖 AI 檢討')
    ->dailyAt('15:35')->weekdays();

// 每週五 16:00 從整週檢討報告萃取通用教訓（依賴 15:30/15:35 檢討完成）
scheduledCommand('stock:extract-weekly-lessons', '週教訓萃取')
    ->weeklyOn(5, '16:00');

// 每週日 17:00 從整週短線平倉持倉 + AI 軌跡 + 出場後 5 日股價萃取教訓
// 排在週日確保前一交易週已完整收盤、5 日 forward window 有資料
scheduledCommand('stock:extract-swing-lessons', '短線教訓萃取')
    ->weeklyOn(0, '17:00');

// 每週日 22:00 計算策略量化績效統計（30/60 天窗口）
scheduledCommand('stock:compute-strategy-stats', '策略績效統計')
    ->weeklyOn(0, '22:00');

// 每日 22:00 健康檢查（健康檢查自己會發通知，不重複）
// 含：卡住 monitor 強制收尾 + 候選結果未回填重跑
Schedule::command('stock:health-check')->dailyAt('22:00')->appendOutputTo($scheduleLog);

// 每週日 03:00 清理過期資料（快照保留 30 天、AI 教訓過期刪除）
Schedule::command('stock:cleanup')->weeklyOn(0, '03:00')->appendOutputTo($scheduleLog);

// 每週一 06:00 從 TWSE/TPEX 補上 stocks.industry（供類股強弱、新聞題材配對使用，產業分類極少變動）
scheduledCommand('stock:fill-industry', '產業別填補', selfNotify: true)
    ->weeklyOn(1, '06:00');

// 回測優化已停用（AI 覆蓋價格後，調整規則式公式參數意義不大）
// 指令 stock:backtest --validated 保留可手動執行
// scheduledCommand('stock:backtest --validated', '週回測優化')->weeklyOn(1, '07:00');

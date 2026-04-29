<?php

use App\Services\TelegramService;
use Illuminate\Support\Facades\Schedule;

$scheduleLog = storage_path('logs/schedule.log');

/**
 * 註冊排程任務並自動加上 Telegram 通知
 */
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

// 每日 14:30 收盤後抓取行情資料
scheduledCommand('stock:fetch-daily', '每日行情抓取', selfNotify: true)->dailyAt('14:30');

// 每日 16:30 抓取三大法人（TWSE 通常 16:15~16:30 才上線）
scheduledCommand('stock:fetch-institutional', '三大法人抓取', selfNotify: true)->dailyAt('16:30');

// 每日 17:00 抓取融資融券
scheduledCommand('stock:fetch-margin', '融資融券抓取', selfNotify: true)->dailyAt('17:00');

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
scheduledCommand('stock:fetch-us-indices', '美股指數抓取', selfNotify: true)->dailyAt('06:00');
// 每日 08:45 更新台指期日盤開盤價（日盤 08:45 開盤，供候選頁顯示用）
scheduledCommand('stock:fetch-us-indices --tx-only', '台指期日盤更新', selfNotify: true)->dailyAt('08:45');
scheduledCommand('news:fetch', '新聞抓取(06:00)', selfNotify: true)->dailyAt('06:00');
scheduledCommand('news:compute-indices', '新聞指數(06:15)', selfNotify: true)->dailyAt('06:15');

// 每日 08:00 / 12:00 / 18:00 抓取新聞並分析
scheduledCommand('news:fetch', '新聞抓取(08:00)', selfNotify: true)->dailyAt('08:00');
scheduledCommand('news:compute-indices', '新聞指數(08:15)', selfNotify: true)->dailyAt('08:15');
scheduledCommand('news:fetch', '新聞抓取(12:00)', selfNotify: true)->dailyAt('12:00');
scheduledCommand('news:compute-indices', '新聞指數(12:15)', selfNotify: true)->dailyAt('12:15');
scheduledCommand('news:fetch', '新聞抓取(18:00)', selfNotify: true)->dailyAt('18:00');
scheduledCommand('news:compute-indices', '新聞指數(18:15)', selfNotify: true)->dailyAt('18:15');

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
// 12:45 抓取類股指數（供 12:50 Haiku/Opus 選股使用）
scheduledCommand('stock:fetch-sector-indices', '類股指數抓取', selfNotify: true)
    ->dailyAt('12:45')->weekdays();

// 12:50 隔日沖 AI 選股（Screener → Haiku → Opus，完成後可於 13:00-13:25 下單）
scheduledCommand('stock:ai-screen-overnight', '隔日沖 AI 選股')
    ->dailyAt('12:50')->weekdays();

// T+1 盤中出場監控：09:00-09:30 每 5 分鐘（開盤最關鍵） + 09:30-13:15 每 15 分鐘
// 含 Fugle 報價抓取 + 目標/停損到價檢查 + AI 滾動調整（不再依賴 monitor-intraday）
foreach ([
    '905', '910', '915', '920', '925',
    '930', '945', '1000', '1015', '1030', '1045',
    '1100', '1115', '1130', '1145',
    '1200', '1215', '1230',
    '1245', '1300', '1315',
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

<?php

use App\Services\TelegramService;
use Illuminate\Support\Facades\Schedule;

$scheduleLog = storage_path('logs/schedule.log');

/**
 * 註冊排程任務並自動加上 Telegram 通知
 */
function scheduledCommand(string $command, string $label): \Illuminate\Console\Scheduling\Event
{
    $scheduleLog = storage_path('logs/schedule.log');

    return Schedule::command($command)
        ->appendOutputTo($scheduleLog)
        ->onSuccess(function () use ($label) {
            app(TelegramService::class)->send("✅ *{$label}* 完成");
        })
        ->onFailure(function () use ($label) {
            app(TelegramService::class)->send("❌ *{$label}* 失敗，請檢查 logs");
        });
}

// 每日 14:30 收盤後抓取行情資料
scheduledCommand('stock:fetch-daily', '每日行情抓取')->dailyAt('14:30');

// 每日 16:00 抓取三大法人
scheduledCommand('stock:fetch-institutional', '三大法人抓取')->dailyAt('16:00');

// 每日 16:30 抓取融資融券
scheduledCommand('stock:fetch-margin', '融資融券抓取')->dailyAt('16:30');

// 每日 08:00 執行 AI 選股（規則式寬篩 + AI 審核）
// 原 stock:screen-candidates 保留可手動執行
scheduledCommand('stock:ai-screen', 'AI 選股審核')->dailyAt('08:00');

// 每日 09:05 & 09:30 抓取盤中即時行情
scheduledCommand('stock:fetch-intraday', '盤中行情(09:05)')->dailyAt('09:05');
scheduledCommand('stock:fetch-intraday', '盤中行情(09:30)')->dailyAt('09:30');

// 09:35 盤前確認已由 stock:monitor-intraday 的 09:05 AI 開盤校準取代
// 原 stock:screen-morning 指令保留可手動執行
// scheduledCommand('stock:screen-morning', '盤前確認篩選')->dailyAt('09:35');

// 每日 15:00 更新前日候選標的的實際結果
scheduledCommand('stock:update-results', '候選結果回填')->dailyAt('15:00');

// 每日 15:30 自動產出前日 AI 檢討報告（依賴 15:00 結果回填）
// 改為手動觸發，排程已停用
// scheduledCommand('stock:daily-review', 'AI 每日檢討')->dailyAt('15:30');

// 每日 06:00 抓取隔夜國際新聞 + 美股指數（供 08:00 選股用）
scheduledCommand('stock:fetch-us-indices', '美股指數抓取')->dailyAt('06:00');
// 每日 08:45 更新台指期日盤開盤價（日盤 08:45 開盤，供候選頁顯示用）
scheduledCommand('stock:fetch-us-indices --tx-only', '台指期日盤更新')->dailyAt('08:45');
scheduledCommand('news:fetch', '新聞抓取(06:00)')->dailyAt('06:00');
scheduledCommand('news:compute-indices', '新聞指數(06:15)')->dailyAt('06:15');

// 每日 08:00 / 12:00 / 18:00 抓取新聞並分析
scheduledCommand('news:fetch', '新聞抓取(08:00)')->dailyAt('08:00');
scheduledCommand('news:compute-indices', '新聞指數(08:15)')->dailyAt('08:15');
scheduledCommand('news:fetch', '新聞抓取(12:00)')->dailyAt('12:00');
scheduledCommand('news:compute-indices', '新聞指數(12:15)')->dailyAt('12:15');
scheduledCommand('news:fetch', '新聞抓取(18:00)')->dailyAt('18:00');
scheduledCommand('news:compute-indices', '新聞指數(18:15)')->dailyAt('18:15');

// 盤中即時監控：command 內部每 30 秒 loop，scheduler 每分鐘觸發作為當機重啟保底
// withoutOverlapping(60)：若 process 存活中，新觸發直接跳過；異常中斷後最多 60 分鐘內重啟
Schedule::command('stock:monitor-intraday')
    ->everyMinute()
    ->between('9:00', '13:30')
    ->weekdays()
    ->withoutOverlapping(60)
    ->appendOutputTo($scheduleLog);

// ---- 隔日沖選股流程（每個交易日執行）----
// 12:45 抓取類股指數（供 12:50 Haiku/Opus 選股使用）
scheduledCommand('stock:fetch-sector-indices', '類股指數抓取')
    ->dailyAt('12:45')->weekdays();

// 12:50 隔日沖 AI 選股（Screener → Haiku → Opus，完成後可於 13:00-13:25 下單）
scheduledCommand('stock:ai-screen-overnight', '隔日沖 AI 選股')
    ->dailyAt('12:50')->weekdays();

// T+1 盤中出場監控（9:30~12:30 每 15 分鐘）：檢查目標/停損觸發 + Haiku 滾動調整
foreach ([
    '930', '945', '1000', '1015', '1030', '1045',
    '1100', '1115', '1130', '1145',
    '1200', '1215', '1230',
] as $slot) {
    $h = intdiv((int) $slot, 100);
    $m = (int) $slot % 100;
    $time = sprintf('%02d:%02d', $h, $m);
    scheduledCommand("stock:monitor-overnight-exit --slot={$slot}", "隔日沖出場監控 {$time}")
        ->dailyAt($time)->weekdays();
}

// 17:00 抓取 TWSE 本益比/殖利率/股價淨值比（TWSE 每日收盤後更新）
scheduledCommand('stock:fetch-valuations', 'TWSE 估值資料抓取')
    ->dailyAt('17:00')->weekdays();

// 15:05 隔日沖結果回填（T+1 收盤後記錄實際開高低收 + 跳空數據）
scheduledCommand('stock:update-overnight-results', '隔日沖結果回填')
    ->dailyAt('15:05')->weekdays();

// 15:35 隔日沖 AI 檢討報告（依賴 15:05 結果回填）
// 改為手動觸發，排程已停用
// scheduledCommand('stock:daily-review --mode=overnight', '隔日沖 AI 檢討')
//     ->dailyAt('15:35')->weekdays();

// 每週日 22:00 計算策略量化績效統計（30/60 天窗口）
scheduledCommand('stock:compute-strategy-stats', '策略績效統計')
    ->weeklyOn(0, '22:00');

// 每日 22:00 健康檢查（健康檢查自己會發通知，不重複）
// 含：卡住 monitor 強制收尾 + 候選結果未回填重跑
Schedule::command('stock:health-check')->dailyAt('22:00')->appendOutputTo($scheduleLog);

// 每週日 03:00 清理過期資料（快照保留 30 天、AI 教訓過期刪除）
Schedule::command('stock:cleanup')->weeklyOn(0, '03:00')->appendOutputTo($scheduleLog);

// 回測優化已停用（AI 覆蓋價格後，調整規則式公式參數意義不大）
// 指令 stock:backtest --validated 保留可手動執行
// scheduledCommand('stock:backtest --validated', '週回測優化')->weeklyOn(1, '07:00');

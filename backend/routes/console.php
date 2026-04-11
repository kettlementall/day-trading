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

// 每日 06:00 抓取隔夜國際新聞 + 美股指數（供 08:00 選股用）
scheduledCommand('stock:fetch-us-indices', '美股指數抓取')->dailyAt('06:00');
scheduledCommand('news:fetch', '新聞抓取(06:00)')->dailyAt('06:00');
scheduledCommand('news:compute-indices', '新聞指數(06:15)')->dailyAt('06:15');

// 每日 08:00 / 12:00 / 18:00 抓取新聞並分析
scheduledCommand('news:fetch', '新聞抓取(08:00)')->dailyAt('08:00');
scheduledCommand('news:compute-indices', '新聞指數(08:15)')->dailyAt('08:15');
scheduledCommand('news:fetch', '新聞抓取(12:00)')->dailyAt('12:00');
scheduledCommand('news:compute-indices', '新聞指數(12:15)')->dailyAt('12:15');
scheduledCommand('news:fetch', '新聞抓取(18:00)')->dailyAt('18:00');
scheduledCommand('news:compute-indices', '新聞指數(18:15)')->dailyAt('18:15');

// 盤中即時監控：每 1 分鐘觸發，指令內部依時段控制實際頻率
// 09:00-09:30 每 1 分 / 09:30-10:30 每 2 分 / 10:30-13:00 每 3 分 / 13:00-13:30 每 1 分
Schedule::command('stock:monitor-intraday')
    ->everyMinute()
    ->between('9:00', '13:30')
    ->weekdays()
    ->appendOutputTo($scheduleLog);

// 每日 22:00 健康檢查（健康檢查自己會發通知，不重複）
// 含：卡住 monitor 強制收尾 + 候選結果未回填重跑
Schedule::command('stock:health-check')->dailyAt('22:00')->appendOutputTo($scheduleLog);

// 每週日 03:00 清理過期資料（快照保留 30 天、AI 教訓過期刪除）
Schedule::command('stock:cleanup')->weeklyOn(0, '03:00')->appendOutputTo($scheduleLog);

// 每週一 07:00 自動執行帶驗證的回測優化（過去60天，最多10次嘗試）
scheduledCommand('stock:backtest --validated', '週回測優化')->weeklyOn(1, '07:00');

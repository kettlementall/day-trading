<?php

use Illuminate\Support\Facades\Schedule;

$scheduleLog = storage_path('logs/schedule.log');

// 每日 14:30 收盤後抓取行情資料
Schedule::command('stock:fetch-daily')->dailyAt('14:30')->appendOutputTo($scheduleLog);

// 每日 16:00 抓取三大法人
Schedule::command('stock:fetch-institutional')->dailyAt('16:00')->appendOutputTo($scheduleLog);

// 每日 16:30 抓取融資融券
Schedule::command('stock:fetch-margin')->dailyAt('16:30')->appendOutputTo($scheduleLog);

// 每日 08:00 執行選股篩選（等 06:00 隔夜新聞抓完後，含消息面修正）
Schedule::command('stock:screen-candidates')->dailyAt('08:00')->appendOutputTo($scheduleLog);

// 每日 09:05 & 09:30 抓取盤中即時行情（第一次抓5分K，第二次抓30分鐘後狀態）
Schedule::command('stock:fetch-intraday')->dailyAt('09:05')->appendOutputTo($scheduleLog);
Schedule::command('stock:fetch-intraday')->dailyAt('09:30')->appendOutputTo($scheduleLog);

// 每日 09:35 執行盤前確認篩選
Schedule::command('stock:screen-morning')->dailyAt('09:35')->appendOutputTo($scheduleLog);

// 每日 15:00 更新前日候選標的的實際結果
Schedule::command('stock:update-results')->dailyAt('15:00')->appendOutputTo($scheduleLog);

// 每日 06:00 抓取隔夜國際新聞（供 08:00 選股用）
Schedule::command('news:fetch')->dailyAt('06:00')->appendOutputTo($scheduleLog);
Schedule::command('news:compute-indices')->dailyAt('06:15')->appendOutputTo($scheduleLog);

// 每日 08:00 / 12:00 / 18:00 抓取新聞並分析
Schedule::command('news:fetch')->dailyAt('08:00')->appendOutputTo($scheduleLog);
Schedule::command('news:compute-indices')->dailyAt('08:15')->appendOutputTo($scheduleLog);
Schedule::command('news:fetch')->dailyAt('12:00')->appendOutputTo($scheduleLog);
Schedule::command('news:compute-indices')->dailyAt('12:15')->appendOutputTo($scheduleLog);
Schedule::command('news:fetch')->dailyAt('18:00')->appendOutputTo($scheduleLog);
Schedule::command('news:compute-indices')->dailyAt('18:15')->appendOutputTo($scheduleLog);

// 每日 22:00 健康檢查（確認當日資料抓取正常）
Schedule::command('stock:health-check')->dailyAt('22:00')->appendOutputTo($scheduleLog);

// 每週一 07:00 自動執行回測分析並套用建議（過去30天）
Schedule::command('stock:backtest --optimize --apply')->weeklyOn(1, '07:00')->appendOutputTo($scheduleLog);

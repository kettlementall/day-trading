<?php

namespace App\Console\Commands;

use App\Models\Candidate;
use App\Models\CandidateMonitor;
use App\Models\DailyQuote;
use App\Models\DailyReview;
use App\Models\InstitutionalTrade;
use App\Models\MarketHoliday;
use App\Models\UsMarketIndex;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HealthCheck extends Command
{
    protected $signature = 'stock:health-check {date?}';
    protected $description = '檢查每日資料抓取是否正常，產生報告並於異常時發送 Telegram 通知';

    public function handle(): int
    {
        $date = $this->argument('date') ?? now()->format('Y-m-d');
        $checks = [];
        $isHoliday = MarketHoliday::isHoliday($date);

        if ($isHoliday) {
            $checks[] = ['name' => '休市日', 'status' => 'info', 'detail' => '今日休市，市場資料檢查跳過'];
        }

        // 1. 每日行情（休市日跳過）
        if (!$isHoliday) {
            $quoteCount = DailyQuote::where('date', $date)->count();
            if ($quoteCount === 0) {
                $checks[] = ['name' => '每日行情', 'status' => 'error', 'detail' => "0 筆（預期 > 800）"];
            } elseif ($quoteCount < 800) {
                $checks[] = ['name' => '每日行情', 'status' => 'warn', 'detail' => "{$quoteCount} 筆（預期 > 800）"];
            } else {
                $checks[] = ['name' => '每日行情', 'status' => 'ok', 'detail' => "{$quoteCount} 筆"];
            }
        }

        // 2. 三大法人（休市日跳過）
        if (!$isHoliday) {
            $instCount = InstitutionalTrade::where('date', $date)->count();
            if ($instCount === 0) {
                $checks[] = ['name' => '三大法人', 'status' => 'error', 'detail' => "0 筆（預期 > 800）"];
            } elseif ($instCount < 800) {
                $checks[] = ['name' => '三大法人', 'status' => 'warn', 'detail' => "{$instCount} 筆（預期 > 800）"];
            } else {
                $checks[] = ['name' => '三大法人', 'status' => 'ok', 'detail' => "{$instCount} 筆"];
            }
        }

        // 3. 價格合理性（休市日跳過）
        if (!$isHoliday) {
            $badPrices = DailyQuote::where('date', $date)
                ->where(function ($q) {
                    $q->where('close', '<=', 0)
                        ->orWhere('open', '<=', 0)
                        ->orWhereColumn('high', '<', 'low');
                })
                ->count();
            if ($badPrices > 0) {
                $checks[] = ['name' => '價格合理性', 'status' => 'error', 'detail' => "{$badPrices} 筆異常（收盤<=0 或 高<低）"];
            } else {
                $checks[] = ['name' => '價格合理性', 'status' => 'ok', 'detail' => '通過'];
            }
        }

        // 4. 候選標的
        $nextTradeDate = now()->addWeekday()->format('Y-m-d');
        $candidateCount = Candidate::where('trade_date', $nextTradeDate)->count();
        $todayCandidateCount = Candidate::where('trade_date', $date)->count();
        $resultCount = Candidate::where('trade_date', $date)->whereHas('result')->count();
        if ($todayCandidateCount > 0) {
            $checks[] = ['name' => '今日候選結果', 'status' => $resultCount > 0 ? 'ok' : 'warn',
                'detail' => "{$todayCandidateCount} 檔，已回填結果 {$resultCount} 筆"];
        }
        if ($candidateCount > 0) {
            $checks[] = ['name' => '明日候選標的', 'status' => 'ok', 'detail' => "{$candidateCount} 檔（{$nextTradeDate}）"];
        } else {
            $checks[] = ['name' => '明日候選標的', 'status' => 'info', 'detail' => "尚未產出（{$nextTradeDate}）"];
        }

        // 5. 卡住的 monitors 強制收尾
        if (!$isHoliday) {
            $stuckMonitors = CandidateMonitor::whereIn('status', [
                    CandidateMonitor::STATUS_WATCHING,
                    CandidateMonitor::STATUS_ENTRY_SIGNAL,
                    CandidateMonitor::STATUS_HOLDING,
                ])
                ->whereHas('candidate', fn($q) => $q->where('trade_date', $date))
                ->get();

            if ($stuckMonitors->isNotEmpty()) {
                foreach ($stuckMonitors as $m) {
                    $oldStatus = $m->status;
                    $stateLog = $m->state_log ?? [];
                    $stateLog[] = [
                        'from' => $oldStatus,
                        'to' => CandidateMonitor::STATUS_CLOSED,
                        'reason' => '健康檢查強制收尾（排程漏跑補償）',
                        'at' => now()->toDateTimeString(),
                    ];
                    $m->update([
                        'status' => CandidateMonitor::STATUS_CLOSED,
                        'exit_time' => now(),
                        'skip_reason' => '健康檢查強制收尾',
                        'state_log' => $stateLog,
                    ]);
                }
                $checks[] = ['name' => '卡住的監控', 'status' => 'warn',
                    'detail' => "已強制關閉 {$stuckMonitors->count()} 筆卡住的 monitor"];
            }
        }

        // 5b. 候選結果未回填 → 重跑
        if (!$isHoliday && $todayCandidateCount > 0 && $resultCount === 0 && $quoteCount > 0) {
            Artisan::call('stock:update-results', ['date' => $date]);
            $retryCount = Candidate::where('trade_date', $date)->whereHas('result')->count();
            $checks[] = ['name' => '結果補回填', 'status' => $retryCount > 0 ? 'ok' : 'warn',
                'detail' => "重跑 update-results，回填 {$retryCount} 筆"];
        }

        // 5c. AI 檢討報告未產出 → 補跑（檢查前一交易日）
        if (!$isHoliday) {
            $hasReview = DailyReview::where('trade_date', $date)->exists();
            if (!$hasReview && $todayCandidateCount > 0 && $resultCount > 0) {
                Artisan::call('stock:daily-review', ['date' => $date]);
                $hasReview = DailyReview::where('trade_date', $date)->exists();
                $checks[] = ['name' => 'AI 檢討報告', 'status' => $hasReview ? 'ok' : 'warn',
                    'detail' => $hasReview ? '補跑成功' : '補跑失敗'];
            } elseif ($hasReview) {
                $checks[] = ['name' => 'AI 檢討報告', 'status' => 'ok', 'detail' => '已產出'];
            }
        }

        // 5d. 美股指數檢查
        $usIndexCount = UsMarketIndex::where('date', $date)->count();
        if ($usIndexCount === 0) {
            // 美股也有休市日，用 warn 而非 error
            $checks[] = ['name' => '美股指數', 'status' => 'warn', 'detail' => '今日無資料（可能美股休市）'];
        } else {
            $checks[] = ['name' => '美股指數', 'status' => 'ok', 'detail' => "{$usIndexCount} 筆"];
        }

        // 6. TWSE API
        try {
            $response = Http::timeout(10)
                ->get('https://www.twse.com.tw/exchangeReport/MI_INDEX', [
                    'response' => 'json',
                    'date' => now()->format('Ymd'),
                    'type' => 'ALLBUT0999',
                ]);
            $json = $response->json();
            if (isset($json['stat'])) {
                $checks[] = ['name' => 'TWSE API', 'status' => 'ok', 'detail' => "可連線 (stat={$json['stat']})"];
            } else {
                $checks[] = ['name' => 'TWSE API', 'status' => 'warn', 'detail' => '回傳格式異常（無 stat 欄位）'];
            }
        } catch (\Exception $e) {
            $checks[] = ['name' => 'TWSE API', 'status' => 'error', 'detail' => '無法連線: ' . $e->getMessage()];
        }

        // 6. Anthropic API (AI)
        $apiKey = config('services.anthropic.api_key', '');
        if (!$apiKey) {
            $checks[] = ['name' => 'AI (Anthropic)', 'status' => 'error', 'detail' => 'API KEY 未設定'];
        } else {
            try {
                $response = Http::timeout(15)
                    ->withHeaders([
                        'x-api-key' => $apiKey,
                        'anthropic-version' => '2023-06-01',
                        'content-type' => 'application/json',
                    ])
                    ->post('https://api.anthropic.com/v1/messages', [
                        'model' => config('services.anthropic.model', 'claude-haiku-4-5-20251001'),
                        'max_tokens' => 10,
                        'messages' => [
                            ['role' => 'user', 'content' => 'ping'],
                        ],
                    ]);

                if ($response->successful()) {
                    $checks[] = ['name' => 'AI (Anthropic)', 'status' => 'ok', 'detail' => '可連線'];
                } else {
                    $code = $response->status();
                    $error = $response->json('error.message', $response->body());
                    $checks[] = ['name' => 'AI (Anthropic)', 'status' => 'error', 'detail' => "HTTP {$code}: {$error}"];
                }
            } catch (\Exception $e) {
                $checks[] = ['name' => 'AI (Anthropic)', 'status' => 'error', 'detail' => '連線失敗: ' . $e->getMessage()];
            }
        }

        // 7. Telegram
        $telegramToken = config('services.telegram.bot_token', '');
        $telegramChatId = config('services.telegram.chat_id', '');
        if (!$telegramToken || !$telegramChatId) {
            $checks[] = ['name' => 'Telegram', 'status' => 'warn', 'detail' => 'BOT_TOKEN 或 CHAT_ID 未設定'];
        } else {
            try {
                $response = Http::timeout(10)
                    ->get("https://api.telegram.org/bot{$telegramToken}/getMe");
                if ($response->successful()) {
                    $checks[] = ['name' => 'Telegram', 'status' => 'ok', 'detail' => '可連線'];
                } else {
                    $checks[] = ['name' => 'Telegram', 'status' => 'error', 'detail' => 'Bot Token 無效'];
                }
            } catch (\Exception $e) {
                $checks[] = ['name' => 'Telegram', 'status' => 'error', 'detail' => '連線失敗: ' . $e->getMessage()];
            }
        }

        // === 產生報告 ===
        $issues = collect($checks)->whereIn('status', ['error', 'warn']);
        $hasIssues = $issues->isNotEmpty();

        // Console 輸出
        foreach ($checks as $check) {
            $icon = match ($check['status']) {
                'ok' => '✓',
                'warn' => '⚠',
                'error' => '✗',
                default => '·',
            };
            $line = "  {$icon} {$check['name']}: {$check['detail']}";

            match ($check['status']) {
                'error' => $this->error($line),
                'warn' => $this->warn($line),
                default => $this->info($line),
            };
        }

        // Log
        $this->newLine();
        if ($hasIssues) {
            $this->error("=== 發現 {$issues->count()} 個問題 ===");
            foreach ($issues as $issue) {
                Log::warning("[HealthCheck] {$date}: {$issue['name']} - {$issue['detail']}");
            }
        } else {
            $this->info("=== 健康檢查全數通過 ===");
        }

        // 儲存報告
        $report = $this->buildReport($date, $checks, $hasIssues);
        $reportPath = storage_path("logs/health-check-{$date}.log");
        file_put_contents($reportPath, $report);
        $this->info("報告已儲存: {$reportPath}");

        // 有異常時發送 Telegram 通知
        if ($hasIssues) {
            $telegram = new TelegramService();
            $message = $this->buildTelegramMessage($date, $checks);
            if ($telegram->send($message)) {
                $this->info('已發送 Telegram 通知');
            } else {
                $this->warn('Telegram 通知發送失敗');
            }
        }

        return $hasIssues ? self::FAILURE : self::SUCCESS;
    }

    private function buildReport(string $date, array $checks, bool $hasIssues): string
    {
        $lines = [];
        $lines[] = "========================================";
        $lines[] = " 每日健康檢查報告 — {$date}";
        $lines[] = " 執行時間: " . now()->format('Y-m-d H:i:s');
        $lines[] = "========================================";
        $lines[] = "";

        foreach ($checks as $check) {
            $icon = match ($check['status']) {
                'ok' => '[OK]   ',
                'warn' => '[WARN] ',
                'error' => '[ERROR]',
                default => '[INFO] ',
            };
            $lines[] = "{$icon} {$check['name']}: {$check['detail']}";
        }

        $lines[] = "";
        $lines[] = $hasIssues
            ? "結果: 發現 " . collect($checks)->whereIn('status', ['error', 'warn'])->count() . " 個問題"
            : "結果: 全數通過";
        $lines[] = "========================================";

        return implode("\n", $lines) . "\n";
    }

    private function buildTelegramMessage(string $date, array $checks): string
    {
        $lines = [];
        $lines[] = "⚠️ *健康檢查異常報告*";
        $lines[] = "日期: `{$date}`";
        $lines[] = "";

        foreach ($checks as $check) {
            $icon = match ($check['status']) {
                'ok' => '✅',
                'warn' => '⚠️',
                'error' => '❌',
                default => 'ℹ️',
            };
            $lines[] = "{$icon} *{$check['name']}*: {$check['detail']}";
        }

        return implode("\n", $lines);
    }
}

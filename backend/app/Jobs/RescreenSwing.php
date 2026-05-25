<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * admin 從前端手動觸發短線 AI 選股(stock:ai-screen-swing)。
 *
 * 走背景 job 是因為 Opus 選股要 1-3 分鐘,同步 HTTP 會逾時。
 * 兼補 AiScreenSwingCandidates 沒有 try/catch 的洞:Opus 重試耗盡 throw 時
 * 不再靜默 crash,而是寫 cache 讓前端顯示明確失敗訊息。
 */
class RescreenSwing implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public string $date,
    ) {}

    public function handle(): void
    {
        $cacheKey = "swing_rescreen_status:{$this->date}";

        Cache::put($cacheKey, ['status' => 'running', 'progress' => 'AI 選股中（約 1-3 分鐘）...'], 600);

        try {
            Artisan::call('stock:ai-screen-swing', ['date' => $this->date]);
            Cache::put($cacheKey, [
                'status' => 'done',
                'success' => true,
                'message' => '選股完成',
            ], 600);
        } catch (\Throwable $e) {
            Log::error("Swing rescreen failed ({$this->date}): " . $e->getMessage());
            Cache::put($cacheKey, [
                'status' => 'done',
                'success' => false,
                'message' => 'AI 選股失敗：Opus 回覆不合格或逾時，請稍後再試',
            ], 600);
        }
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_monitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->unique()->constrained()->cascadeOnDelete();

            // 狀態機
            $table->string('status', 30)->default('pending');
            // pending → watching → entry_signal → holding → target_hit/stop_hit/trailing_stop/closed
            // pending → skipped / watching → skipped

            // 進場
            $table->decimal('entry_price', 10, 2)->nullable();
            $table->dateTime('entry_time')->nullable();

            // 出場
            $table->decimal('exit_price', 10, 2)->nullable();
            $table->dateTime('exit_time')->nullable();

            // 動態目標/停損（AI 可調整）
            $table->decimal('current_target', 10, 2)->nullable();
            $table->decimal('current_stop', 10, 2)->nullable();

            // AI 校準結果
            $table->json('ai_calibration')->nullable();

            // AI 滾動判斷紀錄 [{time, action, notes, adjustments}]
            $table->json('ai_advice_log')->nullable();

            // 狀態轉換紀錄 [{time, from_status, to_status, reason}]
            $table->json('state_log')->nullable();

            // 上次 AI 判斷時間
            $table->dateTime('last_ai_advice_at')->nullable();

            // 跳過原因（status=skipped 時填寫）
            $table->string('skip_reason')->nullable();

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_monitors');
    }
};

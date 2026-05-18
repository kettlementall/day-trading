<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('premarket_briefings', function (Blueprint $table) {
            $table->id();
            $table->date('trade_date')->unique();
            $table->string('market_context', 40)->nullable()->comment('normal/bullish_catalyst/bearish_panic');
            $table->string('direction', 20)->nullable()->comment('bullish/neutral/bearish');
            $table->string('headline', 200)->nullable();
            $table->json('drivers')->nullable()->comment('主要驅動因素（≤3 條）');
            $table->json('focus_sectors')->nullable()->comment('預期主流類股');
            $table->json('cautions')->nullable()->comment('風險提醒（≤2 條）');
            $table->text('raw_markdown')->nullable()->comment('Telegram 推播原文');
            $table->json('input_payload')->nullable()->comment('餵給 Opus 的原始資料快照（供盤後校對）');
            $table->string('model', 60)->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->decimal('cost_usd', 8, 4)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('premarket_briefings');
    }
};

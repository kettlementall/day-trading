<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_lessons', function (Blueprint $table) {
            $table->id();
            $table->date('trade_date')->comment('教訓來源的交易日');
            $table->string('type', 30)->comment('screening|calibration|entry|exit|market');
            $table->string('category', 50)->nullable()->comment('e.g. breakout, bounce, gap, sector');
            $table->text('content')->comment('結構化教訓內容');
            $table->date('expires_at')->comment('過期日，避免過時教訓影響判斷');
            $table->timestamps();

            $table->index(['type', 'expires_at']);
            $table->index('trade_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_lessons');
    }
};

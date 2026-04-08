<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->onDelete('cascade');
            $table->date('trade_date')->comment('預計操作日期');
            $table->decimal('suggested_buy', 10, 2)->comment('建議買入價');
            $table->decimal('target_price', 10, 2)->comment('目標獲利價');
            $table->decimal('stop_loss', 10, 2)->comment('建議停損價');
            $table->decimal('risk_reward_ratio', 5, 2)->default(0)->comment('風報比');
            $table->decimal('score', 5, 2)->default(0)->comment('綜合評分');
            $table->json('reasons')->nullable()->comment('選股理由標籤');
            $table->json('indicators')->nullable()->comment('技術指標快照');
            $table->timestamps();

            $table->unique(['stock_id', 'trade_date']);
            $table->index('trade_date');
            $table->index('score');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidates');
    }
};

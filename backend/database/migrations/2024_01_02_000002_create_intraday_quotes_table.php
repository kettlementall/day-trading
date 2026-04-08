<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intraday_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->decimal('open', 10, 2)->comment('開盤價');
            $table->decimal('high', 10, 2)->comment('最高價');
            $table->decimal('low', 10, 2)->comment('最低價');
            $table->decimal('current_price', 10, 2)->comment('現價');
            $table->decimal('prev_close', 10, 2)->comment('昨收');
            $table->bigInteger('accumulated_volume')->default(0)->comment('累積成交量（股）');
            $table->bigInteger('yesterday_volume')->default(0)->comment('昨日總成交量（股）');
            $table->decimal('estimated_volume_ratio', 8, 2)->default(0)->comment('預估量倍數');
            $table->decimal('open_change_percent', 8, 2)->default(0)->comment('開盤漲幅%');
            $table->decimal('first_5min_high', 10, 2)->nullable()->comment('第一根5分K高點');
            $table->decimal('first_5min_low', 10, 2)->nullable()->comment('第一根5分K低點');
            $table->bigInteger('buy_volume')->default(0)->comment('外盤量（股）');
            $table->bigInteger('sell_volume')->default(0)->comment('內盤量（股）');
            $table->decimal('external_ratio', 5, 2)->default(50)->comment('外盤比%');
            $table->timestamp('snapshot_at')->nullable()->comment('快照時間');
            $table->timestamps();

            $table->unique(['stock_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intraday_quotes');
    }
};

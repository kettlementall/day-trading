<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intraday_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->date('trade_date');
            $table->dateTime('snapshot_time');

            // 價格
            $table->decimal('open', 10, 2)->default(0);
            $table->decimal('high', 10, 2)->default(0);
            $table->decimal('low', 10, 2)->default(0);
            $table->decimal('current_price', 10, 2)->default(0);
            $table->decimal('prev_close', 10, 2)->default(0);

            // 成交量
            $table->bigInteger('accumulated_volume')->default(0);
            $table->decimal('estimated_volume_ratio', 8, 2)->default(0);
            $table->decimal('open_change_percent', 8, 2)->default(0);

            // 內外盤
            $table->bigInteger('buy_volume')->default(0);
            $table->bigInteger('sell_volume')->default(0);
            $table->decimal('external_ratio', 5, 2)->default(50);

            // 衍生指標
            $table->decimal('change_percent', 8, 2)->default(0);    // 現價 vs 昨收
            $table->decimal('amplitude_percent', 8, 2)->default(0); // (high-low)/prev_close

            $table->timestamps();

            $table->unique(['stock_id', 'trade_date', 'snapshot_time'], 'snapshots_unique');
            $table->index('trade_date');
            $table->index('snapshot_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intraday_snapshots');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('us_market_indices', function (Blueprint $table) {
            $table->id();
            $table->date('date')->comment('台灣日期（對應美股前一晚收盤）');
            $table->string('symbol', 20)->comment('^GSPC, ^SOX, ^DJI, etc.');
            $table->string('name', 50);
            $table->decimal('close', 12, 2);
            $table->decimal('prev_close', 12, 2);
            $table->decimal('change_percent', 6, 2);
            $table->timestamps();

            $table->unique(['date', 'symbol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('us_market_indices');
    }
};

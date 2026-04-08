<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->decimal('open', 10, 2);
            $table->decimal('high', 10, 2);
            $table->decimal('low', 10, 2);
            $table->decimal('close', 10, 2);
            $table->bigInteger('volume')->comment('成交股數');
            $table->bigInteger('trade_value')->default(0)->comment('成交金額');
            $table->integer('trade_count')->default(0)->comment('成交筆數');
            $table->decimal('change', 10, 2)->default(0)->comment('漲跌');
            $table->decimal('change_percent', 6, 2)->default(0);
            $table->decimal('amplitude', 6, 2)->default(0)->comment('振幅%');
            $table->timestamps();

            $table->unique(['stock_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_quotes');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dividend_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->date('ex_date')->comment('除權息日（TWT48U「除權除息日期」）');
            $table->string('type', 8)->nullable()->comment('息/權/權息');
            $table->decimal('cash_dividend', 12, 8)->default(0)->comment('現金股利（元/股）');
            $table->decimal('stock_ratio', 12, 8)->default(0)->comment('無償配股率（股/股）');
            $table->decimal('cash_capital_ratio', 12, 8)->default(0)->comment('現金增資配股率');
            $table->decimal('cash_capital_price', 12, 4)->nullable()->comment('現金增資認購價');
            $table->decimal('reference_price', 12, 4)->nullable()->comment('TWSE 參考價試算（若有提供）');
            $table->timestamps();

            $table->unique(['stock_id', 'ex_date']);
            $table->index('ex_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dividend_events');
    }
};

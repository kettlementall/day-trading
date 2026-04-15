<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_valuations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->onDelete('cascade');
            $table->date('date')->comment('資料日期（TWSE 每日收盤後更新）');
            $table->decimal('pe_ratio', 8, 2)->nullable()->comment('本益比');
            $table->decimal('pb_ratio', 8, 2)->nullable()->comment('股價淨值比');
            $table->decimal('dividend_yield', 6, 2)->nullable()->comment('殖利率（%）');
            $table->decimal('eps_ttm', 8, 2)->nullable()->comment('近四季 EPS（TTM）');
            $table->timestamps();

            $table->unique(['stock_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_valuations');
    }
};

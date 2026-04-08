<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('margin_trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->bigInteger('margin_buy')->default(0)->comment('融資買進');
            $table->bigInteger('margin_sell')->default(0)->comment('融資賣出');
            $table->bigInteger('margin_balance')->default(0)->comment('融資餘額');
            $table->bigInteger('margin_change')->default(0)->comment('融資增減');
            $table->bigInteger('short_buy')->default(0)->comment('融券買進');
            $table->bigInteger('short_sell')->default(0)->comment('融券賣出');
            $table->bigInteger('short_balance')->default(0)->comment('融券餘額');
            $table->bigInteger('short_change')->default(0)->comment('融券增減');
            $table->timestamps();

            $table->unique(['stock_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('margin_trades');
    }
};

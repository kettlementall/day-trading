<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institutional_trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->bigInteger('foreign_buy')->default(0)->comment('外資買');
            $table->bigInteger('foreign_sell')->default(0)->comment('外資賣');
            $table->bigInteger('foreign_net')->default(0)->comment('外資淨買賣');
            $table->bigInteger('trust_buy')->default(0)->comment('投信買');
            $table->bigInteger('trust_sell')->default(0)->comment('投信賣');
            $table->bigInteger('trust_net')->default(0)->comment('投信淨買賣');
            $table->bigInteger('dealer_buy')->default(0)->comment('自營商買');
            $table->bigInteger('dealer_sell')->default(0)->comment('自營商賣');
            $table->bigInteger('dealer_net')->default(0)->comment('自營商淨買賣');
            $table->bigInteger('total_net')->default(0)->comment('三大法人合計');
            $table->timestamps();

            $table->unique(['stock_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institutional_trades');
    }
};

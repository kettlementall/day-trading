<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->string('strategy_type', 20)->nullable()->after('score')
                ->comment('策略類型: bounce=跌深反彈, breakout=突破追多');
            $table->json('strategy_detail')->nullable()->after('strategy_type')
                ->comment('策略判斷細節');
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropColumn(['strategy_type', 'strategy_detail']);
        });
    }
};

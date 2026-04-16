<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            // 必須先建新 unique key，再刪舊的
            // 原因：舊 unique key [stock_id, trade_date] 同時作為 stock_id FK 的 backing index，
            // 直接 drop 會被 MySQL 拒絕（error 1553）
            // 新 unique key [stock_id, trade_date, mode] 同樣以 stock_id 開頭，可接替 FK 支撐
            $table->unique(['stock_id', 'trade_date', 'mode']);
            $table->dropUnique(['stock_id', 'trade_date']);
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->unique(['stock_id', 'trade_date']);
            $table->dropUnique(['stock_id', 'trade_date', 'mode']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_reviews', function (Blueprint $table) {
            $table->string('mode', 20)->default('intraday')->after('trade_date');
        });

        // 補上唯一索引（原本只有 trade_date，現在要支援 trade_date + mode）
        Schema::table('daily_reviews', function (Blueprint $table) {
            // 先移除原有唯一索引（若存在），再加新的複合唯一索引
            try {
                $table->dropUnique(['trade_date']);
            } catch (\Exception $e) {
                // 原無此索引，忽略
            }
            $table->unique(['trade_date', 'mode']);
        });
    }

    public function down(): void
    {
        Schema::table('daily_reviews', function (Blueprint $table) {
            $table->dropUnique(['trade_date', 'mode']);
            $table->dropColumn('mode');
        });
    }
};

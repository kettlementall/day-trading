<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_lessons', function (Blueprint $table) {
            $table->string('mode', 20)->default('intraday')->after('type')
                  ->comment('intraday | overnight | both（both = 兩種模式都適用）');
            $table->index('mode');
        });
    }

    public function down(): void
    {
        Schema::table('ai_lessons', function (Blueprint $table) {
            $table->dropIndex(['mode']);
            $table->dropColumn('mode');
        });
    }
};

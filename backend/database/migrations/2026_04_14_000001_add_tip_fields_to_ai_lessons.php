<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_lessons', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('expires_at')
                ->comment('null=每日自動萃取, tip=使用者手動輸入明牌');
            $table->unsignedTinyInteger('priority')->default(0)->after('source')
                ->comment('排序優先度：0=一般, 1=明牌（高優先）');

            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::table('ai_lessons', function (Blueprint $table) {
            $table->dropIndex(['priority']);
            $table->dropColumn(['source', 'priority']);
        });
    }
};

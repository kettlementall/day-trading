<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->decimal('morning_score', 5, 2)->default(0)->after('score')->comment('盤前30分鐘確認評分');
            $table->json('morning_signals')->nullable()->after('morning_score')->comment('盤前確認信號');
            $table->boolean('morning_confirmed')->default(false)->after('morning_signals')->comment('是否通過盤前確認');
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropColumn(['morning_score', 'morning_signals', 'morning_confirmed']);
        });
    }
};

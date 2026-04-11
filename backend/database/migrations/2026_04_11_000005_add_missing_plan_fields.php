<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // intraday_snapshots: 加最佳五檔買賣價
        Schema::table('intraday_snapshots', function (Blueprint $table) {
            $table->decimal('best_ask', 10, 2)->default(0)->after('external_ratio');
            $table->decimal('best_bid', 10, 2)->default(0)->after('best_ask');
        });

        // candidate_monitors: 加進場類型
        Schema::table('candidate_monitors', function (Blueprint $table) {
            $table->string('entry_type', 30)->nullable()->after('entry_time');
        });

        // candidate_results: 加進場類型
        Schema::table('candidate_results', function (Blueprint $table) {
            $table->string('entry_type', 30)->nullable()->after('exit_price_actual');
        });
    }

    public function down(): void
    {
        Schema::table('intraday_snapshots', function (Blueprint $table) {
            $table->dropColumn(['best_ask', 'best_bid']);
        });
        Schema::table('candidate_monitors', function (Blueprint $table) {
            $table->dropColumn('entry_type');
        });
        Schema::table('candidate_results', function (Blueprint $table) {
            $table->dropColumn('entry_type');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_results', function (Blueprint $table) {
            $table->boolean('buy_reachable')->default(false)->after('max_loss_percent');
            $table->boolean('target_reachable')->default(false)->after('buy_reachable');
            $table->decimal('buy_gap_percent', 6, 2)->default(0)->after('target_reachable');
            $table->decimal('target_gap_percent', 6, 2)->default(0)->after('buy_gap_percent');
        });

        // 回填現有資料
        DB::statement("
            UPDATE candidate_results cr
            JOIN candidates c ON cr.candidate_id = c.id
            SET
                cr.buy_reachable = (cr.actual_low <= c.suggested_buy),
                cr.target_reachable = (cr.actual_high >= c.target_price),
                cr.buy_gap_percent = CASE
                    WHEN c.suggested_buy > 0 THEN ROUND((c.suggested_buy - cr.actual_low) / c.suggested_buy * 100, 2)
                    ELSE 0
                END,
                cr.target_gap_percent = CASE
                    WHEN c.target_price > 0 THEN ROUND((cr.actual_high - c.target_price) / c.target_price * 100, 2)
                    ELSE 0
                END
        ");
    }

    public function down(): void
    {
        Schema::table('candidate_results', function (Blueprint $table) {
            $table->dropColumn(['buy_reachable', 'target_reachable', 'buy_gap_percent', 'target_gap_percent']);
        });
    }
};

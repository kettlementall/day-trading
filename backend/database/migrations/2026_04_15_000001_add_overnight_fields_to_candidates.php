<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            // 模式識別
            $table->string('mode', 20)->default('intraday')->after('trade_date')
                  ->comment('intraday=當日當沖 | overnight=隔日沖');
            $table->index('mode');

            // overnight 專用欄位
            $table->string('overnight_strategy', 50)->nullable()->after('intraday_strategy')
                  ->comment('gap_up_open | pullback_entry | open_follow_through | limit_up_chase');
            $table->text('overnight_reasoning')->nullable()->after('overnight_strategy')
                  ->comment('Opus 完整進場策略說明');
            $table->decimal('gap_potential_percent', 6, 2)->nullable()->after('overnight_reasoning')
                  ->comment('Opus 預測次日跳空幅度（%）');

            // 讓 suggested_buy / target_price / stop_loss / risk_reward_ratio 支援 null
            // （overnight 模式物理篩選階段先不設定，等 Opus 覆寫）
            $table->decimal('suggested_buy', 10, 2)->nullable()->change();
            $table->decimal('target_price', 10, 2)->nullable()->change();
            $table->decimal('stop_loss', 10, 2)->nullable()->change();
            $table->decimal('risk_reward_ratio', 5, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropColumn(['mode', 'overnight_strategy', 'overnight_reasoning', 'gap_potential_percent']);
            $table->decimal('suggested_buy', 10, 2)->nullable(false)->change();
            $table->decimal('target_price', 10, 2)->nullable(false)->change();
            $table->decimal('stop_loss', 10, 2)->nullable(false)->change();
            $table->decimal('risk_reward_ratio', 5, 2)->default(0)->nullable(false)->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->string('swing_strategy', 80)->nullable()->after('overnight_key_levels')
                ->comment('短線策略類型');
            $table->text('swing_reasoning')->nullable()->after('swing_strategy')
                ->comment('短線 AI 理專判斷');
            $table->json('swing_thesis')->nullable()->after('swing_reasoning')
                ->comment('短線產業論點與關聯度');
            $table->unsignedTinyInteger('swing_time_horizon_days')->nullable()->after('swing_thesis')
                ->comment('預估持有交易日');
            $table->json('swing_entry_plan')->nullable()->after('swing_time_horizon_days')
                ->comment('短線買入區間與操作計畫');
            $table->json('swing_risk_notes')->nullable()->after('swing_entry_plan')
                ->comment('短線風險與論點失效條件');
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropColumn([
                'swing_strategy',
                'swing_reasoning',
                'swing_thesis',
                'swing_time_horizon_days',
                'swing_entry_plan',
                'swing_risk_notes',
            ]);
        });
    }
};

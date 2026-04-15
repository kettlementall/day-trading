<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategy_performance_stats', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 20)->comment('intraday | overnight');
            $table->string('dimension_type', 30)->comment('strategy | feature | market_condition');
            $table->string('dimension_value', 100)->comment('breakout_fresh | 爆量+法人買超 | 大盤>+1% ...');
            $table->integer('period_days')->comment('統計滾動天數：30 | 60');
            $table->integer('sample_count')->default(0);
            $table->decimal('target_reach_rate', 6, 2)->default(0)->comment('達標率 %');
            $table->decimal('expected_value', 6, 2)->default(0)->comment('期望值 %');
            $table->decimal('avg_risk_reward', 5, 2)->default(0)->comment('平均風報比');
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['mode', 'dimension_type', 'dimension_value', 'period_days'], 'stats_unique');
            $table->index(['mode', 'dimension_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_performance_stats');
    }
};

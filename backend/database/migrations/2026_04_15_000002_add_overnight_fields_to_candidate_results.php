<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_results', function (Blueprint $table) {
            $table->decimal('open_gap_percent', 6, 2)->nullable()->after('actual_open')
                  ->comment('次日實際跳空幅度（actual_open vs prev_close）');
            $table->boolean('gap_predicted_correctly')->nullable()->after('open_gap_percent')
                  ->comment('跳空預測是否正確（方向對且誤差 < 2%）');
            $table->string('overnight_outcome', 30)->nullable()->after('gap_predicted_correctly')
                  ->comment('target_hit | stop_hit | gap_up_exit | gap_down_stopped | end_of_day');
        });
    }

    public function down(): void
    {
        Schema::table('candidate_results', function (Blueprint $table) {
            $table->dropColumn(['open_gap_percent', 'gap_predicted_correctly', 'overnight_outcome']);
        });
    }
};

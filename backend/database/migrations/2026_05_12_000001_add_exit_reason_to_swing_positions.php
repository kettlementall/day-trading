<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('swing_positions', function (Blueprint $table) {
            $table->string('exit_reason', 32)->nullable()->after('exit_date')
                ->comment('target_hit|stop_hit|take_profit_manual|cut_loss_manual|thesis_broken|time_stop|switch_position|other');
            $table->text('exit_note')->nullable()->after('exit_reason')
                ->comment('使用者選填出場補充說明，≤200 字');

            $table->index('exit_reason');
        });
    }

    public function down(): void
    {
        Schema::table('swing_positions', function (Blueprint $table) {
            $table->dropIndex(['exit_reason']);
            $table->dropColumn(['exit_reason', 'exit_note']);
        });
    }
};

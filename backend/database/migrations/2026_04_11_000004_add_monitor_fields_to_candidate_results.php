<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_results', function (Blueprint $table) {
            $table->dateTime('entry_time')->nullable()->after('target_gap_percent');
            $table->dateTime('exit_time')->nullable()->after('entry_time');
            $table->decimal('entry_price_actual', 10, 2)->nullable()->after('exit_time');
            $table->decimal('exit_price_actual', 10, 2)->nullable()->after('entry_price_actual');
            $table->decimal('mfe_percent', 6, 2)->default(0)->after('exit_price_actual');
            $table->decimal('mae_percent', 6, 2)->default(0)->after('mfe_percent');
            $table->boolean('valid_entry')->default(false)->after('mae_percent');
            $table->string('monitor_status', 30)->nullable()->after('valid_entry');
        });
    }

    public function down(): void
    {
        Schema::table('candidate_results', function (Blueprint $table) {
            $table->dropColumn([
                'entry_time', 'exit_time', 'entry_price_actual', 'exit_price_actual',
                'mfe_percent', 'mae_percent', 'valid_entry', 'monitor_status',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_results', function (Blueprint $table) {
            $table->string('unreachable_reason', 40)
                ->nullable()
                ->after('buy_reachable')
                ->comment('物理上無法建倉的原因：t0_limit_up_locked / null=可成交');
            $table->index('unreachable_reason');
        });
    }

    public function down(): void
    {
        Schema::table('candidate_results', function (Blueprint $table) {
            $table->dropIndex(['unreachable_reason']);
            $table->dropColumn('unreachable_reason');
        });
    }
};

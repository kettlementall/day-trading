<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->string('overnight_rank_tier', 20)->nullable()->after('overnight_key_levels');
            $table->string('overnight_regime_fit', 20)->nullable()->after('overnight_rank_tier');
            $table->text('overnight_final_rank_reasoning')->nullable()->after('overnight_regime_fit');
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropColumn([
                'overnight_rank_tier',
                'overnight_regime_fit',
                'overnight_final_rank_reasoning',
            ]);
        });
    }
};

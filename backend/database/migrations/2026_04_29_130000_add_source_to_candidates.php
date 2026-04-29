<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 腿 2：盤中動態加入候選
 *
 * - candidates 新增 source / intraday_added_at 欄位
 * - formula_settings 新增 intraday_mover_thresholds 設定
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->string('source', 32)->default('morning')->after('mode')->index();
            $table->timestamp('intraday_added_at')->nullable()->after('source');
        });

        // 盤中加入閾值設定
        if (!DB::table('formula_settings')->where('type', 'intraday_mover_thresholds')->exists()) {
            DB::table('formula_settings')->insert([
                'type'       => 'intraday_mover_thresholds',
                'config'     => json_encode([
                    'pool_top_n'            => 100,
                    'min_change_pct'        => 3.0,
                    'min_vol_ratio'         => 1.5,
                    'limit_up_buffer_pct'   => 1.5,
                    'min_external_ratio'    => 55,
                    'min_haiku_confidence'  => 60,
                    'min_risk_reward'       => 0.8,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn(['source', 'intraday_added_at']);
        });

        DB::table('formula_settings')->where('type', 'intraday_mover_thresholds')->delete();
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 只在不存在時才插入
        $exists = DB::table('formula_settings')
            ->where('type', 'screen_thresholds_overnight')
            ->exists();

        if (!$exists) {
            DB::table('formula_settings')->insert([
                'type'       => 'screen_thresholds_overnight',
                'config'     => json_encode([
                    'min_volume'              => 500,   // 單日最低成交量（張）
                    'min_price'               => 10,    // 最低股價
                    'min_amplitude'           => 0.5,   // 5日均振幅（%）
                    'min_day_trading_volume'  => 200,   // 5日均量（張）
                    'max_candidates'          => 100,   // 最多輸出幾檔
                    // 注意：overnight 不設 min_risk_reward，由 Opus 自行判斷
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('formula_settings')
            ->where('type', 'screen_thresholds_overnight')
            ->delete();
    }
};

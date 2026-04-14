<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. 新增動能延續評分項到 scoring config
        $scoring = DB::table('formula_settings')->where('type', 'scoring')->first();
        if ($scoring) {
            $config = json_decode($scoring->config, true);
            $config['momentum_continuation'] = [
                'enabled'      => true,
                'score'        => 15,
                'lookback_days'=> 20,
                'min_gain_pct' => 30,   // 近 20 日漲幅需達 30%
                'min_k'        => 50,   // K 值需 >= 50（趨勢未轉弱）
            ];
            DB::table('formula_settings')
                ->where('type', 'scoring')
                ->update(['config' => json_encode($config), 'updated_at' => now()]);
        }

        // 2. 調低 min_risk_reward：1.0 → 0.7
        //    （已有 RR < 1.2 扣分懲罰，不需硬排除到 1.0；高 ATR 動能股 RR 結構性偏低）
        $thresholds = DB::table('formula_settings')->where('type', 'screen_thresholds')->first();
        if ($thresholds) {
            $config = json_decode($thresholds->config, true);
            $config['min_risk_reward'] = 0.7;
            DB::table('formula_settings')
                ->where('type', 'screen_thresholds')
                ->update(['config' => json_encode($config), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        $scoring = DB::table('formula_settings')->where('type', 'scoring')->first();
        if ($scoring) {
            $config = json_decode($scoring->config, true);
            unset($config['momentum_continuation']);
            DB::table('formula_settings')
                ->where('type', 'scoring')
                ->update(['config' => json_encode($config), 'updated_at' => now()]);
        }

        $thresholds = DB::table('formula_settings')->where('type', 'screen_thresholds')->first();
        if ($thresholds) {
            $config = json_decode($thresholds->config, true);
            $config['min_risk_reward'] = 1.0;
            DB::table('formula_settings')
                ->where('type', 'screen_thresholds')
                ->update(['config' => json_encode($config), 'updated_at' => now()]);
        }
    }
};

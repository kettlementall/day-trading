<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 當沖物理門檻排序：5 日均量降冪 → 複合分數降冪
 *
 * - screener_compound_weights：振幅／流動性／日內活躍／籌碼／動能／突破 6 項加權
 * - screener_penalties：漲停、過熱、跌停痕跡、弱勢 4 項負分
 * - 同時把 intraday screen_thresholds.max_candidates 從 80 升到 100
 *
 * 隔日沖（overnight）排序與設定不動，仍走 5 日均量降冪。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. 複合分數加權
        if (!DB::table('formula_settings')->where('type', 'screener_compound_weights')->exists()) {
            DB::table('formula_settings')->insert([
                'type'       => 'screener_compound_weights',
                'config'     => json_encode([
                    'amplitude' => 0.35, // 5日均振幅 — 當沖核心
                    'liquidity' => 0.20, // log 飽和的成交量
                    'pattern'   => 0.15, // 近 10 日 ≥5% 振幅天數
                    'chips'     => 0.15, // 法人 + 融資融券
                    'momentum'  => 0.10, // 前日漲幅 + 近 3 日累計
                    'breakout'  => 0.05, // 突破前高 + 爆量 + 站上短均
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2. 負分機制
        if (!DB::table('formula_settings')->where('type', 'screener_penalties')->exists()) {
            DB::table('formula_settings')->insert([
                'type'       => 'screener_penalties',
                'config'     => json_encode([
                    'prev_limit_up'   => 25, // 前日漲停（≥9.8%）
                    'hot_streak'      => 20, // 連漲 ≥3 日且累計 ≥15%
                    'limit_down_5d'   => 15, // 近 5 日有跌停
                    'weak_3d'         => 10, // 近 3 日累計跌幅 >8%
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 3. intraday max_candidates 80 → 100
        $intradayConfig = DB::table('formula_settings')->where('type', 'screen_thresholds')->first();
        if ($intradayConfig) {
            $config = json_decode($intradayConfig->config, true) ?: [];
            if (($config['max_candidates'] ?? 80) < 100) {
                $config['max_candidates'] = 100;
                DB::table('formula_settings')
                    ->where('type', 'screen_thresholds')
                    ->update([
                        'config'     => json_encode($config),
                        'updated_at' => now(),
                    ]);
            }
        } else {
            DB::table('formula_settings')->insert([
                'type'       => 'screen_thresholds',
                'config'     => json_encode([
                    'min_volume'              => 500,
                    'min_price'               => 10,
                    'min_amplitude'           => 2.5,
                    'min_day_trading_volume'  => 200,
                    'min_risk_reward'         => 0.8,
                    'max_candidates'          => 100,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('formula_settings')
            ->whereIn('type', ['screener_compound_weights', 'screener_penalties'])
            ->delete();

        // 還原 intraday max_candidates 100 → 80
        $intradayConfig = DB::table('formula_settings')->where('type', 'screen_thresholds')->first();
        if ($intradayConfig) {
            $config = json_decode($intradayConfig->config, true) ?: [];
            if (($config['max_candidates'] ?? null) === 100) {
                $config['max_candidates'] = 80;
                DB::table('formula_settings')
                    ->where('type', 'screen_thresholds')
                    ->update([
                        'config'     => json_encode($config),
                        'updated_at' => now(),
                    ]);
            }
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('formula_settings')->insert([
            [
                'type' => 'strategy',
                'config' => json_encode([
                    'bounce' => [
                        'enabled' => true,
                        'score' => 15,
                        'washout_drop_pct' => -5,
                        'two_day_drop_pct' => -7,
                        'washout_lookback_days' => 3,
                        'bounce_from_low_pct' => 3,
                    ],
                    'breakout' => [
                        'enabled' => true,
                        'score' => 15,
                        'prev_high_days' => 5,
                        'near_breakout_pct' => 0.98,
                    ],
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'type' => 'scoring',
                'config' => json_encode([
                    'volume_surge' => ['enabled' => true, 'score' => 15, 'ratio' => 1.5],
                    'ma_bullish' => ['enabled' => true, 'score' => 15],
                    'above_ma5' => ['enabled' => true, 'score' => 5],
                    'kd_golden_cross' => ['enabled' => true, 'score' => 10],
                    'rsi_moderate' => ['enabled' => true, 'score' => 5, 'min' => 40, 'max' => 70],
                    'foreign_buy' => ['enabled' => true, 'score' => 10],
                    'consecutive_buy' => ['enabled' => true, 'score' => 10, 'min_days' => 3],
                    'trust_buy' => ['enabled' => true, 'score' => 5],
                    'margin_decrease' => ['enabled' => true, 'score' => 5],
                    'amplitude_moderate' => ['enabled' => true, 'score' => 5, 'min' => 2, 'max' => 7],
                    'break_prev_high' => ['enabled' => true, 'score' => 10],
                    'bollinger_position' => ['enabled' => true, 'score' => 5],
                    'high_volatility' => ['enabled' => true, 'score' => 10, 'min_amplitude' => 5, 'lookback_days' => 10],
                    'strong_trend' => ['enabled' => true, 'score' => 10, 'min_gain_pct' => 15, 'lookback_days' => 20],
                    'foreign_big_buy' => ['enabled' => true, 'score' => 5, 'volume_ratio' => 0.05],
                    'dealer_big_buy' => ['enabled' => true, 'score' => 5, 'volume_ratio' => 0.03],
                    'high_volume' => ['enabled' => true, 'score' => 5, 'min_lots' => 10000],
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('formula_settings')->whereIn('type', ['strategy', 'scoring'])->delete();
    }
};

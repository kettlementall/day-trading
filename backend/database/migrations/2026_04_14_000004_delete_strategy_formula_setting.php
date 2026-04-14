<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('formula_settings')->where('type', 'strategy')->delete();
    }

    public function down(): void
    {
        DB::table('formula_settings')->insert([
            'type'       => 'strategy',
            'config'     => json_encode([
                'bounce' => [
                    'enabled'              => true,
                    'washout_drop_pct'     => -7,
                    'two_day_drop_pct'     => -10,
                    'washout_lookback_days' => 5,
                    'bounce_from_low_pct'  => 3.0,
                ],
                'breakout' => [
                    'enabled'          => true,
                    'prev_high_days'   => 5,
                    'near_breakout_pct' => 0.98,
                ],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};

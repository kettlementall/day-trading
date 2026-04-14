<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('formula_settings')->insert([
            'type'   => 'signal_labels',
            'config' => json_encode([
                'volume_surge' => [
                    'label'      => '量放大',
                    'enabled'    => true,
                    'days'       => 5,
                    'multiplier' => 1.5,
                ],
                'foreign_buy' => [
                    'label'   => '外資買超',
                    'enabled' => true,
                    'min_net' => 0,
                ],
                'trust_buy' => [
                    'label'   => '投信買超',
                    'enabled' => true,
                    'min_net' => 0,
                ],
                'breakout_high' => [
                    'label'   => '突破前高',
                    'enabled' => true,
                    'days'    => 5,
                ],
                'margin_decrease' => [
                    'label'      => '融資減',
                    'enabled'    => true,
                    'max_change' => 0,
                ],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('formula_settings')->where('type', 'signal_labels')->delete();
    }
};

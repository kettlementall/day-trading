<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formula_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30)->unique(); // suggested_buy, target_price, stop_loss
            $table->json('config');
            $table->timestamps();
        });

        // 預設值
        DB::table('formula_settings')->insert([
            [
                'type' => 'suggested_buy',
                'config' => json_encode([
                    'sources' => [
                        'recent_low' => ['enabled' => true, 'days' => 5],
                        'ma' => ['enabled' => true, 'period' => 5],
                        'bollinger_middle' => ['enabled' => true],
                    ],
                    'filter_lower_pct' => 0.95,
                    'fallback_pct' => 0.99,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'type' => 'target_price',
                'config' => json_encode([
                    'sources' => [
                        'recent_high' => ['enabled' => true, 'days' => 5],
                        'atr' => ['enabled' => true, 'multiplier' => 1.5],
                        'bollinger_upper' => ['enabled' => true],
                    ],
                    'filter_upper_pct' => 1.10,
                    'fallback_pct' => 1.03,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'type' => 'stop_loss',
                'config' => json_encode([
                    'sources' => [
                        'atr' => ['enabled' => true, 'multiplier' => 1.0],
                        'recent_low' => ['enabled' => true, 'days' => 5],
                    ],
                    'fallback_pct' => 0.985,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('formula_settings');
    }
};

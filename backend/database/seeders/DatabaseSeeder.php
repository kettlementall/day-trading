<?php

namespace Database\Seeders;

use App\Models\ScreeningRule;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 預設篩選規則
        ScreeningRule::create([
            'name' => '量能活躍股',
            'conditions' => [
                ['field' => 'volume', 'operator' => '>', 'value' => 2000],
                ['field' => 'amplitude', 'operator' => '>', 'value' => 2],
            ],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        ScreeningRule::create([
            'name' => '法人同步買超',
            'conditions' => [
                ['field' => 'foreign_net', 'operator' => '>', 'value' => 0],
                ['field' => 'trust_net', 'operator' => '>', 'value' => 0],
            ],
            'is_active' => true,
            'sort_order' => 2,
        ]);

        ScreeningRule::create([
            'name' => '融資減少型',
            'conditions' => [
                ['field' => 'margin_change', 'operator' => '<', 'value' => 0],
                ['field' => 'volume', 'operator' => '>', 'value' => 1000],
            ],
            'is_active' => true,
            'sort_order' => 3,
        ]);
    }
}

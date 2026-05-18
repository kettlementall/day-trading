<?php

namespace Tests\Unit\Services;

use App\Models\IntradaySnapshot;
use App\Models\MarginTrade;
use App\Services\StockScreener;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class StockScreenerOvernightScoreTest extends TestCase
{
    public function test_strong_overnight_setup_scores_above_weak_high_volume_setup(): void
    {
        $strongScore = $this->score([
            'closes' => [105, 101, 100, 99, 98],
            'highs' => [106, 102, 101, 100, 99],
            'lows' => [100, 98, 97, 96, 95],
            'volumes' => [3_000_000, 1_400_000, 1_300_000, 1_200_000, 1_100_000],
            'changes' => [3.5, 1.2, 0.8, 0.5, -0.2],
            'avgVol5Lots' => 1600,
            'ma5' => 100,
            'ma10' => 98,
            'ma20' => 95,
            'inst' => collect([
                (object) ['foreign_net' => 1200, 'trust_net' => 300],
                (object) ['foreign_net' => 800, 'trust_net' => 0],
                (object) ['foreign_net' => 600, 'trust_net' => 0],
            ]),
            'margin' => new MarginTrade(['margin_change' => -100]),
            'snapshot' => new IntradaySnapshot([
                'current_price' => '105',
                'high' => '106',
                'low' => '100',
                'estimated_volume_ratio' => '1.8',
                'external_ratio' => '58',
            ]),
        ]);

        $weakScore = $this->score([
            'closes' => [88, 91, 94, 96, 98],
            'highs' => [94, 96, 98, 99, 100],
            'lows' => [87, 90, 93, 95, 97],
            'volumes' => [10_000_000, 9_000_000, 8_000_000, 7_000_000, 6_000_000],
            'changes' => [-2.5, -1.8, -1.2, -0.5, 0.2],
            'avgVol5Lots' => 8000,
            'ma5' => 92,
            'ma10' => 95,
            'ma20' => 98,
            'inst' => collect([
                (object) ['foreign_net' => -1000, 'trust_net' => -100],
            ]),
            'margin' => new MarginTrade(['margin_change' => 800]),
            'snapshot' => new IntradaySnapshot([
                'current_price' => '88',
                'high' => '94',
                'low' => '87',
                'estimated_volume_ratio' => '0.6',
                'external_ratio' => '42',
            ]),
        ]);

        $this->assertGreaterThan($weakScore, $strongScore);
    }

    private function score(array $data): float
    {
        $method = (new ReflectionClass(StockScreener::class))->getMethod('calcOvernightScore');

        return $method->invoke(
            new StockScreener(),
            $data['closes'],
            $data['highs'],
            $data['lows'],
            $data['volumes'],
            $data['changes'],
            $data['avgVol5Lots'],
            $data['ma5'],
            $data['ma10'],
            $data['ma20'],
            $data['inst'],
            $data['margin'],
            $data['snapshot'],
        );
    }
}

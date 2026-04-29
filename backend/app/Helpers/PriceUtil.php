<?php

namespace App\Helpers;

class PriceUtil
{
    /**
     * 台股升降單位（tick size）四捨五入
     */
    public static function tickRound(float $price, float $refPrice, string $direction = 'nearest'): float
    {
        $tick = match (true) {
            $refPrice < 10   => 0.01,
            $refPrice < 50   => 0.05,
            $refPrice < 100  => 0.10,
            $refPrice < 500  => 0.50,
            $refPrice < 1000 => 1.00,
            default          => 5.00,
        };

        return match ($direction) {
            'up'    => ceil($price / $tick) * $tick,
            'down'  => floor($price / $tick) * $tick,
            default => round($price / $tick) * $tick,
        };
    }

    /**
     * 計算漲停價（昨收 ×1.10，tick round down）
     */
    public static function limitUp(float $prevClose): float
    {
        return self::tickRound($prevClose * 1.10, $prevClose, 'down');
    }

    /**
     * 計算跌停價（昨收 ×0.90，tick round up）
     */
    public static function limitDown(float $prevClose): float
    {
        return self::tickRound($prevClose * 0.90, $prevClose, 'up');
    }
}

<?php

namespace App\Services;

class TechnicalIndicator
{
    /**
     * 計算簡單移動平均線 (SMA)
     */
    public static function sma(array $closes, int $period): ?float
    {
        if (count($closes) < $period) return null;
        return round(array_sum(array_slice($closes, 0, $period)) / $period, 2);
    }

    /**
     * 計算 RSI
     */
    public static function rsi(array $closes, int $period = 14): ?float
    {
        if (count($closes) < $period + 1) return null;

        $gains = [];
        $losses = [];

        for ($i = 0; $i < $period; $i++) {
            $diff = $closes[$i] - $closes[$i + 1]; // 由新到舊
            if ($diff > 0) {
                $gains[] = $diff;
                $losses[] = 0;
            } else {
                $gains[] = 0;
                $losses[] = abs($diff);
            }
        }

        $avgGain = array_sum($gains) / $period;
        $avgLoss = array_sum($losses) / $period;

        if ($avgLoss == 0) return 100;

        $rs = $avgGain / $avgLoss;
        return round(100 - (100 / (1 + $rs)), 2);
    }

    /**
     * 計算 KD 指標 (取最新值)
     */
    public static function kd(array $highs, array $lows, array $closes, int $period = 9): ?array
    {
        if (count($closes) < $period) return null;

        $highestHigh = max(array_slice($highs, 0, $period));
        $lowestLow = min(array_slice($lows, 0, $period));

        if ($highestHigh == $lowestLow) return ['k' => 50, 'd' => 50];

        $rsv = ($closes[0] - $lowestLow) / ($highestHigh - $lowestLow) * 100;

        // 簡化：用 RSV 近似 K，K 的 SMA 近似 D
        $k = round($rsv, 2);
        $d = round($k * 2 / 3 + 50 / 3, 2); // 簡化平滑

        return ['k' => $k, 'd' => $d];
    }

    /**
     * 計算 MACD
     */
    public static function macd(array $closes, int $fast = 12, int $slow = 26, int $signal = 9): ?array
    {
        if (count($closes) < $slow) return null;

        $emaFast = self::ema(array_reverse($closes), $fast);
        $emaSlow = self::ema(array_reverse($closes), $slow);

        if (empty($emaFast) || empty($emaSlow)) return null;

        $dif = round(end($emaFast) - end($emaSlow), 2);

        return ['dif' => $dif, 'macd' => $dif]; // 簡化
    }

    /**
     * 計算 ATR (Average True Range)
     */
    public static function atr(array $highs, array $lows, array $closes, int $period = 14): ?float
    {
        if (count($closes) < $period + 1) return null;

        $trueRanges = [];
        for ($i = 0; $i < $period; $i++) {
            $tr = max(
                $highs[$i] - $lows[$i],
                abs($highs[$i] - $closes[$i + 1]),
                abs($lows[$i] - $closes[$i + 1])
            );
            $trueRanges[] = $tr;
        }

        return round(array_sum($trueRanges) / $period, 2);
    }

    /**
     * 布林通道
     */
    public static function bollinger(array $closes, int $period = 20, float $multiplier = 2): ?array
    {
        if (count($closes) < $period) return null;

        $slice = array_slice($closes, 0, $period);
        $sma = array_sum($slice) / $period;
        $variance = array_sum(array_map(fn ($c) => pow($c - $sma, 2), $slice)) / $period;
        $std = sqrt($variance);

        return [
            'upper' => round($sma + $multiplier * $std, 2),
            'middle' => round($sma, 2),
            'lower' => round($sma - $multiplier * $std, 2),
        ];
    }

    private static function ema(array $data, int $period): array
    {
        if (count($data) < $period) return [];

        $multiplier = 2 / ($period + 1);
        $emaValues = [];
        $emaValues[] = array_sum(array_slice($data, 0, $period)) / $period;

        for ($i = $period; $i < count($data); $i++) {
            $emaValues[] = ($data[$i] - end($emaValues)) * $multiplier + end($emaValues);
        }

        return $emaValues;
    }
}

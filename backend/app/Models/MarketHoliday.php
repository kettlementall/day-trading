<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketHoliday extends Model
{
    protected $fillable = ['date', 'name'];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];

    /**
     * 判斷指定日期是否為休市日（週末或國定假日）
     */
    public static function isHoliday(string $date): bool
    {
        $carbon = \Carbon\Carbon::parse($date);

        // 週末
        if ($carbon->isWeekend()) {
            return true;
        }

        // 國定假日
        return static::where('date', $date)->exists();
    }

    /**
     * 給定日期，回傳「下一個交易日」（跳過週末與國定假日）
     * 例如：2026-04-30（四）+ 05/01 勞動節 + 週末 → 2026-05-04（一）
     */
    public static function nextTradingDay(string $date): string
    {
        $next = \Carbon\Carbon::parse($date)->addDay();
        while (static::isHoliday($next->toDateString())) {
            $next->addDay();
        }
        return $next->toDateString();
    }
}

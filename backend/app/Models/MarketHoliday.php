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
}

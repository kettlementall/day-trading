<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsMarketIndex extends Model
{
    protected $fillable = [
        'date', 'symbol', 'name', 'close', 'prev_close', 'change_percent',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'close' => 'decimal:2',
        'prev_close' => 'decimal:2',
        'change_percent' => 'decimal:2',
    ];

    /**
     * 取得指定日期的美股摘要（供 AI prompt 注入）
     */
    public static function getSummary(string $date): string
    {
        $indices = static::where('date', $date)->get();

        if ($indices->isEmpty()) {
            return '';
        }

        $lines = $indices->map(function ($i) {
            $sign = $i->change_percent >= 0 ? '+' : '';
            return "{$i->name} {$sign}{$i->change_percent}%";
        });

        return "## 昨夜美股收盤\n" . $lines->implode(' | ');
    }
}

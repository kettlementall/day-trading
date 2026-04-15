<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SectorIndex extends Model
{
    protected $fillable = [
        'date', 'sector_code', 'sector_name',
        'index_value', 'change_percent', 'volume',
    ];

    protected $casts = [
        'date'           => 'date:Y-m-d',
        'index_value'    => 'decimal:2',
        'change_percent' => 'decimal:2',
    ];

    /**
     * 取得某日所有類股強弱，格式化供 AI prompt 使用
     */
    public static function getSectorSummary(string $date): string
    {
        $sectors = static::where('date', $date)
            ->orderByDesc('change_percent')
            ->get();

        if ($sectors->isEmpty()) {
            return '（類股資料未取得）';
        }

        return $sectors->map(function ($s) {
            $sign = $s->change_percent >= 0 ? '+' : '';
            return "- {$s->sector_name}: {$sign}{$s->change_percent}%";
        })->implode("\n");
    }

    /**
     * 取得特定類股今日漲跌幅（依 sector_name 查詢）
     */
    public static function getChangeForIndustry(string $date, string $industry): ?float
    {
        $sector = static::where('date', $date)
            ->where('sector_name', $industry)
            ->first();

        return $sector ? (float) $sector->change_percent : null;
    }

    /**
     * 取得某日類股排名（由強到弱）
     */
    public static function getRankForIndustry(string $date, string $industry): ?int
    {
        $sectors = static::where('date', $date)
            ->orderByDesc('change_percent')
            ->pluck('sector_name')
            ->values();

        $index = $sectors->search($industry);

        return $index !== false ? $index + 1 : null;
    }
}

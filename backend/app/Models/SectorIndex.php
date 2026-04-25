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
     * 取得指定日期（含）以前最近一個有資料的日期
     * TWSE MI_INDEX 為收盤指數，盤中抓到的是前一交易日資料
     */
    public static function latestDateOn(string $date): ?string
    {
        return static::where('date', '<=', $date)
            ->orderByDesc('date')
            ->value('date')
            ?->format('Y-m-d');
    }

    /**
     * 取得某日所有類股強弱，格式化供 AI prompt 使用
     * 指定日期無資料時自動 fallback 到最近的交易日
     */
    public static function getSectorSummary(string $date): string
    {
        $effectiveDate = static::latestDateOn($date);
        if (!$effectiveDate) {
            return '（類股資料未取得）';
        }

        $sectors = static::where('date', $effectiveDate)
            ->orderByDesc('change_percent')
            ->get();

        $label = $effectiveDate !== $date ? "（資料日期：{$effectiveDate}）\n" : '';

        return $label . $sectors->map(function ($s) {
            $sign = $s->change_percent >= 0 ? '+' : '';
            return "- {$s->sector_name}: {$sign}{$s->change_percent}%";
        })->implode("\n");
    }

    /**
     * 取得特定類股今日漲跌幅（依 sector_name 查詢）
     * 指定日期無資料時自動 fallback 到最近的交易日
     */
    public static function getChangeForIndustry(string $date, string $industry): ?float
    {
        $effectiveDate = static::latestDateOn($date) ?? $date;

        $sector = static::where('date', $effectiveDate)
            ->where('sector_name', $industry)
            ->first();

        return $sector ? (float) $sector->change_percent : null;
    }

    /**
     * 取得某日類股排名（由強到弱）
     * 指定日期無資料時自動 fallback 到最近的交易日
     */
    public static function getRankForIndustry(string $date, string $industry): ?int
    {
        $effectiveDate = static::latestDateOn($date) ?? $date;

        $sectors = static::where('date', $effectiveDate)
            ->orderByDesc('change_percent')
            ->pluck('sector_name')
            ->values();

        $index = $sectors->search($industry);

        return $index !== false ? $index + 1 : null;
    }
}

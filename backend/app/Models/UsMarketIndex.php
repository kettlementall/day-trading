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
     * 取得指定日期的市場摘要（供 AI prompt 注入）
     */
    public static function getSummary(string $date): string
    {
        $indices = static::where('date', $date)->get();

        if ($indices->isEmpty()) {
            return '';
        }

        $tx = $indices->firstWhere('symbol', 'TX');
        $others = $indices->where('symbol', '!=', 'TX');

        $lines = [];

        if ($tx) {
            $sign = $tx->change_percent >= 0 ? '+' : '';
            $lines[] = "**台指期夜盤 {$sign}{$tx->change_percent}%**（重要參考：直接反映隔夜國際情勢對台股開盤影響，AI 選股與校準應優先參考此指標）";
        }

        $otherLines = $others->map(function ($i) {
            $sign = $i->change_percent >= 0 ? '+' : '';
            return "{$i->name} {$sign}{$i->change_percent}%";
        });

        if ($otherLines->isNotEmpty()) {
            $lines[] = $otherLines->implode(' | ');
        }

        return "## 昨夜市場收盤\n" . implode("\n", $lines);
    }
}

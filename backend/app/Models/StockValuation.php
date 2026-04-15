<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockValuation extends Model
{
    protected $fillable = [
        'stock_id', 'date', 'pe_ratio', 'pb_ratio', 'dividend_yield', 'eps_ttm',
    ];

    protected $casts = [
        'date'           => 'date:Y-m-d',
        'pe_ratio'       => 'decimal:2',
        'pb_ratio'       => 'decimal:2',
        'dividend_yield' => 'decimal:2',
        'eps_ttm'        => 'decimal:2',
    ];

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /**
     * 取得最新估值摘要字串（供 AI prompt 使用）
     */
    public static function getSummaryForStock(int $stockId, string $beforeDate): string
    {
        $v = static::where('stock_id', $stockId)
            ->where('date', '<=', $beforeDate)
            ->orderByDesc('date')
            ->first();

        if (!$v) {
            return '（無估值資料）';
        }

        $parts = [];
        if ($v->pe_ratio !== null)       $parts[] = "本益比 {$v->pe_ratio}x";
        if ($v->pb_ratio !== null)       $parts[] = "淨值比 {$v->pb_ratio}x";
        if ($v->dividend_yield !== null) $parts[] = "殖利率 {$v->dividend_yield}%";
        if ($v->eps_ttm !== null)        $parts[] = "EPS(TTM) {$v->eps_ttm}";

        return implode('　', $parts) ?: '（無估值資料）';
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DividendEvent extends Model
{
    protected $fillable = [
        'stock_id', 'ex_date', 'type',
        'cash_dividend', 'stock_ratio', 'cash_capital_ratio',
        'cash_capital_price', 'reference_price',
    ];

    protected $casts = [
        'ex_date' => 'date:Y-m-d',
        'cash_dividend' => 'decimal:8',
        'stock_ratio' => 'decimal:8',
        'cash_capital_ratio' => 'decimal:8',
    ];

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /**
     * 給定「前一交易日收盤價」與該日是否為除息日，回傳除權息參考價（平盤基準）。
     *
     * 台股除權息參考價公式（簡化、與 TWSE 試算一致到小數）：
     *   參考價 = (前收 − 現金股利 + 現金增資認購價 × 現金增資配股率)
     *            ÷ (1 + 無償配股率 + 現金增資配股率)
     *
     * 純配息（最常見）時退化為：前收 − 現金股利。
     */
    public function referenceCloseFrom(float $prevClose): float
    {
        $stock = (float) $this->stock_ratio;
        $cashCap = (float) $this->cash_capital_ratio;
        $cashCapPrice = (float) ($this->cash_capital_price ?? 0);
        $cash = (float) $this->cash_dividend;

        $denominator = 1 + $stock + $cashCap;
        if ($denominator <= 0) {
            return $prevClose;
        }

        return round(($prevClose - $cash + $cashCapPrice * $cashCap) / $denominator, 2);
    }

    /**
     * 找出指定股票在 $exDate 當天的除權息事件（若有）。
     */
    public static function onDate(int $stockId, string $exDate): ?self
    {
        return static::where('stock_id', $stockId)->whereDate('ex_date', $exDate)->first();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntradayQuote extends Model
{
    protected $fillable = [
        'stock_id', 'date', 'open', 'high', 'low', 'current_price', 'prev_close',
        'accumulated_volume', 'yesterday_volume', 'estimated_volume_ratio',
        'open_change_percent', 'first_5min_high', 'first_5min_low',
        'buy_volume', 'sell_volume', 'external_ratio', 'snapshot_at',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'open' => 'decimal:2',
        'high' => 'decimal:2',
        'low' => 'decimal:2',
        'current_price' => 'decimal:2',
        'prev_close' => 'decimal:2',
        'estimated_volume_ratio' => 'decimal:2',
        'open_change_percent' => 'decimal:2',
        'first_5min_high' => 'decimal:2',
        'first_5min_low' => 'decimal:2',
        'external_ratio' => 'decimal:2',
        'snapshot_at' => 'datetime',
    ];

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntradaySnapshot extends Model
{
    protected $fillable = [
        'stock_id', 'trade_date', 'snapshot_time',
        'open', 'high', 'low', 'current_price', 'prev_close',
        'accumulated_volume', 'estimated_volume_ratio', 'open_change_percent',
        'buy_volume', 'sell_volume', 'external_ratio',
        'best_ask', 'best_bid',
        'change_percent', 'amplitude_percent',
    ];

    protected $casts = [
        'trade_date' => 'date:Y-m-d',
        'snapshot_time' => 'datetime',
        'open' => 'decimal:2',
        'high' => 'decimal:2',
        'low' => 'decimal:2',
        'current_price' => 'decimal:2',
        'prev_close' => 'decimal:2',
        'estimated_volume_ratio' => 'decimal:2',
        'open_change_percent' => 'decimal:2',
        'external_ratio' => 'decimal:2',
        'best_ask' => 'decimal:2',
        'best_bid' => 'decimal:2',
        'change_percent' => 'decimal:2',
        'amplitude_percent' => 'decimal:2',
    ];

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}

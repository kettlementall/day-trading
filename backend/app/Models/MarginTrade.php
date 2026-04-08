<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarginTrade extends Model
{
    protected $fillable = [
        'stock_id', 'date',
        'margin_buy', 'margin_sell', 'margin_balance', 'margin_change',
        'short_buy', 'short_sell', 'short_balance', 'short_change',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}

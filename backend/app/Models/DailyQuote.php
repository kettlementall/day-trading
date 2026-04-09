<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyQuote extends Model
{
    protected $fillable = [
        'stock_id', 'date', 'open', 'high', 'low', 'close',
        'volume', 'trade_value', 'trade_count',
        'change', 'change_percent', 'amplitude',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'open' => 'decimal:2',
        'high' => 'decimal:2',
        'low' => 'decimal:2',
        'close' => 'decimal:2',
        'change' => 'decimal:2',
        'change_percent' => 'decimal:2',
        'amplitude' => 'decimal:2',
    ];

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}

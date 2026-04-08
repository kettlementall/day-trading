<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstitutionalTrade extends Model
{
    protected $fillable = [
        'stock_id', 'date',
        'foreign_buy', 'foreign_sell', 'foreign_net',
        'trust_buy', 'trust_sell', 'trust_net',
        'dealer_buy', 'dealer_sell', 'dealer_net',
        'total_net',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SwingPositionSnapshot extends Model
{
    protected $fillable = [
        'swing_position_id',
        'date',
        'close',
        'unrealized_profit_percent',
        'current_stop',
        'current_target',
        'holding_days',
        'advice',
        'thesis_status',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'close' => 'decimal:2',
        'unrealized_profit_percent' => 'decimal:2',
        'current_stop' => 'decimal:2',
        'current_target' => 'decimal:2',
        'holding_days' => 'integer',
        'advice' => 'array',
        'thesis_status' => 'array',
    ];

    public function position(): BelongsTo
    {
        return $this->belongsTo(SwingPosition::class, 'swing_position_id');
    }
}

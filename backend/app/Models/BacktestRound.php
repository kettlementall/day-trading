<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BacktestRound extends Model
{
    protected $fillable = [
        'analyzed_from', 'analyzed_to', 'sample_count',
        'metrics_before', 'metrics_after', 'suggestions',
        'applied', 'applied_at',
    ];

    protected $casts = [
        'analyzed_from' => 'date:Y-m-d',
        'analyzed_to' => 'date:Y-m-d',
        'metrics_before' => 'array',
        'metrics_after' => 'array',
        'suggestions' => 'array',
        'applied' => 'boolean',
        'applied_at' => 'datetime',
    ];
}

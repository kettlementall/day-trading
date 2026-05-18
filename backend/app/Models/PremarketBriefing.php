<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PremarketBriefing extends Model
{
    protected $fillable = [
        'trade_date',
        'market_context',
        'direction',
        'headline',
        'drivers',
        'focus_sectors',
        'cautions',
        'raw_markdown',
        'input_payload',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'cost_usd',
    ];

    protected $casts = [
        'trade_date'    => 'date:Y-m-d',
        'drivers'       => 'array',
        'focus_sectors' => 'array',
        'cautions'      => 'array',
        'input_payload' => 'array',
        'cost_usd'      => 'decimal:4',
    ];
}

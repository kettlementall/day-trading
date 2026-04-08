<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewsIndex extends Model
{
    protected $fillable = [
        'date', 'scope', 'scope_value',
        'sentiment', 'heatmap', 'panic', 'international',
        'article_count',
    ];

    protected $casts = [
        'date' => 'date',
        'sentiment' => 'decimal:2',
        'heatmap' => 'decimal:2',
        'panic' => 'decimal:2',
        'international' => 'decimal:2',
    ];
}

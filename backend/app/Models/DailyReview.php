<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyReview extends Model
{
    protected $fillable = [
        'trade_date', 'mode', 'candidates_count', 'report',
    ];

    protected $casts = [
        'trade_date' => 'date:Y-m-d',
    ];
}

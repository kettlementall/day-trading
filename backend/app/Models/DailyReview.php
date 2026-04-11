<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyReview extends Model
{
    protected $fillable = [
        'trade_date', 'candidates_count', 'report',
    ];

    protected $casts = [
        'trade_date' => 'date:Y-m-d',
    ];
}

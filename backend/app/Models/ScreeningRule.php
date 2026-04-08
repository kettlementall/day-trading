<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScreeningRule extends Model
{
    protected $fillable = [
        'name', 'conditions', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'conditions' => 'array',
        'is_active' => 'boolean',
    ];
}

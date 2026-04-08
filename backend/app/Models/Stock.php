<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stock extends Model
{
    protected $fillable = [
        'symbol', 'name', 'industry', 'market', 'is_day_trading',
    ];

    protected $casts = [
        'is_day_trading' => 'boolean',
    ];

    public function dailyQuotes(): HasMany
    {
        return $this->hasMany(DailyQuote::class);
    }

    public function institutionalTrades(): HasMany
    {
        return $this->hasMany(InstitutionalTrade::class);
    }

    public function marginTrades(): HasMany
    {
        return $this->hasMany(MarginTrade::class);
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class);
    }
}

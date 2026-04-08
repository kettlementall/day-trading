<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Candidate extends Model
{
    protected $fillable = [
        'stock_id', 'trade_date', 'suggested_buy', 'target_price',
        'stop_loss', 'risk_reward_ratio', 'score', 'strategy_type', 'strategy_detail',
        'reasons', 'indicators',
        'morning_score', 'morning_signals', 'morning_confirmed',
    ];

    protected $casts = [
        'trade_date' => 'date',
        'suggested_buy' => 'decimal:2',
        'target_price' => 'decimal:2',
        'stop_loss' => 'decimal:2',
        'risk_reward_ratio' => 'decimal:2',
        'score' => 'decimal:2',
        'reasons' => 'array',
        'indicators' => 'array',
        'strategy_detail' => 'array',
        'morning_score' => 'decimal:2',
        'morning_signals' => 'array',
        'morning_confirmed' => 'boolean',
    ];

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function result(): HasOne
    {
        return $this->hasOne(CandidateResult::class);
    }
}

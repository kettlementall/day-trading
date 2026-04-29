<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Candidate extends Model
{
    protected $fillable = [
        'stock_id', 'trade_date', 'mode', 'source', 'intraday_added_at',
        'suggested_buy', 'target_price', 'stop_loss', 'risk_reward_ratio',
        'score', 'strategy_type', 'strategy_detail', 'reasons', 'indicators',
        'haiku_selected', 'haiku_reasoning',
        'morning_score', 'morning_signals', 'morning_confirmed', 'morning_grade',
        'ai_selected', 'ai_score_adjustment', 'ai_reasoning', 'ai_price_reasoning',
        'intraday_strategy', 'reference_support', 'reference_resistance', 'ai_warnings',
        'overnight_strategy', 'overnight_reasoning', 'overnight_news_reason', 'overnight_fundamental_reason',
        'gap_potential_percent', 'overnight_key_levels',
    ];

    protected $casts = [
        'trade_date' => 'date:Y-m-d',
        'intraday_added_at' => 'datetime',
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
        'haiku_selected' => 'boolean',
        'ai_selected' => 'boolean',
        'ai_reasoning' => 'string',
        'intraday_strategy' => 'string',
        'reference_support' => 'decimal:2',
        'reference_resistance' => 'decimal:2',
        'ai_warnings' => 'array',
        'gap_potential_percent' => 'decimal:2',
        'overnight_key_levels' => 'array',
    ];

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function result(): HasOne
    {
        return $this->hasOne(CandidateResult::class);
    }

    public function monitor(): HasOne
    {
        return $this->hasOne(CandidateMonitor::class);
    }
}

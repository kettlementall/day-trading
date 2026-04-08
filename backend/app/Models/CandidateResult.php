<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateResult extends Model
{
    protected $fillable = [
        'candidate_id', 'actual_open', 'actual_high', 'actual_low', 'actual_close',
        'hit_target', 'hit_stop_loss', 'max_profit_percent', 'max_loss_percent',
        'buy_reachable', 'target_reachable', 'buy_gap_percent', 'target_gap_percent',
    ];

    protected $casts = [
        'actual_open' => 'decimal:2',
        'actual_high' => 'decimal:2',
        'actual_low' => 'decimal:2',
        'actual_close' => 'decimal:2',
        'hit_target' => 'boolean',
        'hit_stop_loss' => 'boolean',
        'max_profit_percent' => 'decimal:2',
        'max_loss_percent' => 'decimal:2',
        'buy_reachable' => 'boolean',
        'target_reachable' => 'boolean',
        'buy_gap_percent' => 'decimal:2',
        'target_gap_percent' => 'decimal:2',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }
}

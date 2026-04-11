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
        'entry_time', 'exit_time', 'entry_price_actual', 'exit_price_actual',
        'entry_type',
        'mfe_percent', 'mae_percent', 'valid_entry', 'monitor_status',
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
        'entry_time' => 'datetime',
        'exit_time' => 'datetime',
        'entry_price_actual' => 'decimal:2',
        'exit_price_actual' => 'decimal:2',
        'mfe_percent' => 'decimal:2',
        'mae_percent' => 'decimal:2',
        'valid_entry' => 'boolean',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }
}

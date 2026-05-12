<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SwingPosition extends Model
{
    public const STATUS_WATCHING = 'watching';
    public const STATUS_HOLDING = 'holding';
    public const STATUS_EXIT_SUGGESTED = 'exit_suggested';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_STOPPED = 'stopped';

    public const ACTIVE_STATUSES = [
        self::STATUS_WATCHING,
        self::STATUS_HOLDING,
        self::STATUS_EXIT_SUGGESTED,
    ];

    protected $fillable = [
        'user_id',
        'candidate_id',
        'stock_id',
        'status',
        'entry_price',
        'shares',
        'entry_date',
        'current_stop',
        'current_target',
        'max_holding_days',
        'exit_price',
        'exit_date',
        'exit_reason',
        'exit_note',
        'latest_advice',
        'advice_log',
    ];

    public const EXIT_REASONS = [
        'target_hit',
        'stop_hit',
        'take_profit_manual',
        'cut_loss_manual',
        'thesis_broken',
        'time_stop',
        'switch_position',
        'other',
    ];

    protected $casts = [
        'entry_price' => 'decimal:2',
        'current_stop' => 'decimal:2',
        'current_target' => 'decimal:2',
        'exit_price' => 'decimal:2',
        'shares' => 'integer',
        'entry_date' => 'date:Y-m-d',
        'exit_date' => 'date:Y-m-d',
        'max_holding_days' => 'integer',
        'latest_advice' => 'array',
        'advice_log' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(SwingPositionSnapshot::class);
    }

    public function appendAdvice(array $advice): void
    {
        $log = $this->advice_log ?? [];
        $log[] = array_merge(['time' => now()->toDateTimeString()], $advice);
        $this->advice_log = $log;
        $this->latest_advice = $advice;
    }
}

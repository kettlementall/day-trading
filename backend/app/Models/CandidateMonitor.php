<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateMonitor extends Model
{
    // 狀態常數
    const STATUS_PENDING = 'pending';
    const STATUS_WATCHING = 'watching';
    const STATUS_ENTRY_SIGNAL = 'entry_signal';
    const STATUS_HOLDING = 'holding';
    const STATUS_TARGET_HIT = 'target_hit';
    const STATUS_STOP_HIT = 'stop_hit';
    const STATUS_TRAILING_STOP = 'trailing_stop';
    const STATUS_CLOSED = 'closed';
    const STATUS_SKIPPED = 'skipped';

    // 終態（不可再轉換）
    const TERMINAL_STATUSES = [
        self::STATUS_TARGET_HIT,
        self::STATUS_STOP_HIT,
        self::STATUS_TRAILING_STOP,
        self::STATUS_CLOSED,
        self::STATUS_SKIPPED,
    ];

    // 活躍狀態（需要持續監控）
    const ACTIVE_STATUSES = [
        self::STATUS_WATCHING,
        self::STATUS_ENTRY_SIGNAL,
        self::STATUS_HOLDING,
    ];

    protected $fillable = [
        'candidate_id', 'status',
        'entry_price', 'entry_time', 'entry_type', 'exit_price', 'exit_time',
        'current_target', 'current_stop',
        'ai_calibration', 'ai_advice_log', 'state_log',
        'last_ai_advice_at', 'skip_reason',
    ];

    protected $casts = [
        'entry_price' => 'decimal:2',
        'exit_price' => 'decimal:2',
        'current_target' => 'decimal:2',
        'current_stop' => 'decimal:2',
        'entry_time' => 'datetime',
        'exit_time' => 'datetime',
        'last_ai_advice_at' => 'datetime',
        'ai_calibration' => 'array',
        'ai_advice_log' => 'array',
        'state_log' => 'array',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /**
     * 是否為終態
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES);
    }

    /**
     * 是否為活躍狀態（需要監控）
     */
    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES);
    }

    /**
     * 記錄狀態轉換
     */
    public function logTransition(string $fromStatus, string $toStatus, string $reason): void
    {
        $log = $this->state_log ?? [];
        $log[] = [
            'time' => now()->format('H:i:s'),
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'reason' => $reason,
        ];
        $this->state_log = $log;
    }

    /**
     * 記錄 AI 建議
     */
    public function logAiAdvice(string $action, string $notes, ?array $adjustments = null): void
    {
        $log = $this->ai_advice_log ?? [];
        $log[] = [
            'time' => now()->format('H:i:s'),
            'action' => $action,
            'notes' => $notes,
            'adjustments' => $adjustments,
        ];
        $this->ai_advice_log = $log;
        $this->last_ai_advice_at = now();
    }
}

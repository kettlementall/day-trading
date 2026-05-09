<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThesisStockLink extends Model
{
    protected $fillable = [
        'investment_thesis_id',
        'stock_id',
        'relevance_score',
        'evidence',
    ];

    protected $casts = [
        'relevance_score' => 'integer',
        'evidence' => 'array',
    ];

    public function thesis(): BelongsTo
    {
        return $this->belongsTo(InvestmentThesis::class, 'investment_thesis_id');
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}

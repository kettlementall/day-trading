<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvestmentThesis extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'title',
        'description',
        'industry_chain',
        'beneficiary_industries',
        'beneficiary_keywords',
        'evidence_summary',
        'risk_factors',
        'sentiment_divergence',
        'research_date',
        'confidence_score',
        'status',
        'last_evaluated_at',
    ];

    protected $casts = [
        'industry_chain' => 'array',
        'beneficiary_industries' => 'array',
        'beneficiary_keywords' => 'array',
        'risk_factors' => 'array',
        'research_date' => 'date:Y-m-d',
        'confidence_score' => 'integer',
        'last_evaluated_at' => 'datetime',
    ];

    public function stockLinks(): HasMany
    {
        return $this->hasMany(ThesisStockLink::class);
    }
}

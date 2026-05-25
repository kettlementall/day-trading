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
        'related_stocks',
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
        'related_stocks' => 'array',
        'risk_factors' => 'array',
        'research_date' => 'date:Y-m-d',
        'confidence_score' => 'integer',
        'last_evaluated_at' => 'datetime',
    ];

    public function stockLinks(): HasMany
    {
        return $this->hasMany(ThesisStockLink::class);
    }

    /**
     * 從 candidate.swing_thesis 快照解析回權威論點：thesis_id 優先、title 為 fallback。
     *
     * thesis_id 是穩定鍵，論點改名也撈得到，避免持倉複查誤判失效；
     * title fallback 相容沒有 thesis_id 的舊快照，無需回填歷史資料。
     */
    public static function resolveFromSnapshot(?array $snapshot): ?self
    {
        if (empty($snapshot)) {
            return null;
        }

        if (!empty($snapshot['thesis_id']) && ($thesis = static::find($snapshot['thesis_id']))) {
            return $thesis;
        }

        $title = $snapshot['title'] ?? null;

        return $title ? static::where('title', $title)->first() : null;
    }
}

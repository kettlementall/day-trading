<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewsArticle extends Model
{
    protected $fillable = [
        'source', 'title', 'summary', 'content', 'content_fetched_at', 'url', 'category', 'industry',
        'sentiment_score', 'sentiment_label', 'ai_analysis',
        'fetched_date', 'published_at',
    ];

    protected $casts = [
        'fetched_date' => 'date:Y-m-d',
        'published_at' => 'datetime',
        'content_fetched_at' => 'datetime',
        'sentiment_score' => 'decimal:2',
        'ai_analysis' => 'array',
    ];
}

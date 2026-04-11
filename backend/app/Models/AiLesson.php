<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiLesson extends Model
{
    protected $fillable = [
        'trade_date', 'type', 'category', 'content', 'expires_at',
    ];

    protected $casts = [
        'trade_date' => 'date:Y-m-d',
        'expires_at' => 'date:Y-m-d',
    ];

    /**
     * 取得未過期的教訓
     */
    public function scopeActive($query)
    {
        return $query->where('expires_at', '>=', now()->toDateString());
    }

    /**
     * 依類型篩選
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * 取得適用於選股的教訓（screening + market）
     */
    public static function getScreeningLessons(int $limit = 20): string
    {
        $lessons = static::active()
            ->whereIn('type', ['screening', 'market', 'entry'])
            ->orderByDesc('trade_date')
            ->limit($limit)
            ->get();

        if ($lessons->isEmpty()) {
            return '';
        }

        $lines = $lessons->map(fn($l) => "- [{$l->trade_date->format('m/d')}][{$l->type}] {$l->content}");

        return "## 近期教訓（從每日檢討萃取）\n" . $lines->implode("\n");
    }

    /**
     * 取得適用於盤中的教訓（calibration + entry + exit）
     */
    public static function getIntradayLessons(int $limit = 15): string
    {
        $lessons = static::active()
            ->whereIn('type', ['calibration', 'entry', 'exit', 'market'])
            ->orderByDesc('trade_date')
            ->limit($limit)
            ->get();

        if ($lessons->isEmpty()) {
            return '';
        }

        $lines = $lessons->map(fn($l) => "- [{$l->trade_date->format('m/d')}][{$l->type}] {$l->content}");

        return "## 近期教訓\n" . $lines->implode("\n");
    }
}

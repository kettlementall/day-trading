<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormulaSetting extends Model
{
    protected $fillable = ['type', 'config'];

    protected $casts = [
        'config' => 'array',
    ];

    public static function getConfig(string $type): array
    {
        $setting = static::where('type', $type)->first();
        return $setting?->config ?? [];
    }
}

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

    /**
     * 系統級功能總開關。type='system_toggles' 內以 "{key}_enabled" → bool 儲存。
     * 沒設定時回傳 $default（向後相容：預設都開）。
     * 範例：FormulaSetting::isFeatureEnabled('intraday_monitor')
     *  → 對應 config 鍵 'intraday_monitor_enabled'
     */
    public static function isFeatureEnabled(string $key, bool $default = true): bool
    {
        $config = static::getConfig('system_toggles');
        return (bool) ($config["{$key}_enabled"] ?? $default);
    }
}

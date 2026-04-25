<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = ['user_id', 'name', 'email', 'password', 'role', 'telegram_chat_id', 'telegram_enabled', 'intraday_monitor_enabled'];

    protected $hidden = ['password'];

    protected $casts = [
        'password' => 'hashed',
        'telegram_enabled' => 'boolean',
        'intraday_monitor_enabled' => 'boolean',
    ];

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}

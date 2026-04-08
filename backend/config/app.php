<?php

return [
    'name' => env('APP_NAME', 'Day Trading Screener'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => 'Asia/Taipei',
    'locale' => 'zh_TW',
    'fallback_locale' => 'en',
    'faker_locale' => 'zh_TW',
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'maintenance' => [
        'driver' => 'file',
    ],
];

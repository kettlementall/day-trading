<?php

return [
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY', ''),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-4-6'),
        'screening_model' => env('ANTHROPIC_SCREENING_MODEL', 'claude-opus-4-6'),
        'intraday_model' => env('ANTHROPIC_INTRADAY_MODEL', 'claude-sonnet-4-6'),
        'overnight_model' => env('ANTHROPIC_OVERNIGHT_MODEL', 'claude-sonnet-4-6'),
        'sentiment_model' => env('ANTHROPIC_SENTIMENT_MODEL', 'claude-haiku-4-5-20251001'),
    ],
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
        'chat_id' => env('TELEGRAM_CHAT_ID', ''),
    ],
    'fugle' => [
        'api_key' => env('FUGLE_API_KEY', ''),
        'api_key_backup' => env('FUGLE_API_KEY_BACKUP', ''),
    ],
];

<?php

return [
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY', ''),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-4-6'),
        'screening_model' => env('ANTHROPIC_SCREENING_MODEL', 'claude-opus-4-6'),
        'intraday_model' => env('ANTHROPIC_INTRADAY_MODEL', 'claude-sonnet-4-6'),
        'intraday_calibration_batch_size' => env('ANTHROPIC_INTRADAY_CALIBRATION_BATCH_SIZE', 6),
        'intraday_calibration_timeout' => env('ANTHROPIC_INTRADAY_CALIBRATION_TIMEOUT', 75),
        'intraday_calibration_max_tokens' => env('ANTHROPIC_INTRADAY_CALIBRATION_MAX_TOKENS', 4096),
        'overnight_model' => env('ANTHROPIC_OVERNIGHT_MODEL', 'claude-sonnet-4-6'),
        'sentiment_model' => env('ANTHROPIC_SENTIMENT_MODEL', 'claude-haiku-4-5-20251001'),
        // 當沖規則層進場前的 AI 即時確認（second opinion，輕量任務用 Haiku）
        'entry_confirm_model' => env('ANTHROPIC_ENTRY_CONFIRM_MODEL', 'claude-haiku-4-5-20251001'),
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

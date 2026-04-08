<?php

return [
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY', ''),
        'model' => env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),
    ],
];

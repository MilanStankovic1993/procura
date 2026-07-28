<?php

return [
    'matcher_version' => 'saved-search-matcher:v1',
    'saved_search_chunk_size' => 100,
    'listing_chunk_size' => 100,
    'inbox_page_size' => 20,
    'maximum_keywords_per_kind' => 20,
    'email_delivery' => [
        'queue' => env('MONITORING_EMAIL_QUEUE', 'notifications'),
        'provider' => env('MONITORING_EMAIL_PROVIDER', 'laravel-mail'),
        'tries' => 4,
        'timeout_seconds' => 30,
        'retry_delays_seconds' => [60, 300, 900],
        'attempt_stale_after_seconds' => 300,
        'recovery_after_seconds' => 120,
        'recovery_chunk_size' => 100,
    ],
    'telegram' => [
        'provider' => env(
            'MONITORING_TELEGRAM_PROVIDER',
            'telegram-bot-api',
        ),
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'identity_hash_key' => env('TELEGRAM_IDENTITY_HASH_KEY'),
        'webhook_url' => env(
            'TELEGRAM_WEBHOOK_URL',
            rtrim((string) env('APP_URL'), '/')
                .'/api/v1/integrations/telegram/webhook',
        ),
        'connection_challenge_ttl_minutes' => 15,
        'delivery' => [
            'queue' => env('MONITORING_TELEGRAM_QUEUE', 'notifications'),
            'tries' => 4,
            'timeout_seconds' => 20,
            'retry_delays_seconds' => [30, 120, 600],
            'attempt_stale_after_seconds' => 300,
            'recovery_after_seconds' => 120,
            'recovery_chunk_size' => 100,
        ],
    ],
];

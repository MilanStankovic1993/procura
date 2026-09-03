<?php

return [
    'provider' => env('ANALYSIS_PROVIDER', 'fake'),
    'submission_enabled' => (bool) env(
        'ANALYSIS_SUBMISSION_ENABLED',
        false,
    ),
    'queue' => env('ANALYSIS_QUEUE', 'analyses'),
    'manual_retry_enabled' => (bool) env(
        'ANALYSIS_MANUAL_RETRY_ENABLED',
        false,
    ),
    'pipeline_version' => 'buy-analysis-pipeline:v8',
    'prompt_version' => 'buy-analysis-extraction:v1',
    'fake_model' => 'deterministic-fixture-v1',
    'provider_governance' => [
        'task_max_cost_minor' => (int) env(
            'ANALYSIS_AI_TASK_MAX_COST_MINOR',
            10,
        ),
        'global_monthly_budget_minor' => (int) env(
            'ANALYSIS_AI_GLOBAL_MONTHLY_BUDGET_MINOR',
            100_000,
        ),
        'organization_monthly_budget_minor' => (int) env(
            'ANALYSIS_AI_ORGANIZATION_MONTHLY_BUDGET_MINOR',
            10_000,
        ),
        'user_monthly_budget_minor' => (int) env(
            'ANALYSIS_AI_USER_MONTHLY_BUDGET_MINOR',
            2_500,
        ),
        'circuit_failure_threshold' => (int) env(
            'ANALYSIS_AI_CIRCUIT_FAILURE_THRESHOLD',
            5,
        ),
        'circuit_cooldown_seconds' => (int) env(
            'ANALYSIS_AI_CIRCUIT_COOLDOWN_SECONDS',
            300,
        ),
    ],
    'provider_monitoring' => [
        'enabled' => (bool) env('ANALYSIS_AI_MONITORING_ENABLED', false),
        'stale_reservation_minutes' => (int) env(
            'ANALYSIS_AI_MONITOR_STALE_RESERVATION_MINUTES',
            15,
        ),
        'recent_window_minutes' => (int) env(
            'ANALYSIS_AI_MONITOR_RECENT_WINDOW_MINUTES',
            60,
        ),
        'uncertain_outcome_limit' => (int) env(
            'ANALYSIS_AI_MONITOR_UNCERTAIN_OUTCOME_LIMIT',
            5,
        ),
        'rate_limit_limit' => (int) env(
            'ANALYSIS_AI_MONITOR_RATE_LIMIT_LIMIT',
            1,
        ),
        'server_error_limit' => (int) env(
            'ANALYSIS_AI_MONITOR_SERVER_ERROR_LIMIT',
            3,
        ),
        'budget_utilization_basis_points' => (int) env(
            'ANALYSIS_AI_MONITOR_BUDGET_UTILIZATION_BPS',
            8_000,
        ),
    ],
    'providers' => [
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => env(
                'GEMINI_API_BASE_URL',
                'https://generativelanguage.googleapis.com/v1beta',
            ),
            'allowed_hosts' => ['generativelanguage.googleapis.com'],
            'model' => env('GEMINI_ANALYSIS_MODEL', 'gemini-3.7-flash'),
            'connect_timeout_seconds' => (int) env(
                'GEMINI_ANALYSIS_CONNECT_TIMEOUT_SECONDS',
                10,
            ),
            'timeout_seconds' => (int) env(
                'GEMINI_ANALYSIS_TIMEOUT_SECONDS',
                60,
            ),
            'max_output_tokens' => (int) env(
                'GEMINI_ANALYSIS_MAX_OUTPUT_TOKENS',
                1200,
            ),
            'input_price_usd_per_million' => env(
                'GEMINI_ANALYSIS_INPUT_PRICE_USD_PER_MILLION',
                '0',
            ),
            'output_price_usd_per_million' => env(
                'GEMINI_ANALYSIS_OUTPUT_PRICE_USD_PER_MILLION',
                '0',
            ),
        ],
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env(
                'OPENAI_API_BASE_URL',
                'https://api.openai.com/v1',
            ),
            'allowed_hosts' => ['api.openai.com'],
            'model' => env('OPENAI_ANALYSIS_MODEL', 'gpt-5.6-luna'),
            'connect_timeout_seconds' => (int) env(
                'OPENAI_ANALYSIS_CONNECT_TIMEOUT_SECONDS',
                10,
            ),
            'timeout_seconds' => (int) env(
                'OPENAI_ANALYSIS_TIMEOUT_SECONDS',
                60,
            ),
            'max_output_tokens' => (int) env(
                'OPENAI_ANALYSIS_MAX_OUTPUT_TOKENS',
                1200,
            ),
            'input_price_usd_per_million' => env(
                'OPENAI_ANALYSIS_INPUT_PRICE_USD_PER_MILLION',
                '0.10',
            ),
            'output_price_usd_per_million' => env(
                'OPENAI_ANALYSIS_OUTPUT_PRICE_USD_PER_MILLION',
                '0.60',
            ),
        ],
    ],
    'max_processing_attempts' => 3,
    'manual_retry_attempts' => (int) env(
        'ANALYSIS_MANUAL_RETRY_ATTEMPTS',
        3,
    ),
    'manual_retry_max_runs' => (int) env(
        'ANALYSIS_MANUAL_RETRY_MAX_RUNS',
        5,
    ),
    'dispatch_claim_timeout_seconds' => 300,
    'processing_timeout_seconds' => 600,
    'retry_delays_seconds' => [10, 60, 300],
];

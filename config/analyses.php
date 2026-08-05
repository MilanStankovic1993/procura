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

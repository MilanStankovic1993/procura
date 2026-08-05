<?php

return [
    'requests_enabled' => (bool) env('BROKER_REQUESTS_ENABLED', true),
    'offers_enabled' => (bool) env('BROKER_OFFERS_ENABLED', false),
    'transactions_enabled' => (bool) env(
        'BROKER_TRANSACTIONS_ENABLED',
        false,
    ),
    'payment_cases_enabled' => (bool) env(
        'BROKER_PAYMENT_CASES_ENABLED',
        false,
    ),
    'commission_rule_version' => env(
        'BROKER_COMMISSION_RULE_VERSION',
        'broker-commission:v1',
    ),
    'commission_rate_basis_points' => (int) env(
        'BROKER_COMMISSION_RATE_BASIS_POINTS',
        0,
    ),
    'reports_enabled' => (bool) env('BROKER_REPORTS_ENABLED', false),
    'report_version' => env(
        'BROKER_REPORT_VERSION',
        'broker-transaction-report:v1',
    ),
    'report_disk' => env('BROKER_REPORT_DISK', 'local'),
    'report_retention_days' => (int) env(
        'BROKER_REPORT_RETENTION_DAYS',
        30,
    ),
    'report_download_ttl_minutes' => (int) env(
        'BROKER_REPORT_DOWNLOAD_TTL_MINUTES',
        10,
    ),
    'report_max_bytes' => (int) env(
        'BROKER_REPORT_MAX_BYTES',
        5 * 1024 * 1024,
    ),
    'report_purge_batch' => (int) env('BROKER_REPORT_PURGE_BATCH', 100),
    'monitoring' => [
        'request_age_hours' => (int) env(
            'BROKER_MONITOR_REQUEST_AGE_HOURS',
            48,
        ),
        'offer_expiry_grace_hours' => (int) env(
            'BROKER_MONITOR_OFFER_EXPIRY_GRACE_HOURS',
            1,
        ),
        'transaction_age_hours' => (int) env(
            'BROKER_MONITOR_TRANSACTION_AGE_HOURS',
            24,
        ),
        'commission_age_hours' => (int) env(
            'BROKER_MONITOR_COMMISSION_AGE_HOURS',
            72,
        ),
        'report_purge_grace_hours' => (int) env(
            'BROKER_MONITOR_REPORT_PURGE_GRACE_HOURS',
            26,
        ),
        'payment_case_age_hours' => (int) env(
            'BROKER_MONITOR_PAYMENT_CASE_AGE_HOURS',
            48,
        ),
    ],
];

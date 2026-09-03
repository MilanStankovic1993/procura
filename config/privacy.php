<?php

return [
    'workflow_version' => env(
        'PRIVACY_WORKFLOW_VERSION',
        'privacy-request-workflow:v1',
    ),
    'privacy_notice_version' => env(
        'PRIVACY_NOTICE_VERSION',
        'privacy-notice:v1',
    ),
    'response_target_days' => (int) env(
        'PRIVACY_RESPONSE_TARGET_DAYS',
        30,
    ),
    'request_history_limit' => 25,
    'event_history_limit' => 50,
    'maximum_events_per_request' => 50,
    'fulfillment' => [
        'enabled' => (bool) env(
            'PRIVACY_FULFILLMENT_ENABLED',
            false,
        ),
        'execution_version' => env(
            'PRIVACY_FULFILLMENT_VERSION',
            'privacy-fulfillment:v1',
        ),
        'data_inventory_version' => env(
            'PRIVACY_DATA_INVENTORY_VERSION',
            'privacy-data-inventory:v4',
        ),
        'maximum_export_bytes' => (int) env(
            'PRIVACY_EXPORT_MAX_BYTES',
            104857600,
        ),
        'maximum_artifact_retention_days' => (int) env(
            'PRIVACY_EXPORT_MAX_RETENTION_DAYS',
            30,
        ),
    ],
    'erasure' => [
        'enabled' => (bool) env(
            'PRIVACY_ERASURE_ENABLED',
            false,
        ),
        'execution_version' => env(
            'PRIVACY_ERASURE_VERSION',
            'privacy-erasure:v1',
        ),
        'data_inventory_version' => env(
            'PRIVACY_ERASURE_INVENTORY_VERSION',
            'privacy-erasure-inventory:v4',
        ),
        'maximum_backup_retention_days' => (int) env(
            'PRIVACY_BACKUP_MAX_RETENTION_DAYS',
            90,
        ),
    ],
];

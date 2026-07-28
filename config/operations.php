<?php

$heartbeatQueues = array_values(array_filter(array_map(
    static fn (string $queue): string => trim($queue),
    explode(
        ',',
        (string) env(
            'OPERATIONS_QUEUE_HEARTBEAT_QUEUES',
            'analyses,connectors,notifications,default',
        ),
    ),
)));

return [
    'dashboard_metrics' => [
        'cache_store' => env('OPERATIONS_DASHBOARD_CACHE_STORE'),
        'cache_key' => 'operations:dashboard-metrics:v1',
        'ttl_seconds' => (int) env(
            'OPERATIONS_DASHBOARD_CACHE_TTL_SECONDS',
            30,
        ),
        'lock_seconds' => 10,
        'lock_wait_seconds' => 2,
    ],
    'readiness' => [
        'database_connection' => env(
            'OPERATIONS_READINESS_DATABASE_CONNECTION',
        ),
        'cache_store' => env('OPERATIONS_READINESS_CACHE_STORE'),
        'probe_ttl_seconds' => 15,
        'queue_heartbeats' => [
            'enabled' => (bool) env(
                'OPERATIONS_QUEUE_HEARTBEATS_ENABLED',
                false,
            ),
            'queues' => $heartbeatQueues,
            'maximum_age_seconds' => (int) env(
                'OPERATIONS_QUEUE_HEARTBEAT_MAX_AGE_SECONDS',
                180,
            ),
            'maximum_latency_seconds' => (int) env(
                'OPERATIONS_QUEUE_HEARTBEAT_MAX_LATENCY_SECONDS',
                120,
            ),
            'ttl_seconds' => (int) env(
                'OPERATIONS_QUEUE_HEARTBEAT_TTL_SECONDS',
                900,
            ),
            'cache_key_prefix' => 'operations:queue-heartbeat:v1',
            'lock_seconds' => 10,
            'lock_wait_seconds' => 3,
        ],
    ],
];

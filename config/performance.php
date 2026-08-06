<?php

return [
    'capacity_baseline' => [
        'tenant_page_size' => 50,
        'probes' => [
            'platform_dashboard_cold' => [
                'maximum_queries' => 12,
                'maximum_database_milliseconds' => 1000,
                'maximum_wall_milliseconds' => 1500,
            ],
            'analysis_operations_count' => [
                'maximum_queries' => 1,
                'maximum_database_milliseconds' => 500,
                'maximum_wall_milliseconds' => 750,
            ],
            'tenant_analysis_index' => [
                'maximum_queries' => 2,
                'maximum_database_milliseconds' => 500,
                'maximum_wall_milliseconds' => 750,
            ],
        ],
    ],
    'queue_throughput' => [
        'default_jobs' => 100,
        'maximum_jobs' => 5000,
        'default_timeout_seconds' => 60,
        'maximum_timeout_seconds' => 300,
        'receipt_ttl_seconds' => 900,
        'poll_interval_milliseconds' => 100,
        'cache_key_prefix' => 'performance:queue-throughput:v1',
        'budgets' => [
            'minimum_throughput_per_second' => 5.0,
            'maximum_p95_latency_milliseconds' => 15000,
            'maximum_p99_latency_milliseconds' => 30000,
        ],
    ],
    'analysis_pipeline_workload' => [
        'enabled' => (bool) env(
            'PERFORMANCE_ANALYSIS_WORKLOAD_ENABLED',
            false,
        ),
        'cache_store' => env(
            'PERFORMANCE_ANALYSIS_WORKLOAD_CACHE_STORE',
        ),
        'default_scenarios' => 20,
        'maximum_scenarios' => 250,
        'default_permit_ttl_seconds' => 900,
        'maximum_permit_ttl_seconds' => 3600,
        'lock_seconds' => 10,
        'lock_wait_seconds' => 3,
        'cache_key_prefix' => 'performance:analysis-pipeline-workload:v1',
        'header' => 'X-Procura-Analysis-Workload-Permit',
        'budgets' => [
            'version' => 'analysis-pipeline-workload-budget:v1',
            'minimum_throughput_per_second' => 0.25,
            'maximum_draft_p95_milliseconds' => 2000,
            'maximum_submit_p95_milliseconds' => 2000,
            'maximum_pipeline_p95_milliseconds' => 60000,
            'maximum_failure_rate_basis_points' => 0,
        ],
    ],
];

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
];

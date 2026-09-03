<?php

return [
    'csv_import_enabled' => (bool) env('MARKETPLACE_CSV_IMPORT_ENABLED', false),
    'import_disk' => env('MARKETPLACE_IMPORT_DISK', env('FILESYSTEM_DISK', 'local')),
    'queue' => env('MARKETPLACE_CONNECTOR_QUEUE', 'connectors'),
    'max_file_kilobytes' => (int) env('MARKETPLACE_IMPORT_MAX_FILE_KB', 5120),
    'max_rows' => (int) env('MARKETPLACE_IMPORT_MAX_ROWS', 10000),
    'processing_timeout_seconds' => (int) env(
        'MARKETPLACE_IMPORT_PROCESSING_TIMEOUT_SECONDS',
        900,
    ),
    'retry_delays_seconds' => [30, 120, 600],
];

<?php

return [
    'import_disk' => env('CATALOG_IMPORT_DISK', 'local'),
    'import_queue' => env('CATALOG_IMPORT_QUEUE', 'imports'),
    'max_rows' => (int) env('CATALOG_IMPORT_MAX_ROWS', 5000),
    'max_file_size_kb' => (int) env('CATALOG_IMPORT_MAX_FILE_SIZE_KB', 10240),
    'processing_timeout_seconds' => (int) env(
        'CATALOG_IMPORT_PROCESSING_TIMEOUT_SECONDS',
        1800,
    ),
];

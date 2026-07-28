<?php

return [
    'uploads' => [
        'disk' => env('OWNED_PRODUCT_UPLOAD_DISK', 'local'),
        'temporary_url_minutes' => 10,
        'max_files_per_request' => 10,
        'max_product_images' => 12,
        'max_serial_label_images' => 4,
        'max_defect_images' => 8,
        'max_proof_images' => 4,
        'max_size_kilobytes' => 10 * 1024,
        'min_width' => 200,
        'min_height' => 200,
        'max_width' => 8000,
        'max_height' => 8000,
    ],
];

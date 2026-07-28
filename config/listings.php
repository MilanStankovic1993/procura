<?php

return [
    'uploads' => [
        'disk' => env('LISTING_UPLOAD_DISK', 'local'),
        'temporary_url_minutes' => 10,
        'max_files_per_request' => 10,
        'max_product_images' => 10,
        'max_screenshots' => 5,
        'max_size_kilobytes' => 10 * 1024,
        'min_width' => 200,
        'min_height' => 200,
        'max_width' => 8000,
        'max_height' => 8000,
        'mime_types' => [
            'image/jpeg',
            'image/png',
            'image/webp',
        ],
    ],
];

<?php

return [
    'generator_version' => 'deterministic-sell-listing-generator:v1',
    'photo_evaluator_version' => 'deterministic-photo-readiness:v1',
    'supported_languages' => ['en', 'de', 'es', 'fr', 'sr-Latn'],
    'template_versions' => [
        'en' => 'sell-listing-template:en:v1',
        'de' => 'sell-listing-template:de:v1',
        'es' => 'sell-listing-template:es:v1',
        'fr' => 'sell-listing-template:fr:v1',
        'sr-Latn' => 'sell-listing-template:sr-Latn:v1',
    ],
    'history_limit' => 25,
    'title_max_length' => 180,
    'photo_readiness' => [
        'minimum_product_images' => 3,
        'minimum_serial_label_images' => 1,
        'minimum_short_edge_pixels' => 800,
        'minimum_long_edge_pixels' => 1200,
    ],
];

<?php

return [
    'algorithm_version' => 'deterministic-weighted-median:v2',
    'rate_resolver_version' => 'dated-exchange-rate-resolver:v1',
    'max_rate_age_hours' => 72,
    'max_inputs' => 20,
    'minimum_values' => 3,
    'outlier_minimum_values' => 5,
    'mad_multiplier' => 3,
    'high_dispersion_basis_points' => 5000,
    'low_confidence_basis_points' => 5000,
];

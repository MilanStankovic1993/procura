<?php

return [
    'selector_version' => 'deterministic-sell-comparable-selector:v2',
    'algorithm_version' => 'deterministic-sell-price-bands:v2',
    'normalization_calculation_version' => 'sell-comparable-market-normalization:v1',
    'max_candidates' => 100,
    'max_selected' => 20,
    'max_currency_scopes_per_market' => 10,
    'minimum_selected' => 3,
    'default_source_reliability_basis_points' => 4000,
    'outlier_minimum_values' => 5,
    'mad_multiplier' => 3,
    'high_dispersion_basis_points' => 5000,
    'low_confidence_basis_points' => 5000,
    'history_limit' => 25,
    'normalization_history_limit' => 10,
];

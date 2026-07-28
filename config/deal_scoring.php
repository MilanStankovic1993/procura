<?php

return [
    'calculation_version' => 'deterministic-deal-score:v1',
    'margin_floor_basis_points' => 0,
    'margin_ceiling_basis_points' => 4000,
    'maximum_absolute_margin_basis_points' => 10_000_000,
    'weights_basis_points' => [
        'estimated_net_margin' => 3500,
        'price_confidence' => 2500,
        'resale_demand' => 1500,
        'inverse_risk' => 1500,
        'logistics_simplicity' => 1000,
    ],
    'caps' => [
        'critical_risk' => 40,
        'low_price_confidence' => 60,
        'unknown_product_model' => 50,
    ],
    'recommendation_minimum_basis_points' => [
        'strong_opportunity' => 8000,
        'potential_opportunity' => 6500,
        'needs_verification' => 5000,
        'weak_opportunity' => 3000,
        'avoid' => 0,
    ],
    'strengthens_score_basis_points' => 6000,
    'reduces_score_basis_points' => 4000,
];

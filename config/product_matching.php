<?php

return [
    'provider' => env('PRODUCT_MATCHING_PROVIDER', 'fake'),
    'matcher_version' => 'catalog-alias-matcher:v2',
    'max_candidates' => 10,
    'max_alias_query_terms' => 250,
    'max_text_tokens' => 40,
    'max_ngram_tokens' => 6,
    'review_score_delta' => 1000,
    'automatic_match_minimum_score' => 4500,
];

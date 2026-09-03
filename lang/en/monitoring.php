<?php

return [
    'email' => [
        'saved_search_match' => [
            'subject' => 'New saved-search match: :listing',
            'greeting' => 'Hello :name,',
            'introduction' => 'A new opportunity matched your saved search “:search”.',
            'listing' => 'Listing: :listing',
            'asking_price' => 'Asking price: :price',
            'expected_profit' => 'Estimated net profit: :profit',
            'deal_score' => 'Deal score: :score/100',
            'risk_score' => 'Risk score: :score/100',
            'action' => 'Review listing',
            'evidence_boundary' => 'Procura only includes values backed by the current immutable listing and analysis evidence.',
            'unknown_listing' => 'Unknown listing',
            'unknown_search' => 'Saved search',
        ],
    ],
    'telegram' => [
        'saved_search_match' => [
            'title' => 'New potential deal',
            'search' => 'Saved search: :search',
            'listing' => 'Listing: :listing',
            'asking_price' => 'Asking price: :price',
            'expected_profit' => 'Estimated net profit: :profit',
            'deal_score' => 'Deal score: :score/100',
            'risk_score' => 'Risk score: :score/100',
            'action' => 'Review listing',
            'evidence_boundary' => 'Only current immutable evidence is included. Unknown values are omitted.',
            'unknown_listing' => 'Unknown listing',
            'unknown_search' => 'Saved search',
        ],
    ],
];

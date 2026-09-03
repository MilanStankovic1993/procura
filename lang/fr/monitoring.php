<?php

return [
    'email' => [
        'saved_search_match' => [
            'subject' => 'Nouvelle correspondance de recherche enregistrée : :listing',
            'greeting' => 'Bonjour :name,',
            'introduction' => 'Une nouvelle opportunité correspond à votre recherche enregistrée « :search ».',
            'listing' => 'Annonce : :listing',
            'asking_price' => 'Prix demandé : :price',
            'expected_profit' => 'Bénéfice net estimé : :profit',
            'deal_score' => 'Score de l’affaire : :score/100',
            'risk_score' => 'Score de risque : :score/100',
            'action' => 'Examiner l’annonce',
            'evidence_boundary' => 'Procura inclut uniquement les valeurs étayées par les preuves immuables actuelles de l’annonce et de l’analyse.',
            'unknown_listing' => 'Annonce inconnue',
            'unknown_search' => 'Recherche enregistrée',
        ],
    ],
    'telegram' => [
        'saved_search_match' => [
            'title' => 'Nouvelle opportunité potentielle',
            'search' => 'Recherche enregistrée : :search',
            'listing' => 'Annonce : :listing',
            'asking_price' => 'Prix demandé : :price',
            'expected_profit' => 'Bénéfice net estimé : :profit',
            'deal_score' => 'Score de l’opportunité : :score/100',
            'risk_score' => 'Score de risque : :score/100',
            'action' => 'Examiner l’annonce',
            'evidence_boundary' => 'Seules les preuves immuables actuelles sont incluses. Les valeurs inconnues sont omises.',
            'unknown_listing' => 'Annonce inconnue',
            'unknown_search' => 'Recherche enregistrée',
        ],
    ],
];

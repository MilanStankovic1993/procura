<?php

return [
    'email' => [
        'saved_search_match' => [
            'subject' => 'Neuer Treffer für gespeicherte Suche: :listing',
            'greeting' => 'Hallo :name,',
            'introduction' => 'Eine neue Gelegenheit entspricht Ihrer gespeicherten Suche „:search“.',
            'listing' => 'Angebot: :listing',
            'asking_price' => 'Angebotspreis: :price',
            'expected_profit' => 'Geschätzter Nettogewinn: :profit',
            'deal_score' => 'Deal-Score: :score/100',
            'risk_score' => 'Risiko-Score: :score/100',
            'action' => 'Angebot prüfen',
            'evidence_boundary' => 'Procura zeigt nur Werte an, die durch die aktuellen unveränderlichen Angebots- und Analysenachweise belegt sind.',
            'unknown_listing' => 'Unbekanntes Angebot',
            'unknown_search' => 'Gespeicherte Suche',
        ],
    ],
    'telegram' => [
        'saved_search_match' => [
            'title' => 'Neues potenzielles Angebot',
            'search' => 'Gespeicherte Suche: :search',
            'listing' => 'Inserat: :listing',
            'asking_price' => 'Angebotspreis: :price',
            'expected_profit' => 'Geschätzter Nettogewinn: :profit',
            'deal_score' => 'Angebotsbewertung: :score/100',
            'risk_score' => 'Risikobewertung: :score/100',
            'action' => 'Inserat prüfen',
            'evidence_boundary' => 'Enthalten sind nur aktuelle unveränderliche Nachweise. Unbekannte Werte werden ausgelassen.',
            'unknown_listing' => 'Unbekanntes Inserat',
            'unknown_search' => 'Gespeicherte Suche',
        ],
    ],
];

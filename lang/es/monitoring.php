<?php

return [
    'email' => [
        'saved_search_match' => [
            'subject' => 'Nueva coincidencia de búsqueda guardada: :listing',
            'greeting' => 'Hola, :name:',
            'introduction' => 'Una nueva oportunidad coincide con tu búsqueda guardada «:search».',
            'listing' => 'Anuncio: :listing',
            'asking_price' => 'Precio anunciado: :price',
            'expected_profit' => 'Beneficio neto estimado: :profit',
            'deal_score' => 'Puntuación de la operación: :score/100',
            'risk_score' => 'Puntuación de riesgo: :score/100',
            'action' => 'Revisar anuncio',
            'evidence_boundary' => 'Procura solo incluye valores respaldados por la evidencia inmutable actual del anuncio y del análisis.',
            'unknown_listing' => 'Anuncio desconocido',
            'unknown_search' => 'Búsqueda guardada',
        ],
    ],
    'telegram' => [
        'saved_search_match' => [
            'title' => 'Nueva oportunidad potencial',
            'search' => 'Búsqueda guardada: :search',
            'listing' => 'Anuncio: :listing',
            'asking_price' => 'Precio solicitado: :price',
            'expected_profit' => 'Beneficio neto estimado: :profit',
            'deal_score' => 'Puntuación de oportunidad: :score/100',
            'risk_score' => 'Puntuación de riesgo: :score/100',
            'action' => 'Revisar anuncio',
            'evidence_boundary' => 'Solo se incluyen pruebas inmutables actuales. Se omiten los valores desconocidos.',
            'unknown_listing' => 'Anuncio desconocido',
            'unknown_search' => 'Búsqueda guardada',
        ],
    ],
];

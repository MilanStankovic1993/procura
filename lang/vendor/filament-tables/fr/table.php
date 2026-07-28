<?php

return [
    'column_manager' => [
        'actions' => [
            'reorder' => [
                'label' => 'Réorganiser la colonne',
            ],
        ],
    ],

    'columns' => [
        'icon' => [
            'boolean' => [
                'true' => 'Oui',
                'false' => 'Non',
            ],
        ],
        'select' => [
            'no_options_message' => 'Aucune option disponible.',
        ],
    ],

    'actions' => [
        'reorder_record' => [
            'label' => 'Réorganiser l’élément :key',
        ],
        'toggle_record_content' => [
            'label' => 'Développer ou réduire l’élément :key',
        ],
    ],

    'loading' => 'Chargement...',

    'result_count' => '{0} Aucun résultat|{1} :count résultat|[2,*] :count résultats',
];

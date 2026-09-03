<?php

return [
    'column_manager' => [
        'actions' => [
            'reorder' => [
                'label' => 'Promeni redosled kolone',
            ],
        ],
    ],

    'columns' => [
        'icon' => [
            'boolean' => [
                'true' => 'Da',
                'false' => 'Ne',
            ],
        ],
        'select' => [
            'no_options_message' => 'Nema dostupnih opcija.',
        ],
    ],

    'actions' => [
        'reorder_record' => [
            'label' => 'Promeni redosled stavke :key',
        ],
        'toggle_record_content' => [
            'label' => 'Proširi ili skupi stavku :key',
        ],
    ],

    'loading' => 'Učitavanje...',

    'result_count' => '{0} Nema rezultata|{1} :count rezultat|[2,4] :count rezultata|[5,*] :count rezultata',
];

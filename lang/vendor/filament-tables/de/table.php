<?php

return [
    'column_manager' => [
        'actions' => [
            'reorder' => [
                'label' => 'Spalte neu anordnen',
            ],
        ],
    ],

    'columns' => [
        'icon' => [
            'boolean' => [
                'true' => 'Ja',
                'false' => 'Nein',
            ],
        ],
        'select' => [
            'no_options_message' => 'Keine Optionen verfügbar.',
        ],
    ],

    'actions' => [
        'reorder_record' => [
            'label' => 'Element :key neu anordnen',
        ],
        'toggle_record_content' => [
            'label' => 'Element :key ein- oder ausklappen',
        ],
    ],

    'loading' => 'Wird geladen...',

    'result_count' => '{0} Keine Ergebnisse|{1} :count Ergebnis|[2,*] :count Ergebnisse',
];

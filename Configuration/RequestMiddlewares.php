<?php

// Die Schnittstelle antwortet auf /_velundo/v1/… noch vor der Site-Auflösung des Frontends;
// so hängt sie nicht an Routing, Fehlerseiten oder Wartungsmodus einzelner Sites.
return [
    'frontend' => [
        'velundo/connect/api' => [
            'target' => \Velundo\Connect\Middleware\ApiMiddleware::class,
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
            'before' => [
                'typo3/cms-frontend/eid',
                'typo3/cms-frontend/site',
            ],
        ],
    ],
];

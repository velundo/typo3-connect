<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'velundo connect',
    'description' => 'Macht eine TYPO3-Installation für die velundo-App erreichbar: Seiten, Inhalte, SEO-Felder, Nachrichten und Umleitungen lesen und gezielt ändern.',
    'category' => 'services',
    'state' => 'beta',
    'version' => '1.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-14.99.99',
            'php' => '8.2.0-8.99.99',
        ],
        'conflicts' => [],
        'suggests' => [
            'seo' => '',
            'redirects' => '',
            'news' => '',
        ],
    ],
];

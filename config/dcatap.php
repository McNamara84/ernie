<?php

declare(strict_types=1);

return [
    'enabled' => env('DCATAP_ENABLED', false),
    'version' => '3.0.1',
    'revision' => 1,
    'profile' => 'https://semiceu.github.io/DCAT-AP/releases/3.0.1/',
    'metadata_prefix' => 'dcat_ap',
    'namespace' => 'http://data.europa.eu/r5r/',
    'resource_types' => ['dataset', 'collection', 'model', 'image', 'sound'],
    'epos' => [
        'enabled' => env('EPOS_DCAT_AP_ENABLED', false),
        'version' => '3.0',
        'revision' => 1,
        'profile' => 'https://epos-eu.github.io/EPOS-DCAT-AP/v3/',
        'namespace' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
    ],
    'catalog' => [
        'uri' => env('DCATAP_CATALOG_URI', 'https://dataservices.gfz.de/'),
        'title' => env('DCATAP_CATALOG_TITLE', 'GFZ Data Services'),
        'description' => env('DCATAP_CATALOG_DESCRIPTION', 'Research data published by GFZ Data Services.'),
        'publisher' => env('DCATAP_CATALOG_PUBLISHER', 'GFZ Data Services'),
    ],
];

<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | ISO 19115-3 metadata export
    |--------------------------------------------------------------------------
    |
    | ISO 19115-3 is enabled by default. Set ISO19115_ENABLED=false to remove
    | the public representation and the OAI-PMH metadata format without
    | affecting the existing DataCite exports.
    |
    */
    'enabled' => env('ISO19115_ENABLED', true),

    'metadata_prefix' => 'iso19115_3',

    'namespace' => 'https://schemas.isotc211.org/19115/-1/mdb/1.3',

    'schema' => 'https://schemas.isotc211.org/19115/-1/mdb/1.3.0/mdb.xsd',

    'profile' => 'https://schemas.isotc211.org/19115/-1/mdb/1.3',

    'media_type' => 'application/xml; charset=UTF-8; profile="https://schemas.isotc211.org/19115/-1/mdb/1.3"',

    'codelist' => 'https://schemas.isotc211.org/resources/codelists/codelists.xml',

    'validation' => [
        'schema' => resource_path('data/scheme/iso-19115-3/2023/ernie-profile.xsd'),
        'catalog' => resource_path('data/scheme/iso-19115-3/2023/catalog.xml'),
        'schematron_xslt' => resource_path('data/scheme/iso-19115-3/2023/ernie-profile-schematron.xsl'),
        'manifest' => resource_path('data/scheme/iso-19115-3/2023/manifest.json'),
    ],

    'metadata_contact' => [
        'name' => env('ISO19115_METADATA_CONTACT_NAME', 'GFZ Data Services'),
        'email' => env('ISO19115_METADATA_CONTACT_EMAIL', 'datapub@gfz.de'),
        'website' => env('ISO19115_METADATA_CONTACT_WEBSITE', 'https://dataservices.gfz.de/'),
    ],

    /*
    | Immutable DataCite resource-type slugs and their ISO MD_ScopeCode.
    */
    'resource_scopes' => [
        'dataset' => 'dataset',
        'physical-object' => 'sample',
        'collection' => 'collection',
        'model' => 'model',
        'instrument' => 'collectionHardware',
        'service' => 'service',
        'software' => 'software',
        'computational-notebook' => 'software',
        'workflow' => 'software',
        'interactive-resource' => 'application',
        'image' => 'document',
    ],
];

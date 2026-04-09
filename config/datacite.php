<?php

return [
    /*
    |--------------------------------------------------------------------------
    | DataCite Test Mode
    |--------------------------------------------------------------------------
    |
    | When true, the DataCite test API will be used. When false, the production
    | API will be used. This should be set to true in development/testing
    | environments and false in production.
    |
    */
    'test_mode' => env('DATACITE_TEST_MODE', true),

    /*
    |--------------------------------------------------------------------------
    | DataCite Production API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the production DataCite API. These credentials are used
    | when test_mode is false.
    |
    */
    'production' => [
        'endpoint' => env('DATACITE_ENDPOINT', 'https://api.datacite.org'),
        'username' => env('DATACITE_USERNAME'),
        'password' => env('DATACITE_PASSWORD'),
        'client_id' => env('DATACITE_CLIENT_ID', 'tib.gfz'),
        'prefixes' => [
            '10.5880',
            '10.1594',
            '10.14470',
        ],
        'igsn_prefix' => '10.60510',
        'igsn_client_id' => 'gfz.igsn',
    ],

    /*
    |--------------------------------------------------------------------------
    | DataCite Test API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the DataCite test API. These credentials are used
    | when test_mode is true. The test API allows you to safely test DOI
    | registration without affecting production data.
    |
    */
    'test' => [
        'endpoint' => env('DATACITE_TEST_ENDPOINT', 'https://api.test.datacite.org'),
        'username' => env('DATACITE_TEST_USERNAME'),
        'password' => env('DATACITE_TEST_PASSWORD'),
        'prefixes' => [
            '10.83279',
            '10.83186',
            '10.83114',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | DataCite API Version
    |--------------------------------------------------------------------------
    |
    | The DataCite API version to use. Currently using v2.
    |
    */
    'api_version' => 'v2',

    /*
    |--------------------------------------------------------------------------
    | Publisher Information
    |--------------------------------------------------------------------------
    |
    | Default publisher information for DOI registration.
    |
    */
    'publisher' => [
        'name' => 'GFZ Helmholtz Centre for Geosciences',
        'ror_id' => 'https://ror.org/04z8jg394',
    ],

    /*
    |--------------------------------------------------------------------------
    | Solr IGSN Enrichment
    |--------------------------------------------------------------------------
    |
    | Configuration for the Solr index used to enrich imported IGSNs with
    | legacy DIF XML metadata. The igsnaa core contains ~35k IGSN records.
    |
    */
    'solr' => [
        'host' => env('SOLR_HOST'),
        'port' => env('SOLR_PORT', '443'),
        'user' => env('SOLR_USER'),
        'password' => env('SOLR_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | DataCite Linked Data
    |--------------------------------------------------------------------------
    |
    | Configuration for JSON-LD export using the DataCite Linked Data schema.
    | The context_url points to the JSON-LD context file that defines the
    | vocabulary mapping for DataCite metadata expressed as Linked Data.
    |
    */
    // DataCite Linked Data JSON-LD context configuration.
    // The staging URL is used as default because DataCite has not yet published
    // a stable production context URL. Update when a production URL becomes available.
    'linked_data' => [
        'context_url' => env(
            'DATACITE_LINKED_DATA_CONTEXT_URL',
            'https://schema.stage.datacite.org/linked-data/context/fullcontext.jsonld'
        ),
    ],
];

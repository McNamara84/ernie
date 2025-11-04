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
        'prefixes' => [
            '10.5880',
            '10.26026',
            '10.14470',
        ],
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
];

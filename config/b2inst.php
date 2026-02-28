<?php

return [
    /*
    |--------------------------------------------------------------------------
    | b2inst API Host
    |--------------------------------------------------------------------------
    |
    | The base URL for the b2inst (EUDAT) instrument registry API.
    | Production: https://b2inst.gwdg.de
    | Test: https://b2inst-test.gwdg.de
    |
    */
    'host' => env('B2INST_HOST', 'https://b2inst.gwdg.de'),

    /*
    |--------------------------------------------------------------------------
    | b2inst API Token
    |--------------------------------------------------------------------------
    |
    | OAuth 2.0 access token for accessing draft instruments.
    | For public instruments, no token is required.
    | Generate a token in the b2inst web UI under Profile → API tokens.
    |
    */
    'token' => env('B2INST_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Page Size
    |--------------------------------------------------------------------------
    |
    | Number of records to fetch per page when downloading instruments.
    |
    */
    'page_size' => (int) env('B2INST_PAGE_SIZE', 100),
];

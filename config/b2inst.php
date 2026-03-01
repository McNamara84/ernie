<?php

return [
    /*
    |--------------------------------------------------------------------------
    | b2inst API Host
    |--------------------------------------------------------------------------
    |
    | The base URL for the b2inst (EUDAT) instrument registry API.
    | Only publicly available instruments are fetched (no authentication required).
    | Production: https://b2inst.gwdg.de
    | Test: https://b2inst-test.gwdg.de
    |
    */
    'host' => env('B2INST_HOST', 'https://b2inst.gwdg.de'),

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

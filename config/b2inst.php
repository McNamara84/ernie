<?php

return [
    /*
    |--------------------------------------------------------------------------
    | b2inst API Host
    |--------------------------------------------------------------------------
    |
    | The base URL for the b2inst (EUDAT) instrument registry API.
    | Only publicly available instruments are fetched (no authentication required).
    | Production: https://b2inst.pid.gwdg.de (alias: b2inst.gwdg.de)
    | Test: https://b2inst-test.gwdg.de
    |
    */
    'host' => env('B2INST_HOST', 'https://b2inst.pid.gwdg.de'),

    /*
    |--------------------------------------------------------------------------
    | Page Size
    |--------------------------------------------------------------------------
    |
    | Number of records to fetch per page when downloading instruments.
    |
    */
    'page_size' => (int) env('B2INST_PAGE_SIZE', 100),

    // Shared by pagination, retries and Handle verification; below the 1200s job limit.
    'connect_timeout' => (int) env('B2INST_CONNECT_TIMEOUT', 10),
    'timeout' => (int) env('B2INST_TIMEOUT', 30),
    'max_duration' => (int) env('B2INST_MAX_DURATION', 900),
    'max_pages' => (int) env('B2INST_MAX_PAGES', 1000),
    'max_records' => (int) env('B2INST_MAX_RECORDS', 100000),
    'retry_delay' => 1,
    'resolver_cache_ttl' => (int) env('B2INST_RESOLVER_CACHE_TTL', 900),
];

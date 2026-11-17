<?php

declare(strict_types=1);

return [
    // Null retains B2INST_MAX_DURATION as the combined harvest/resolver budget.
    'max_duration' => env('PIDINST_MAX_DURATION') ?: null,
    'datacite' => [
        // Public production discovery is independent of ERNIE's registration mode.
        'host' => env('PIDINST_DATACITE_HOST', 'https://api.datacite.org'),
        'page_size' => (int) env('PIDINST_DATACITE_PAGE_SIZE', 1000),
        'connect_timeout' => (int) env('PIDINST_DATACITE_CONNECT_TIMEOUT', 10),
        'timeout' => (int) env('PIDINST_DATACITE_TIMEOUT', 30),
        'max_pages' => (int) env('PIDINST_DATACITE_MAX_PAGES', 1000),
        'max_records' => (int) env('PIDINST_DATACITE_MAX_RECORDS', 100000),
    ],
];

<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | MSL Laboratories source
    |--------------------------------------------------------------------------
    |
    | The latest stable laboratories vocabulary is discovered through the
    | GitHub Contents API. Version directories are resolved dynamically, so a
    | future 1.2 or 1.10 release does not require an application change.
    |
    */
    'github_api_base' => env('MSL_GITHUB_API_BASE', 'https://api.github.com'),
    'repository' => env('MSL_GITHUB_REPOSITORY', 'UtrechtUniversity/msl_vocabularies'),
    'ref' => env('MSL_GITHUB_REF', 'main'),
    'laboratories_base_path' => env('MSL_LABORATORIES_BASE_PATH', 'vocabularies/labs'),
    'laboratories_filename' => env('MSL_LABORATORIES_FILENAME', 'laboratories.json'),
    'http_timeout' => (int) env('MSL_HTTP_TIMEOUT', 30),
    'http_retries' => (int) env('MSL_HTTP_RETRIES', 3),
    'http_retry_delay_ms' => (int) env('MSL_HTTP_RETRY_DELAY_MS', 200),
];

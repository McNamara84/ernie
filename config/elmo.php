<?php

return [
    'enabled' => env('ELMO_CACHE_REFRESH_ENABLED', true),

    // Deployment files select Stage or Production explicitly: Stage also uses APP_ENV=production.
    'targets' => [
        'elmo' => [
            'base_url' => env('ELMO_BASE_URL', ''),
            'api_key' => env('ELMO_API_KEY', ''),
        ],
        'elmo-msl' => [
            'base_url' => env('ELMO_MSL_BASE_URL', ''),
            'api_key' => env('ELMO_MSL_API_KEY', ''),
        ],
    ],

    'connect_timeout' => (int) env('ELMO_CACHE_REFRESH_CONNECT_TIMEOUT', 10),
    'timeout' => (int) env('ELMO_CACHE_REFRESH_TIMEOUT', 310),
    'job_timeout' => (int) env('ELMO_CACHE_REFRESH_JOB_TIMEOUT', 330),
];

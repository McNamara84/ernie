<?php

declare(strict_types=1);

return [
    'enabled' => env('FUJI_ENABLED', false),
    'base_url' => env('FUJI_BASE_URL'),
    'username' => env('FUJI_USERNAME'),
    'password' => env('FUJI_PASSWORD'),
    'timeout' => env('FUJI_TIMEOUT', 60),
    'connect_timeout' => env('FUJI_CONNECT_TIMEOUT', 10),
    'use_datacite' => env('FUJI_USE_DATACITE', true),
    'use_github' => env('FUJI_USE_GITHUB', false),
    'test_debug' => env('FUJI_TEST_DEBUG', false),
    'metric_version' => env('FUJI_METRIC_VERSION'),
];
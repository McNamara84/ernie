<?php

declare(strict_types=1);

return [
    'disk' => env('IGSN_IMAGE_DISK', 'public'),
    'connect_timeout_seconds' => (int) env('IGSN_IMAGE_CONNECT_TIMEOUT', 5),
    'timeout_seconds' => (int) env('IGSN_IMAGE_TIMEOUT', 30),
    'max_bytes' => (int) env('IGSN_IMAGE_MAX_BYTES', 20 * 1024 * 1024),
    'allowed_mime_types' => [
        'image/jpeg' => 'jpg',
    ],
    'gfz' => [
        'host' => 'dataservices.gfz-potsdam.de',
        'path_prefix' => '/extern/IGSN/',
    ],
    'icdp' => [
        'legacy_host' => 'www-icdp.icdp-online.org',
        'canonical_host' => 'data.icdp-online.org',
        'path_prefixes' => [
            '/sites/cosc/',
            '/sites/dfdp/',
            '/sites/lusklint/',
            '/sites/sustain/',
        ],
    ],
];

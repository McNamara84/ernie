<?php

declare(strict_types=1);

return [
    'disk' => env('DATABASE_DUMP_DISK', 'local'),
    'path_prefix' => env('DATABASE_DUMP_PATH_PREFIX', 'database-dumps'),
    'expiry_hours' => (int) env('DATABASE_DUMP_EXPIRY_HOURS', 24),
    'timeout_seconds' => (int) env('DATABASE_DUMP_TIMEOUT_SECONDS', 7200),
    'max_parallel_per_user' => (int) env('DATABASE_DUMP_MAX_PARALLEL_PER_USER', 1),
    'dump_binary' => env('DATABASE_DUMP_BINARY'),

    'targets' => [
        'ernie' => [
            'label' => 'ERNIE',
            'description' => 'Current ERNIE application database',
            'connection' => 'mysql',
            'legacy' => false,
            'server_version_hint' => 'MySQL Community Server 9.7.0',
        ],

        // LEGACY_DATABASE_DUMP_SUPPORT:
        // These legacy targets back the admin-only /database dump page while the
        // old MySQL 5.6/5.7 databases are still operationally relevant.
        // Remove this block when legacy database exports are retired.
        'sumariopmd' => [
            'label' => 'SUMARIOPMD',
            'description' => 'Legacy SUMARIOPMD metadata database',
            'connection' => 'metaworks',
            'legacy' => true,
            'server_version_hint' => 'MySQL Community Server 5.7.38-log',
        ],
        'metaworks' => [
            'label' => 'MetaWorks',
            'description' => 'Legacy MetaWorks metadata database',
            'connection' => 'legacy_metaworks',
            'legacy' => true,
            'server_version_hint' => 'MySQL Community Server 5.7.38-log',
        ],
        'igsn' => [
            'label' => 'IGSN',
            'description' => 'Legacy IGSN metadata database',
            'connection' => 'igsn_legacy',
            'legacy' => true,
            'requires_legacy_ssl_probe' => true,
            'server_version_hint' => 'MySQL 5.6.36',
        ],
    ],
];

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
            'description' => 'Neue ERNIE-Datenbank',
            'connection' => 'mysql',
            'database_env' => 'DB_DATABASE',
            'legacy' => false,
            'server_version_hint' => 'MySQL Community Server 9.7.0',
        ],

        // LEGACY_DATABASE_DUMP_SUPPORT:
        // These legacy targets back the admin-only /database dump page while the
        // old MySQL 5.6/5.7 databases are still operationally relevant.
        // Remove this block when legacy database exports are retired.
        'sumariopmd' => [
            'label' => 'SUMARIOPMD',
            'description' => 'Alte SUMARIOPMD-Datenbank',
            'connection' => 'metaworks',
            'database_env' => 'DB_SUMARIOPMD_NAME',
            'legacy' => true,
            'server_version_hint' => 'MySQL Community Server 5.7.38-log',
        ],
        'metaworks' => [
            'label' => 'MetaWorks',
            'description' => 'Alte MetaWorks-Datenbank',
            'connection' => 'legacy_metaworks',
            'database_env' => 'DB_METAWORKS_NAME',
            'legacy' => true,
            'server_version_hint' => 'MySQL Community Server 5.7.38-log',
        ],
        'igsn' => [
            'label' => 'IGSN',
            'description' => 'Alte IGSN-Datenbank',
            'connection' => 'igsn_legacy',
            'database_env' => 'DB_IGSN_NAME',
            'legacy' => true,
            'requires_legacy_ssl_probe' => true,
            'server_version_hint' => 'MySQL 5.6.36',
        ],
    ],
];

<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('FUNDING_AWARD_URI_ENABLED', false),
    'rules_version' => '1',
    'connect_timeout' => 5,
    'timeout' => 20,
    'attempts' => 3,
    'request_interval_ms' => 1000,
    'max_wait_seconds' => 30,
    'positive_cache_seconds' => 86400,
    'negative_cache_seconds' => 3600,
    'evidence_days' => 7,
    'crosswalk_days' => 180,
    'max_candidates' => 300,
    'run_seconds' => 300,
    'chunk_size' => 100,
    // Exact FundRef identities, verified against https://api.crossref.org/funders/{id}.
    // Programme identifiers are supplied by https://cordis.europa.eu/datalab/sparql.
    'cordis_funders' => [
        '10.13039/501100000780' => [
            'name' => 'European Commission',
            'programmes' => ['H2020.', 'HORIZON.', 'FP7-'],
        ],
        '10.13039/501100000781' => [
            'name' => 'European Research Council',
            'programmes' => ['H2020.1.1.', 'HORIZON.1.1.', 'FP7-IDEAS'],
        ],
        '10.13039/100010661' => [
            'name' => 'Horizon 2020 Framework Programme',
            'programmes' => ['H2020.'],
        ],
    ],
];

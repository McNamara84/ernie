<?php

return [
    /*
    |--------------------------------------------------------------------------
    | DataCite Test Mode
    |--------------------------------------------------------------------------
    |
    | When true, the DataCite test API will be used. When false, the production
    | API will be used. This should be set to true in development/testing
    | environments and false in production.
    |
    */
    'test_mode' => env('DATACITE_TEST_MODE', true),

    // Existing DOI create/full-update paths retry transient transport and 5xx
    // failures only. URL migration jobs own their persistent retry lifecycle.
    'transport_transient_attempts' => (int) env('DATACITE_TRANSPORT_TRANSIENT_ATTEMPTS', 3),

    /*
    |--------------------------------------------------------------------------
    | Landing Page URL Domain Migration
    |--------------------------------------------------------------------------
    |
    | APP_URL is the only canonical target base after a domain move. DataCite
    | recommends conservative sustained rates for large DOI updates.
    |
    */
    'landing_page_url_update' => [
        'requests_per_window' => (int) env('DATACITE_URL_UPDATE_REQUESTS_PER_WINDOW', 300),
        'window_seconds' => (int) env('DATACITE_URL_UPDATE_WINDOW_SECONDS', 300),
        'minimum_interval_ms' => (int) env('DATACITE_URL_UPDATE_MINIMUM_INTERVAL_MS', 1000),
        'connect_timeout_seconds' => (int) env('DATACITE_URL_UPDATE_CONNECT_TIMEOUT_SECONDS', 10),
        'timeout_seconds' => (int) env('DATACITE_URL_UPDATE_TIMEOUT_SECONDS', 30),
        'reachability_connect_timeout_seconds' => (int) env('DATACITE_URL_UPDATE_REACHABILITY_CONNECT_TIMEOUT_SECONDS', 3),
        'reachability_timeout_seconds' => (int) env('DATACITE_URL_UPDATE_REACHABILITY_TIMEOUT_SECONDS', 8),
        'max_transient_attempts' => (int) env('DATACITE_URL_UPDATE_MAX_TRANSIENT_ATTEMPTS', 5),
        'queue' => env('DATACITE_URL_UPDATE_QUEUE', 'datacite'),
        'support_email' => env('DATACITE_USER_AGENT_EMAIL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Exhaustive Citation Label Resolution
    |--------------------------------------------------------------------------
    |
    | Single-resource imports exhaust all configured citation-label sources,
    | while unresolved labels remain optional. Requests are pooled in bounded
    | chunks and retried for transient failures without changing bulk imports.
    |
    */
    'citation_labels' => [
        'exhaustive_concurrency' => (int) env(
            'DATACITE_CITATION_EXHAUSTIVE_CONCURRENCY',
            env('DATACITE_CITATION_REQUIRED_CONCURRENCY', 4),
        ),
        'exhaustive_timeout_seconds' => (float) env(
            'DATACITE_CITATION_EXHAUSTIVE_TIMEOUT_SECONDS',
            env('DATACITE_CITATION_REQUIRED_TIMEOUT_SECONDS', 10),
        ),
        'exhaustive_attempts' => (int) env(
            'DATACITE_CITATION_EXHAUSTIVE_ATTEMPTS',
            env('DATACITE_CITATION_REQUIRED_ATTEMPTS', 3),
        ),
        'exhaustive_retry_delay_ms' => (int) env(
            'DATACITE_CITATION_EXHAUSTIVE_RETRY_DELAY_MS',
            env('DATACITE_CITATION_REQUIRED_RETRY_DELAY_MS', 500),
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | DataCite Production API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the production DataCite API. These credentials are used
    | when test_mode is false.
    |
    */
    'production' => [
        'endpoint' => env('DATACITE_ENDPOINT', 'https://api.datacite.org'),
        'username' => env('DATACITE_USERNAME'),
        'password' => env('DATACITE_PASSWORD'),
        'client_id' => env('DATACITE_CLIENT_ID', 'tib.gfz'),
        'prefixes' => [
            '10.5880',
            '10.1594',
            '10.14470',
        ],
        'igsn_prefix' => '10.60510',
        'igsn_client_id' => 'gfz.igsn',
    ],

    /*
    |--------------------------------------------------------------------------
    | GFZ Data Services Legacy Portal
    |--------------------------------------------------------------------------
    |
    | The legacy portal exposes the authoritative datacentre_facet values used
    | to group published GFZ resources. ERNIE accesses the proxy server-side;
    | browsers never receive raw Solr query access.
    |
    */
    'legacy_portal' => [
        'proxy_url' => env(
            'GFZ_DATA_SERVICES_PORTAL_PROXY_URL',
            'https://dataservices.gfz-potsdam.de/portal/proxy/proxy.php',
        ),
        'timeout_seconds' => (int) env('GFZ_DATA_SERVICES_PORTAL_TIMEOUT', 30),
        'retry_times' => (int) env('GFZ_DATA_SERVICES_PORTAL_RETRY_TIMES', 3),
        'retry_sleep_ms' => (int) env('GFZ_DATA_SERVICES_PORTAL_RETRY_SLEEP_MS', 500),
        'page_size' => (int) env('GFZ_DATA_SERVICES_PORTAL_PAGE_SIZE', 500),
        'datacenter_cache_ttl_seconds' => (int) env('GFZ_DATA_SERVICES_PORTAL_CACHE_TTL', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | GFZ Legacy IGSN Portal
    |--------------------------------------------------------------------------
    |
    | The retired IGSN catalogue exposes the authoritative datacentre_facet
    | values used to categorize legacy physical samples.
    |
    */
    'legacy_igsn_portal' => [
        'proxy_url' => env(
            'GFZ_IGSN_PORTAL_PROXY_URL',
            'https://dataservices.gfz-potsdam.de/igsn/portal/proxy/proxy.php',
        ),
        'timeout_seconds' => (int) env('GFZ_IGSN_PORTAL_TIMEOUT', 30),
        'retry_times' => (int) env('GFZ_IGSN_PORTAL_RETRY_TIMES', 3),
        'retry_sleep_ms' => (int) env('GFZ_IGSN_PORTAL_RETRY_SLEEP_MS', 500),
        'page_size' => (int) env('GFZ_IGSN_PORTAL_PAGE_SIZE', 500),
        'datacenter_cache_ttl_seconds' => (int) env('GFZ_IGSN_PORTAL_CACHE_TTL', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | DataCite Test API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the DataCite test API. These credentials are used
    | when test_mode is true. The test API allows you to safely test DOI
    | registration without affecting production data.
    |
    */
    'test' => [
        'endpoint' => env('DATACITE_TEST_ENDPOINT', 'https://api.test.datacite.org'),
        'username' => env('DATACITE_TEST_USERNAME'),
        'password' => env('DATACITE_TEST_PASSWORD'),
        'prefixes' => [
            '10.83279',
            '10.83186',
            '10.83114',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | DataCite API Version
    |--------------------------------------------------------------------------
    |
    | The DataCite API version to use. Currently using v2.
    |
    */
    'api_version' => 'v2',

    /*
    |--------------------------------------------------------------------------
    | Publisher Information
    |--------------------------------------------------------------------------
    |
    | Default publisher information for DOI registration.
    |
    */
    'publisher' => [
        'name' => 'GFZ Helmholtz Centre for Geosciences',
        'ror_id' => 'https://ror.org/04z8jg394',
    ],

    /*
    |--------------------------------------------------------------------------
    | Solr IGSN Enrichment
    |--------------------------------------------------------------------------
    |
    | Configuration for the Solr index used to enrich imported IGSNs with
    | legacy DIF XML metadata. The igsnaa core contains ~35k IGSN records.
    |
    */
    'solr' => [
        'host' => env('SOLR_HOST'),
        'port' => env('SOLR_PORT', '443'),
        'user' => env('SOLR_USER'),
        'password' => env('SOLR_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | DataCite Linked Data
    |--------------------------------------------------------------------------
    |
    | Configuration for JSON-LD export using the DataCite Linked Data schema.
    | The context_url points to the JSON-LD context file that defines the
    | vocabulary mapping for DataCite metadata expressed as Linked Data.
    |
    */
    // DataCite Linked Data JSON-LD context configuration.
    // The staging URL is used as default because DataCite has not yet published
    // a stable production context URL. Update when a production URL becomes available.
    'linked_data' => [
        'context_url' => env(
            'DATACITE_LINKED_DATA_CONTEXT_URL',
            'https://schema.stage.datacite.org/linked-data/context/fullcontext.jsonld'
        ),
    ],
];

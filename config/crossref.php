<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Crossref REST API
    |--------------------------------------------------------------------------
    |
    | Configuration for looking up citation metadata via the Crossref API,
    | used by the Citation Manager for DOI auto-fill (primary source,
    | falling back to DataCite).
    |
    */

    'base_url' => env('CROSSREF_BASE_URL', 'https://api.crossref.org/works'),

    // Polite pool — Crossref prioritises requests with a contact mailto.
    'mailto' => env('CROSSREF_MAILTO', ''),

    'timeout' => (int) env('CROSSREF_TIMEOUT', 8),

    'cache_ttl' => (int) env('CROSSREF_CACHE_TTL', 86400),
];

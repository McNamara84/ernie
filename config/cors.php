<?php

return [
    // Retain Laravel's default API paths and allow browser-based schema tools.
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'schemas/igsn/2.0/*', 'schemas/datacite/4.7/*', 'metadata/contexts/datacite-4.7-v1.jsonld'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['ETag', 'Last-Modified'],
    'max_age' => 0,
    'supports_credentials' => false,
];

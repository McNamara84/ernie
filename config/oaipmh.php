<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | OAI-PMH Repository Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the OAI-PMH 2.0 harvesting endpoint.
    | See: http://www.openarchives.org/OAI/openarchivesprotocol.html
    |
    */

    'repository_name' => 'ERNIE – GFZ Data Publication Repository',

    'base_url' => env('APP_URL', 'https://ernie.gfz.de') . '/oai-pmh',

    'admin_email' => 'datapub@gfz.de',

    'identifier_prefix' => 'oai:ernie.gfz.de',

    'deleted_record' => 'persistent',

    'granularity' => 'YYYY-MM-DDThh:mm:ssZ',

    'protocol_version' => '2.0',

    'earliest_datestamp' => '2000-01-01T00:00:00Z',

    'page_size' => 100,

    'resumption_token_ttl' => 86400, // 24 hours in seconds

    'metadata_formats' => [
        'oai_dc' => [
            'schema' => 'http://www.openarchives.org/OAI/2.0/oai_dc.xsd',
            'namespace' => 'http://www.openarchives.org/OAI/2.0/oai_dc/',
        ],
        'oai_datacite' => [
            'schema' => 'https://schema.datacite.org/meta/kernel-4.7/metadata.xsd',
            'namespace' => 'http://datacite.org/schema/kernel-4',
        ],
    ],
];

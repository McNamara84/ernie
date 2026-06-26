<?php

return [
    /*
    |--------------------------------------------------------------------------
    | RAiD DataCite Search Endpoint
    |--------------------------------------------------------------------------
    |
    | Public RAiD search currently resolves records through DataCite metadata.
    | This endpoint is used to count and download public RAiD project records.
    |
    */
    'datacite_endpoint' => env('RAID_DATACITE_ENDPOINT', 'https://api.datacite.org'),

    /*
    |--------------------------------------------------------------------------
    | RAiD Search Query
    |--------------------------------------------------------------------------
    |
    | Query used by the public RAiD search site to identify RAiD records in
    | DataCite. Keep this configurable in case RAiD changes its indexing.
    |
    */
    'search_query' => env('RAID_DATACITE_QUERY', 'identifiers.identifier:*raid.org.au*'),

    /*
    |--------------------------------------------------------------------------
    | Page Size
    |--------------------------------------------------------------------------
    |
    | Number of DataCite records to fetch per page when downloading RAiDs.
    |
    */
    'page_size' => (int) env('RAID_DATACITE_PAGE_SIZE', 1000),
];

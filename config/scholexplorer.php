<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | ScholExplorer API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the OpenAIRE ScholExplorer API used to discover
    | scholarly links between datasets and publications.
    |
    | @see https://scholexplorer.openaire.eu/
    */

    'base_url' => env('SCHOLEXPLORER_API_URL', 'https://api.scholexplorer.openaire.eu/v3'),

    'timeout' => (int) env('SCHOLEXPLORER_TIMEOUT', 30),
];

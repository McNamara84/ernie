<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | ARDC Linked Data API Configuration
    |--------------------------------------------------------------------------
    |
    | Centralized configuration for the ARDC (Australian Research Data Commons)
    | Linked Data API endpoints used by vocabulary fetch commands and status checks.
    |
    */

    'chronostratigraphy' => [
        'url' => env(
            'ARDC_CHRONOSTRAT_URL',
            'https://vocabs.ardc.edu.au/repository/api/lda/csiro/international-chronostratigraphic-chart/geologic-time-scale-2020/concept.json'
        ),
    ],

    'analytical_methods' => [
        // The ARDC slug "cosmochemi" is intentionally truncated by the ARDC platform.
        'url_template' => env(
            'ARDC_ANALYTICAL_METHODS_URL_TEMPLATE',
            'https://vocabs.ardc.edu.au/repository/api/lda/earthchem-georoc/analytical-methods-for-geochemistry-and-cosmochemi/{version}/concept.json'
        ),
        'default_version' => env('ARDC_ANALYTICAL_METHODS_DEFAULT_VERSION', '1-4'),
    ],

];

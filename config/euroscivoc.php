<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | European Science Vocabulary (EuroSciVoc) Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the EuroSciVoc vocabulary provided by the
    | Publications Office of the European Union.
    |
    | EuroSciVoc is a taxonomy of fields of science based on OECD's
    | 2015 Frascati Manual taxonomy, extended with fields of science
    | categories extracted from CORDIS content via NLP.
    |
    */

    'download_url' => env(
        'EUROSCIVOC_DOWNLOAD_URL',
        'https://op.europa.eu/o/opportal-service/euvoc-download-handler?cellarURI=http%3A%2F%2Fpublications.europa.eu%2Fresource%2Fdistribution%2Feuroscivoc%2F20250924-0%2Frdf%2Fskos_xl%2FEuroSciVoc.rdf&fileName=EuroSciVoc.rdf'
    ),

    'concept_scheme_uri' => env(
        'EUROSCIVOC_CONCEPT_SCHEME_URI',
        'http://data.europa.eu/8mn/euroscivoc/40c0f173-baa3-48a3-9fe6-d6e8fb366a00'
    ),

    'scheme_name' => 'European Science Vocabulary (EuroSciVoc)',

];

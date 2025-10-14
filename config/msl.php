<?php

return [
    /*
    |--------------------------------------------------------------------------
    | MSL Laboratories Vocabulary URL
    |--------------------------------------------------------------------------
    |
    | This URL points to the MSL Laboratories JSON vocabulary maintained by
    | Utrecht University. Both server-side (MslLaboratoryService) and
    | client-side (use-msl-laboratories hook) consume this single source.
    |
    */
    'vocabulary_url' => env(
        'MSL_VOCABULARY_URL',
        'https://raw.githubusercontent.com/UtrechtUniversity/msl_vocabularies/refs/heads/main/vocabularies/labs/laboratories.json'
    ),
];

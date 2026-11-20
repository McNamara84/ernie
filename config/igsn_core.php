<?php

declare(strict_types=1);

return [
    'revision' => 'v1',
    // Only published revisions are offered for validation and documentation.
    'revisions' => [
        'v1' => resource_path('data/scheme/igsn-core/2.0/v1'),
    ],
    'documentation_locale' => 'en',
    'stylesheet' => resource_path('xslt/igsn-core/2.0/ernie-to-igsn-core.xsl'),
];

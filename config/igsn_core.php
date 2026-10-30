<?php

declare(strict_types=1);

return [
    'revision' => 'preview-1',
    // Keep previous revisions here when publishing a new one: their URLs are immutable.
    'revisions' => [
        'preview-1' => resource_path('data/scheme/igsn-core/2.0/preview-1'),
    ],
    'stylesheet' => resource_path('xslt/igsn-core/2.0/ernie-to-igsn-core.xsl'),
];

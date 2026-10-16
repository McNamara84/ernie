<?php

declare(strict_types=1);

/*
 * Immutable follow-up to 2026-10-16.php for issue #1475's review corrections.
 * Escape literal punctuation in character classes for HTML's v flag and remove
 * the invalid Unicode identity escape \:. Validation semantics stay unchanged.
 * Known IGSN DOI prefixes also accept the optional DOI label during detection.
 */
return [
    'LSID' => [
        [
            'type' => 'validation',
            'previous' => '^urn:lsid:[a-z0-9.-]+:[a-z0-9._-]+:[a-z0-9._-]+(?:\\:\\d+)?$',
            'previous_priority' => 10,
            'pattern' => '^urn:lsid:[a-z0-9.\\-]+:[a-z0-9._\\-]+:[a-z0-9._\\-]+(?::\\d+)?$',
            'priority' => 10,
        ],
    ],
    'PURL' => [
        [
            'type' => 'validation',
            'previous' => '^https?://purl\\.[a-z0-9.-]+/[a-z0-9._/-]+$',
            'previous_priority' => 10,
            'pattern' => '^https?://purl\\.[a-z0-9.\\-]+/[a-z0-9._\\/\\-]+$',
            'priority' => 10,
        ],
    ],
    'RRID' => [
        [
            'type' => 'validation',
            'previous' => '^(?:RRID:)?[a-z]+[_:][a-z0-9_:-]+$',
            'previous_priority' => 10,
            'pattern' => '^(?:RRID:)?[a-z]+[_:][a-z0-9_:\\-]+$',
            'priority' => 10,
        ],
    ],
    'URN' => [
        [
            'type' => 'validation',
            'previous' => '^urn:[a-z0-9][a-z0-9-]{0,31}:\\S+$',
            'previous_priority' => 10,
            'pattern' => '^urn:[a-z0-9][a-z0-9\\-]{0,31}:\\S+$',
            'priority' => 10,
        ],
    ],
    'w3id' => [
        [
            'type' => 'validation',
            'previous' => '^https?://w3id\\.org/[a-z0-9._/-]+(?:#[a-z0-9._-]*)?$',
            'previous_priority' => 10,
            'pattern' => '^https?://w3id\\.org/[a-z0-9._\\/\\-]+(?:#[a-z0-9._\\-]*)?$',
            'priority' => 10,
        ],
    ],
    'IGSN' => [
        [
            'type' => 'detection',
            'previous' => '^(?:10\\.(?:25813|57777|57977|57993|58006|58030|58031|58052|58056|58057|58095|58100|58104|58108|58136|58151|60470|60471|60475|60482|60488|60494|60510|60516|60578|60579|60607|60661|60665|60936)/\\S+|10273/[A-Za-z0-9][A-Za-z0-9._\\-]{1,63})(?![\\s\\S])',
            'previous_priority' => 85,
            'pattern' => '^(?:(?:[dD][oO][iI]:[ ]*)?10\\.(?:25813|57777|57977|57993|58006|58030|58031|58052|58056|58057|58095|58100|58104|58108|58136|58151|60470|60471|60475|60482|60488|60494|60510|60516|60578|60579|60607|60661|60665|60936)/\\S+|10273/[A-Za-z0-9][A-Za-z0-9._\\-]{1,63})(?![\\s\\S])',
            'priority' => 85,
        ],
    ],
];

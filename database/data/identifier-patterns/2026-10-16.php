<?php

declare(strict_types=1);

/*
 * Immutable identifier-pattern correction for issue #1475. Both the data
 * migration and the current seeder use this snapshot. Future corrections must
 * use a new dated snapshot so historical migrations never change behavior.
 *
 * Namespace evidence checked 2026-10-16:
 * IGSN DOI prefixes: https://ev.igsn.org/resources/igsn-agents-and-registration
 * Legacy Handle prefix 10273: https://dataservices.gfz.de/10.60510/gflvk00kb/igsn-gflvk00kb-es0319-sg-n1c-1-geo1-specimen
 * RAiD prefix 10.82210: https://api.datacite.org/prefixes/10.82210?include=clients
 * Its sole client uuag.xxnipc is a raidRegistry, not an arbitrary DOI repository.
 *
 * Validation accepts complete input forms. Detection is narrower and ranks
 * specific namespaces above DOI (30), ISBN above EAN13 (40), and both above URL.
 * Explicit letter cases also work in HTML pattern without an implicit i flag.
 * The final lookahead requires absolute end-of-input, including in JavaScript.
 */
return [
    'DOI' => [
        [
            'type' => 'validation',
            'previous' => '^10\\.\\d{4,}/\\S+$',
            'previous_priority' => 10,
            'pattern' => '^(?:(?:[hH][tT][tT][pP][sS]?://(?:[dD][xX]\\.)?[dD][oO][iI]\\.[oO][rR][gG]/|[dD][oO][iI]:[ ]*)?10\\.[0-9]{4,}(?:\\.[0-9]+)*/\\S+)(?![\\s\\S])',
            'priority' => 10,
        ],
        [
            'type' => 'detection',
            'previous' => '^https?://(?:doi\\.org|dx\\.doi\\.org)/(.+)',
            'previous_priority' => 30,
            'pattern' => '^(?:[hH][tT][tT][pP][sS]?://(?:[dD][xX]\\.)?[dD][oO][iI]\\.[oO][rR][gG]/10\\.[0-9]{4,}(?:\\.[0-9]+)*/\\S+)(?![\\s\\S])',
            'priority' => 30,
        ],
        [
            'type' => 'detection',
            'previous' => '^doi:',
            'previous_priority' => 20,
            'pattern' => '^(?:[dD][oO][iI]:[ ]*10\\.[0-9]{4,}(?:\\.[0-9]+)*/\\S+)(?![\\s\\S])',
            'priority' => 20,
        ],
        [
            'type' => 'detection',
            'previous' => '^10\\.\\d{4,}',
            'previous_priority' => 10,
            'pattern' => '^(?:10\\.[0-9]{4,}(?:\\.[0-9]+)*/\\S+)(?![\\s\\S])',
            'priority' => 10,
        ],
    ],
    'arXiv' => [
        [
            'type' => 'validation',
            'previous' => '^\\d{4}\\.\\d{4,5}(v\\d+)?$',
            'previous_priority' => 10,
            'pattern' => '^(?:(?:[aA][rR][xX][iI][vV]:|[hH][tT][tT][pP][sS]?://[aA][rR][xX][iI][vV]\\.[oO][rR][gG]/(?:abs|html|src)/)?(?:(?:07(?:0[4-9]|1[0-2])|(?:0[89]|1[0-4])(?:0[1-9]|1[0-2]))\\.(?!0000)[0-9]{4}|(?:1[5-9]|[2-9][0-9]|0[0-6])(?:0[1-9]|1[0-2])\\.(?!00000)[0-9]{5})(?:[vV][1-9][0-9]*)?)(?![\\s\\S])',
            'priority' => 10,
        ],
        [
            'type' => 'validation',
            'previous' => '^\\d{4}\\.\\d{4,5}(v\\d+)?$',
            'previous_priority' => 10,
            'pattern' => '^(?:[hH][tT][tT][pP][sS]?://[aA][rR][xX][iI][vV]\\.[oO][rR][gG]/pdf/(?:(?:07(?:0[4-9]|1[0-2])|(?:0[89]|1[0-4])(?:0[1-9]|1[0-2]))\\.(?!0000)[0-9]{4}|(?:1[5-9]|[2-9][0-9]|0[0-6])(?:0[1-9]|1[0-2])\\.(?!00000)[0-9]{5})(?:[vV][1-9][0-9]*)?(?:\\.pdf)?)(?![\\s\\S])',
            'priority' => 10,
        ],
        [
            'type' => 'validation',
            'previous' => '^[a-z-]+/\\d{7}$',
            'previous_priority' => 5,
            'pattern' => '^(?:(?:[aA][rR][xX][iI][vV]:|[hH][tT][tT][pP][sS]?://[aA][rR][xX][iI][vV]\\.[oO][rR][gG]/(?:abs|html|src)/)?[A-Za-z][A-Za-z\\-]*(?:\\.[A-Za-z]{2})?/(?:(?:9[1-9]|0[0-6])(?:0[1-9]|1[0-2])|070[1-3])(?!000)[0-9]{3}(?:[vV][1-9][0-9]*)?)(?![\\s\\S])',
            'priority' => 5,
        ],
        [
            'type' => 'validation',
            'previous' => '^[a-z-]+/\\d{7}$',
            'previous_priority' => 5,
            'pattern' => '^(?:[hH][tT][tT][pP][sS]?://[aA][rR][xX][iI][vV]\\.[oO][rR][gG]/pdf/[A-Za-z][A-Za-z\\-]*(?:\\.[A-Za-z]{2})?/(?:(?:9[1-9]|0[0-6])(?:0[1-9]|1[0-2])|070[1-3])(?!000)[0-9]{3}(?:[vV][1-9][0-9]*)?(?:\\.pdf)?)(?![\\s\\S])',
            'priority' => 5,
        ],
        [
            'type' => 'detection',
            'previous' => '^https?://arxiv\\.org/(?:abs|pdf|html|src)/\\S+',
            'previous_priority' => 30,
            'pattern' => '^(?:[hH][tT][tT][pP][sS]?://[aA][rR][xX][iI][vV]\\.[oO][rR][gG]/(?:abs|html|src)/(?:(?:(?:07(?:0[4-9]|1[0-2])|(?:0[89]|1[0-4])(?:0[1-9]|1[0-2]))\\.(?!0000)[0-9]{4}|(?:1[5-9]|[2-9][0-9]|0[0-6])(?:0[1-9]|1[0-2])\\.(?!00000)[0-9]{5})(?:[vV][1-9][0-9]*)?|[A-Za-z][A-Za-z\\-]*(?:\\.[A-Za-z]{2})?/(?:(?:9[1-9]|0[0-6])(?:0[1-9]|1[0-2])|070[1-3])(?!000)[0-9]{3}(?:[vV][1-9][0-9]*)?))(?![\\s\\S])',
            'priority' => 75,
        ],
        [
            'type' => 'detection',
            'previous' => '^https?://arxiv\\.org/(?:abs|pdf|html|src)/\\S+',
            'previous_priority' => 30,
            'pattern' => '^(?:[hH][tT][tT][pP][sS]?://[aA][rR][xX][iI][vV]\\.[oO][rR][gG]/pdf/(?:(?:(?:07(?:0[4-9]|1[0-2])|(?:0[89]|1[0-4])(?:0[1-9]|1[0-2]))\\.(?!0000)[0-9]{4}|(?:1[5-9]|[2-9][0-9]|0[0-6])(?:0[1-9]|1[0-2])\\.(?!00000)[0-9]{5})(?:[vV][1-9][0-9]*)?|[A-Za-z][A-Za-z\\-]*(?:\\.[A-Za-z]{2})?/(?:(?:9[1-9]|0[0-6])(?:0[1-9]|1[0-2])|070[1-3])(?!000)[0-9]{3}(?:[vV][1-9][0-9]*)?)(?:\\.pdf)?)(?![\\s\\S])',
            'priority' => 75,
        ],
        [
            'type' => 'detection',
            'previous' => '^arxiv:',
            'previous_priority' => 20,
            'pattern' => '^(?:[aA][rR][xX][iI][vV]:(?:(?:(?:07(?:0[4-9]|1[0-2])|(?:0[89]|1[0-4])(?:0[1-9]|1[0-2]))\\.(?!0000)[0-9]{4}|(?:1[5-9]|[2-9][0-9]|0[0-6])(?:0[1-9]|1[0-2])\\.(?!00000)[0-9]{5})(?:[vV][1-9][0-9]*)?|[A-Za-z][A-Za-z\\-]*(?:\\.[A-Za-z]{2})?/(?:(?:9[1-9]|0[0-6])(?:0[1-9]|1[0-2])|070[1-3])(?!000)[0-9]{3}(?:[vV][1-9][0-9]*)?))(?![\\s\\S])',
            'priority' => 70,
        ],
        [
            'type' => 'detection',
            'previous' => '^\\d{4}\\.\\d{4,5}(v\\d+)?$',
            'previous_priority' => 10,
            'pattern' => '^(?:(?:(?:07(?:0[4-9]|1[0-2])|(?:0[89]|1[0-4])(?:0[1-9]|1[0-2]))\\.(?!0000)[0-9]{4}|(?:1[5-9]|[2-9][0-9]|0[0-6])(?:0[1-9]|1[0-2])\\.(?!00000)[0-9]{5})(?:[vV][1-9][0-9]*)?)(?![\\s\\S])',
            'priority' => 65,
        ],
        [
            'type' => 'detection',
            'previous' => '^[a-z-]+/\\d{7}$',
            'previous_priority' => 5,
            'pattern' => '^(?:[A-Za-z][A-Za-z\\-]*(?:\\.[A-Za-z]{2})?/(?:(?:9[1-9]|0[0-6])(?:0[1-9]|1[0-2])|070[1-3])(?!000)[0-9]{3}(?:[vV][1-9][0-9]*)?)(?![\\s\\S])',
            'priority' => 65,
        ],
    ],
    'EAN13' => [
        [
            'type' => 'validation',
            'previous' => '^\\d{13}$',
            'previous_priority' => 10,
            'pattern' => '^(?:[0-9]{13}|[uU][rR][nN]:(?:[eE][aA][nN]13|[gG][tT][iI][nN](?:-13)?):[0-9](?:-?[0-9]){12}|[hH][tT][tT][pP][sS]?://(?:[iI][dD][eE][nN][tT][iI][fF][iI][eE][rR][sS]\\.[oO][rR][gG]/[eE][aA][nN]13:|[gG][sS]1\\.[A-Za-z0-9](?:[A-Za-z0-9.\\-]*[A-Za-z0-9])?/01/)[0-9]{13})(?![\\s\\S])',
            'priority' => 10,
        ],
        [
            'type' => 'detection',
            'previous' => '^https?://(?:identifiers\\.org/ean13:|gs1\\.[^/]+/01/)',
            'previous_priority' => 30,
            'pattern' => '^(?:[hH][tT][tT][pP][sS]?://(?:[iI][dD][eE][nN][tT][iI][fF][iI][eE][rR][sS]\\.[oO][rR][gG]/[eE][aA][nN]13:|[gG][sS]1\\.[A-Za-z0-9](?:[A-Za-z0-9.\\-]*[A-Za-z0-9])?/01/)[0-9]{13})(?![\\s\\S])',
            'priority' => 55,
        ],
        [
            'type' => 'detection',
            'previous' => '^urn:(?:ean13|gtin(?:-13)?):[\\d-]+$',
            'previous_priority' => 20,
            'pattern' => '^(?:[uU][rR][nN]:(?:[eE][aA][nN]13|[gG][tT][iI][nN](?:-13)?):[0-9](?:-?[0-9]){12})(?![\\s\\S])',
            'priority' => 55,
        ],
        [
            'type' => 'detection',
            'previous' => '^\\d{13}$',
            'previous_priority' => 10,
            'pattern' => '^(?:[0-9]{13})(?![\\s\\S])',
            'priority' => 40,
        ],
    ],
    'IGSN' => [
        [
            'type' => 'validation',
            'previous' => '^(?:(?:AU|SSH|BGR[A-Z]?|ICDP|CSR[A-Z]?|GFZ|MBCR|ARDC)[A-Z0-9]{2,12}|10\\.(?:60516|58052|60510|58108|58095)/\\S+)$',
            'previous_priority' => 10,
            'pattern' => '^(?:(?:[hH][tT][tT][pP][sS]?://(?:[dD][xX]\\.)?[dD][oO][iI]\\.[oO][rR][gG]/|[dD][oO][iI]:[ ]*|[iI][gG][sS][nN]:?[ ]*)?10\\.[0-9]{4,}(?:\\.[0-9]+)*/\\S+)(?![\\s\\S])',
            'priority' => 10,
        ],
        [
            'type' => 'validation',
            'previous' => '^(?:(?:AU|SSH|BGR[A-Z]?|ICDP|CSR[A-Z]?|GFZ|MBCR|ARDC)[A-Z0-9]{2,12}|10\\.(?:60516|58052|60510|58108|58095)/\\S+)$',
            'previous_priority' => 10,
            'pattern' => '^(?:(?:[uU][rR][nN]:[iI][gG][sS][nN]:|[iI][gG][sS][nN]:?[ ]*|[hH][tT][tT][pP][sS]?://(?:(?:[wW][wW][wW]\\.)?[iI][gG][sS][nN]\\.[oO][rR][gG]/(?:10273/)?|[hH][dD][lL]\\.[hH][aA][nN][dD][lL][eE]\\.[nN][eE][tT]/10273/)|10273/)[A-Za-z0-9][A-Za-z0-9._\\-]{1,63}|(?:[aA][uU]|[sS][sS][hH]|[bB][gG][rR][A-Za-z]?|[iI][cC][dD][pP]|[cC][sS][rR][A-Za-z]?|[gG][fF][zZ]|[mM][bB][cC][rR]|[aA][rR][dD][cC])[A-Za-z0-9]{2,12})(?![\\s\\S])',
            'priority' => 10,
        ],
        [
            'type' => 'detection',
            'previous' => '^https?://(?:doi\\.org|dx\\.doi\\.org)/10\\.(?:60516|58052|60510|58108|58095)/\\S+',
            'previous_priority' => 35,
            'pattern' => '^(?:[hH][tT][tT][pP][sS]?://(?:[dD][xX]\\.)?[dD][oO][iI]\\.[oO][rR][gG]/10\\.(?:25813|57777|57977|57993|58006|58030|58031|58052|58056|58057|58095|58100|58104|58108|58136|58151|60470|60471|60475|60482|60488|60494|60510|60516|60578|60579|60607|60661|60665|60936)/\\S+)(?![\\s\\S])',
            'priority' => 90,
        ],
        [
            'type' => 'detection',
            'previous' => '^https?://igsn\\.org/10\\.273/\\S+',
            'previous_priority' => 30,
            'pattern' => '^(?:[hH][tT][tT][pP][sS]?://(?:(?:[wW][wW][wW]\\.)?[iI][gG][sS][nN]\\.[oO][rR][gG]/(?:10273/)?|[hH][dD][lL]\\.[hH][aA][nN][dD][lL][eE]\\.[nN][eE][tT]/10273/)[A-Za-z0-9][A-Za-z0-9._\\-]{1,63})(?![\\s\\S])',
            'priority' => 90,
        ],
        [
            'type' => 'detection',
            'previous' => '^10\\.(?:60516|58052|60510|58108|58095)/\\S+$',
            'previous_priority' => 25,
            'pattern' => '^(?:10\\.(?:25813|57777|57977|57993|58006|58030|58031|58052|58056|58057|58095|58100|58104|58108|58136|58151|60470|60471|60475|60482|60488|60494|60510|60516|60578|60579|60607|60661|60665|60936)/\\S+|10273/[A-Za-z0-9][A-Za-z0-9._\\-]{1,63})(?![\\s\\S])',
            'priority' => 85,
        ],
        [
            'type' => 'detection',
            'previous' => '^urn:igsn:[A-Za-z0-9]+$',
            'previous_priority' => 20,
            'pattern' => '^(?:[uU][rR][nN]:[iI][gG][sS][nN]:[A-Za-z0-9][A-Za-z0-9._\\-]{1,63})(?![\\s\\S])',
            'priority' => 80,
        ],
        [
            'type' => 'detection',
            'previous' => '^igsn:?\\s*[A-Za-z0-9]+$',
            'previous_priority' => 15,
            'pattern' => '^(?:[iI][gG][sS][nN]:?[ ]*(?:[A-Za-z0-9][A-Za-z0-9._\\-]{1,63}|10\\.[0-9]{4,}(?:\\.[0-9]+)*/\\S+))(?![\\s\\S])',
            'priority' => 80,
        ],
        [
            'type' => 'detection',
            'previous' => '^(?:AU|SSH|BGR[A-Z]?|ICDP|CSR[A-Z]?|GFZ|MBCR|ARDC)[A-Z0-9]{2,12}$',
            'previous_priority' => 10,
            'pattern' => '^(?:(?:[aA][uU]|[sS][sS][hH]|[bB][gG][rR][A-Za-z]?|[iI][cC][dD][pP]|[cC][sS][rR][A-Za-z]?|[gG][fF][zZ]|[mM][bB][cC][rR]|[aA][rR][dD][cC])[A-Za-z0-9]{2,12})(?![\\s\\S])',
            'priority' => 60,
        ],
    ],
    'ISBN' => [
        [
            'type' => 'validation',
            'previous' => '^(?:97[89]\\d{10}|\\d{9}[\\dXx])$',
            'previous_priority' => 10,
            'pattern' => '^(?:(?:[iI][sS][bB][nN](?:-?(?:13|10))?(?::[ ]*|[ ]+)|[uU][rR][nN]:[iI][sS][bB][nN]:|[hH][tT][tT][pP][sS]?://(?:[iI][sS][bB][nN]\\.[oO][pP][eE][nN][eE][dD][iI][tT][iI][oO][nN]\\.[oO][rR][gG]/|[bB][oO][oO][kK][sS]\\.[oO][pP][eE][nN][eE][dD][iI][tT][iI][oO][nN]\\.[oO][rR][gG]/isbn/))?(?:97(?:8|9(?![ \\-]?0))(?:[ \\-]?[0-9]){10}|[0-9](?:[ \\-]?[0-9]){8}[ \\-]?[0-9Xx]))(?![\\s\\S])',
            'priority' => 10,
        ],
        [
            'type' => 'detection',
            'previous' => '^https?://isbn\\.openedition\\.org/97[89]',
            'previous_priority' => 30,
            'pattern' => '^(?:[hH][tT][tT][pP][sS]?://[iI][sS][bB][nN]\\.[oO][pP][eE][nN][eE][dD][iI][tT][iI][oO][nN]\\.[oO][rR][gG]/97(?:8|9(?![ \\-]?0))(?:[ \\-]?[0-9]){10})(?![\\s\\S])',
            'priority' => 70,
        ],
        [
            'type' => 'detection',
            'previous' => '^https?://books\\.openedition\\.org/isbn/97[89]',
            'previous_priority' => 30,
            'pattern' => '^(?:[hH][tT][tT][pP][sS]?://[bB][oO][oO][kK][sS]\\.[oO][pP][eE][nN][eE][dD][iI][tT][iI][oO][nN]\\.[oO][rR][gG]/isbn/97(?:8|9(?![ \\-]?0))(?:[ \\-]?[0-9]){10})(?![\\s\\S])',
            'priority' => 70,
        ],
        [
            'type' => 'detection',
            'previous' => '^urn:isbn:',
            'previous_priority' => 25,
            'pattern' => '^(?:[uU][rR][nN]:[iI][sS][bB][nN]:(?:97(?:8|9(?![ \\-]?0))(?:[ \\-]?[0-9]){10}|[0-9](?:[ \\-]?[0-9]){8}[ \\-]?[0-9Xx]))(?![\\s\\S])',
            'priority' => 65,
        ],
        [
            'type' => 'detection',
            'previous' => '^isbn(?:-?(?:13|10))?[:\\s]+',
            'previous_priority' => 20,
            'pattern' => '^(?:[iI][sS][bB][nN](?:-?(?:13|10))?(?::[ ]*|[ ]+)(?:97(?:8|9(?![ \\-]?0))(?:[ \\-]?[0-9]){10}|[0-9](?:[ \\-]?[0-9]){8}[ \\-]?[0-9Xx]))(?![\\s\\S])',
            'priority' => 65,
        ],
        [
            'type' => 'detection',
            'previous' => '^97[89]\\d{10}$',
            'previous_priority' => 10,
            'pattern' => '^(?:97(?:8|9(?![ \\-]?0))(?:[ \\-]?[0-9]){10})(?![\\s\\S])',
            'priority' => 60,
        ],
        [
            'type' => 'detection',
            'previous' => '^\\d{9}[\\dXx]$',
            'previous_priority' => 5,
            'pattern' => '^(?:[0-9](?:[ \\-]?[0-9]){8}[ \\-]?[0-9Xx])(?![\\s\\S])',
            'priority' => 60,
        ],
    ],
    'RAiD' => [
        [
            'type' => 'validation',
            'previous' => '^https?://raid\\.org/\\S+$',
            'previous_priority' => 10,
            'pattern' => '^(?:(?:[hH][tT][tT][pP][sS]?://[rR][aA][iI][dD]\\.[oO][rR][gG]/|[hH][tT][tT][pP][sS]?://(?:[dD][xX]\\.)?[dD][oO][iI]\\.[oO][rR][gG]/|[dD][oO][iI]:[ ]*)?10\\.[0-9]{4,}(?:\\.[0-9]+)*/\\S+)(?![\\s\\S])',
            'priority' => 10,
        ],
        [
            'type' => 'detection',
            'previous' => '^https?://raid\\.org/\\S+',
            'previous_priority' => 30,
            'pattern' => '^(?:[hH][tT][tT][pP][sS]?://[rR][aA][iI][dD]\\.[oO][rR][gG]/10\\.[0-9]{4,}(?:\\.[0-9]+)*/\\S+)(?![\\s\\S])',
            'priority' => 90,
        ],
        [
            'type' => 'detection',
            'previous' => '^https?://raid\\.org/\\S+',
            'previous_priority' => 30,
            'pattern' => '^(?:(?:[hH][tT][tT][pP][sS]?://(?:[dD][xX]\\.)?[dD][oO][iI]\\.[oO][rR][gG]/|[dD][oO][iI]:[ ]*)?10\\.82210/\\S+)(?![\\s\\S])',
            'priority' => 85,
        ],
    ],
];

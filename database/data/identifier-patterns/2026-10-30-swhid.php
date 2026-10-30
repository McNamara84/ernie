<?php

declare(strict_types=1);

// Immutable SWHID 1.2 syntax filters. Full semantics: POST /api/v1/identifiers/validate.
// Explicit case pairs also work in HTML pattern attributes (which have no i flag).
return [
    [
        'type' => 'validation',
        'previous' => '^swh:1:(?:cnt|dir|rel|rev|snp):[0-9a-f]{40}(?:;.*)?$',
        'previous_priority' => 10,
        'patterns' => [
            ['pattern' => '^(?:[hH][tT][tT][pP][sS]?://(?:[aA][rR][cC][hH][iI][vV][eE]\\.[sS][oO][fF][tT][wW][aA][rR][eE][hH][eE][rR][iI][tT][aA][gG][eE]\\.[oO][rR][gG]/(?:[bB][rR][oO][wW][sS][eE]/)?|(?:[iI][dD][eE][nN][tT][iI][fF][iI][eE][rR][sS]\\.[oO][rR][gG]|[nN]2[tT]\\.[nN][eE][tT])/))?[sS][wW][hH]:1:(?:[cC][nN][tT]|[dD][iI][rR]|[rR][eE][vV]|[rR][eE][lL]|[sS][nN][pP]):[0-9a-fA-F]{40}(?:/|(?:;(?:origin|visit|anchor|path|lines|bytes)=[^;\\s]+)*)(?![\\s\\S])', 'priority' => 10],
        ],
    ],
    [
        'type' => 'detection',
        'previous' => '^swh:1:(?:cnt|dir|rel|rev|snp):[0-9a-f]{40}',
        'previous_priority' => 30,
        'patterns' => [
            ['pattern' => '^[sS][wW][hH]:', 'priority' => 30],
            ['pattern' => '^[hH][tT][tT][pP][sS]?://(?:[aA][rR][cC][hH][iI][vV][eE]\\.[sS][oO][fF][tT][wW][aA][rR][eE][hH][eE][rR][iI][tT][aA][gG][eE]\\.[oO][rR][gG]/(?:[bB][rR][oO][wW][sS][eE]/)?|(?:[iI][dD][eE][nN][tT][iI][fF][iI][eE][rR][sS]\\.[oO][rR][gG]|[nN]2[tT]\\.[nN][eE][tT])/)[sS][wW][hH]:', 'priority' => 30],
        ],
    ],
];

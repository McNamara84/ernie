<?php

declare(strict_types=1);

return [
    'session_secret' => env('COUNTER_SESSION_SECRET', ''),
    'sources' => [
        'counter' => 'https://raw.githubusercontent.com/atmire/COUNTER-Robots/master/COUNTER_Robots_list.json',
        'robots' => 'https://raw.githubusercontent.com/CDLUC3/Make-Data-Count/master/user-agents/lists/robot.txt',
        'machines' => 'https://raw.githubusercontent.com/CDLUC3/Make-Data-Count/master/user-agents/lists/machine.txt',
    ],
    'max_list_bytes' => 2_000_000,
    'methodology_url' => '/usage-statistics',
    'privacy_url' => 'https://dataservices.gfz-potsdam.de/web/about-us/data-protection',
];

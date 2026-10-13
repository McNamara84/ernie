<?php

declare(strict_types=1);

// Stage enables previews in Compose; production keeps the legacy redirects by default.
$enabledByDefault = env('APP_ENV', 'production') !== 'production';

return [
    'home_enabled' => (bool) env('PUBLIC_HOME_ENABLED', $enabledByDefault),
    'find_enabled' => (bool) env('PUBLIC_FIND_ENABLED', $enabledByDefault),
    'data_centres_enabled' => (bool) env('PUBLIC_DATA_CENTRES_ENABLED', $enabledByDefault),
    'data_centre_description_enabled' => (bool) env('PUBLIC_DATA_CENTRE_DESCRIPTION_ENABLED', $enabledByDefault),
];

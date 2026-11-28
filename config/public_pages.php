<?php

declare(strict_types=1);

// Public pages are served locally in every environment. Disabled pages return 404.
return [
    'home_enabled' => (bool) env('PUBLIC_HOME_ENABLED', true),
    'find_enabled' => (bool) env('PUBLIC_FIND_ENABLED', true),
    'data_centres_enabled' => (bool) env('PUBLIC_DATA_CENTRES_ENABLED', true),
    'data_centre_description_enabled' => (bool) env('PUBLIC_DATA_CENTRE_DESCRIPTION_ENABLED', true),
];

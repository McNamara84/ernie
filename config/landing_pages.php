<?php

declare(strict_types=1);

return [
    // Used only until an order (including an empty list) is saved in Editor Settings.
    // Other installations may replace the prefix or use an empty string to disable it.
    'default_download_url_prefix' => env('LANDING_PAGE_DEFAULT_DOWNLOAD_URL_PREFIX', 'https://datapub.gfz.de/download'),
];

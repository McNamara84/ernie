<?php

declare(strict_types=1);

return [
    // Keep serving sample images already stored during the completed migration.
    'disk' => env('IGSN_IMAGE_DISK', 'public'),
];

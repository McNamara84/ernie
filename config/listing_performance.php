<?php

declare(strict_types=1);

return [
    'internal_count_ttl' => max(0, (int) env('LISTING_INTERNAL_COUNT_TTL', 300)),
    'portal_count_ttl' => max(0, (int) env('LISTING_PORTAL_COUNT_TTL', 120)),
    'count_lock_seconds' => max(1, (int) env('LISTING_COUNT_LOCK_SECONDS', 10)),
    'count_lock_wait_seconds' => max(1, (int) env('LISTING_COUNT_LOCK_WAIT_SECONDS', 3)),
];

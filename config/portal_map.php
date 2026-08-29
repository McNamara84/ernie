<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('PORTAL_MAP_ENABLED', true),
    'max_features' => max(100, (int) env('PORTAL_MAP_MAX_FEATURES', 1000)),
    'cluster_radius' => max(20, (int) env('PORTAL_MAP_CLUSTER_RADIUS', 60)),
    'shape_detail_zoom' => min(18, max(0, (int) env('PORTAL_MAP_SHAPE_DETAIL_ZOOM', 10))),
    'cache_ttl' => max(0, (int) env('PORTAL_MAP_CACHE_TTL', 30)),
];

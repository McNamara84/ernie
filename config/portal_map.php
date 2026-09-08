<?php

declare(strict_types=1);

$maxZoom = min(18, max(0, (int) env('PORTAL_MAP_MAX_ZOOM', 18)));

return [
    'enabled' => (bool) env('PORTAL_MAP_ENABLED', true),
    'max_zoom' => $maxZoom,
    'max_features' => max(100, (int) env('PORTAL_MAP_MAX_FEATURES', 1000)),
    'cluster_radius' => max(20, (int) env('PORTAL_MAP_CLUSTER_RADIUS', 60)),
    'shape_detail_zoom' => min(
        $maxZoom,
        max(0, (int) env('PORTAL_MAP_SHAPE_DETAIL_ZOOM', 10)),
    ),
    'cluster_members_per_page' => min(100, max(1, (int) env('PORTAL_MAP_CLUSTER_MEMBERS_PER_PAGE', 50))),
    'igsn_material_visualization_enabled' => (bool) env('PORTAL_IGSN_MAP_MATERIAL_VISUALIZATION_ENABLED', true),
    'cache_ttl' => max(0, (int) env('PORTAL_MAP_CACHE_TTL', 30)),
    'extent_cache_ttl' => max(0, (int) env('PORTAL_MAP_EXTENT_CACHE_TTL', 300)),
];

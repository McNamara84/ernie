<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('caps the configured portal map zoom at the supported tile limit', function (): void {
    $previousMaxZoom = $_SERVER['PORTAL_MAP_MAX_ZOOM'] ?? null;
    $previousShapeDetailZoom = $_SERVER['PORTAL_MAP_SHAPE_DETAIL_ZOOM'] ?? null;

    try {
        $_SERVER['PORTAL_MAP_MAX_ZOOM'] = '99';
        $_SERVER['PORTAL_MAP_SHAPE_DETAIL_ZOOM'] = '99';

        $config = require config_path('portal_map.php');

        expect($config['max_zoom'])->toBe(18)
            ->and($config['shape_detail_zoom'])->toBe(18);
    } finally {
        if ($previousMaxZoom === null) {
            unset($_SERVER['PORTAL_MAP_MAX_ZOOM']);
        } else {
            $_SERVER['PORTAL_MAP_MAX_ZOOM'] = $previousMaxZoom;
        }

        if ($previousShapeDetailZoom === null) {
            unset($_SERVER['PORTAL_MAP_SHAPE_DETAIL_ZOOM']);
        } else {
            $_SERVER['PORTAL_MAP_SHAPE_DETAIL_ZOOM'] = $previousShapeDetailZoom;
        }
    }
});

it('forwards the portal map settings to the app container', function (string $composeFile): void {
    $compose = Yaml::parseFile(base_path($composeFile));

    expect($compose)->toBeArray()
        ->and($compose['services']['app']['environment'] ?? null)
        ->toBeArray()
        ->toContain('PORTAL_MAP_MAX_ZOOM=${PORTAL_MAP_MAX_ZOOM:-18}')
        ->toContain('PORTAL_MAP_CLUSTER_MEMBERS_PER_PAGE=${PORTAL_MAP_CLUSTER_MEMBERS_PER_PAGE:-50}')
        ->toContain('PORTAL_IGSN_MAP_MATERIAL_VISUALIZATION_ENABLED=${PORTAL_IGSN_MAP_MATERIAL_VISUALIZATION_ENABLED:-true}');
})->with([
    'development' => 'docker-compose.dev.yml',
    'stage' => 'docker-compose.stage.yml',
    'production' => 'docker-compose.prod.yml',
]);

it('documents the portal map settings for Docker deployments', function (): void {
    $environment = file_get_contents(base_path('.env.docker.example'));

    expect($environment)->toBeString()
        ->toContain('PORTAL_MAP_MAX_ZOOM=18')
        ->toContain('PORTAL_MAP_CLUSTER_MEMBERS_PER_PAGE=50')
        ->toContain('PORTAL_IGSN_MAP_MATERIAL_VISUALIZATION_ENABLED=true');
});

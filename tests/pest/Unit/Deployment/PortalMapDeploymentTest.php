<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('forwards the IGSN material map flag to the app container', function (string $composeFile): void {
    $compose = Yaml::parseFile(base_path($composeFile));

    expect($compose)->toBeArray()
        ->and($compose['services']['app']['environment'] ?? null)
        ->toBeArray()
        ->toContain('PORTAL_IGSN_MAP_MATERIAL_VISUALIZATION_ENABLED=${PORTAL_IGSN_MAP_MATERIAL_VISUALIZATION_ENABLED:-true}');
})->with([
    'development' => 'docker-compose.dev.yml',
    'stage' => 'docker-compose.stage.yml',
    'production' => 'docker-compose.prod.yml',
]);

it('documents the IGSN material map flag for Docker deployments', function (): void {
    $environment = file_get_contents(base_path('.env.docker.example'));

    expect($environment)->toBeString()
        ->toContain('PORTAL_IGSN_MAP_MATERIAL_VISUALIZATION_ENABLED=true');
});

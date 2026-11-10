<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('forwards DCAT settings to every PHP runtime including inherited workers', function (string $composeFile): void {
    $compose = Yaml::parseFile(base_path($composeFile));
    foreach (['app', 'queue', 'curation-queue', 'assistance-queue', 'elmo-cache-refresh', 'assessment-queue', 'scheduler'] as $service) {
        expect($compose['services'][$service]['environment'])
            ->toContain('DCATAP_ENABLED=${DCATAP_ENABLED:-false}')
            ->toContain('DCATAP_CATALOG_URI=${DCATAP_CATALOG_URI:-https://dataservices.gfz.de/}')
            ->toContain('DCATAP_CATALOG_TITLE=${DCATAP_CATALOG_TITLE:-GFZ Data Services}')
            ->toContain('DCATAP_CATALOG_DESCRIPTION=${DCATAP_CATALOG_DESCRIPTION:-Research data published by GFZ Data Services.}')
            ->toContain('DCATAP_CATALOG_PUBLISHER=${DCATAP_CATALOG_PUBLISHER:-GFZ Data Services}');
    }
})->with(['docker-compose.dev.yml', 'docker-compose.stage.yml', 'docker-compose.prod.yml']);

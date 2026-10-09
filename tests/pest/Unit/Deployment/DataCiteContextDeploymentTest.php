<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('forwards an optional compatible JSON-LD context mirror to the application and workers', function (string $composeFile): void {
    $compose = Yaml::parseFile(base_path($composeFile));

    foreach (['app', 'queue', 'assessment-queue', 'scheduler'] as $service) {
        expect($compose['services'][$service]['environment'])
            ->toContain('DATACITE_LINKED_DATA_CONTEXT_URL=${DATACITE_LINKED_DATA_CONTEXT_URL:-}');
    }
})->with([
    'development' => 'docker-compose.dev.yml',
    'stage' => 'docker-compose.stage.yml',
    'production' => 'docker-compose.prod.yml',
]);

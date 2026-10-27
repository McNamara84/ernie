<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('forwards the ISO JSON preview switch to every Laravel runtime', function (string $composeFile) {
    $compose = Yaml::parseFile(base_path($composeFile));
    $services = ['app', 'queue', 'curation-queue', 'assistance-queue', 'elmo-cache-refresh', 'assessment-queue', 'scheduler'];
    if ($composeFile === 'docker-compose.dev.yml') {
        $services[] = 'vite';
    }

    foreach ($services as $service) {
        expect($compose['services'][$service]['environment'])
            ->toContain('ISO19115_JSON_ENABLED=${ISO19115_JSON_ENABLED:-true}');
    }
})->with([
    'development' => 'docker-compose.dev.yml',
    'stage' => 'docker-compose.stage.yml',
    'production' => 'docker-compose.prod.yml',
]);

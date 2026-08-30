<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('forwards and consumes the configurable DataCite queue in every Docker environment', function (string $composeFile): void {
    $contents = file_get_contents(base_path($composeFile));
    if ($contents === false) {
        throw new RuntimeException("Failed to read deployment file: {$composeFile}");
    }

    $compose = Yaml::parse($contents);
    if (! is_array($compose)) {
        throw new RuntimeException("Compose file did not parse to an array: {$composeFile}");
    }

    $command = $compose['services']['queue']['command'] ?? null;
    expect($command)->toBeString();

    expect($command)->toContain('--queue=${DATACITE_QUEUE:-datacite},');

    foreach (['app', 'queue'] as $service) {
        $environment = $compose['services'][$service]['environment'] ?? [];
        expect($environment)
            ->toBeArray()
            ->toContain('DATACITE_QUEUE=${DATACITE_QUEUE:-datacite}');
    }
})->with([
    'development' => 'docker-compose.dev.yml',
    'stage' => 'docker-compose.stage.yml',
    'production' => 'docker-compose.prod.yml',
]);

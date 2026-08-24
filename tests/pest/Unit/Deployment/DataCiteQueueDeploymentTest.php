<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('consumes the dedicated DataCite queue in every Docker environment', function (string $composeFile): void {
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

    preg_match('/(?:^|\s)--queue=([^\s]+)/', $command, $matches);
    $queues = isset($matches[1]) ? explode(',', $matches[1]) : [];

    expect($queues)->toContain('datacite');
})->with([
    'development' => 'docker-compose.dev.yml',
    'stage' => 'docker-compose.stage.yml',
    'production' => 'docker-compose.prod.yml',
]);

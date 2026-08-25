<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * @return array<string, mixed>
 */
function igsnCompose(string $composeFile): array
{
    $contents = file_get_contents(base_path($composeFile));
    if ($contents === false) {
        throw new RuntimeException("Failed to read deployment file: {$composeFile}");
    }

    $compose = Yaml::parse($contents);
    if (! is_array($compose)) {
        throw new RuntimeException("Compose file did not parse to an array: {$composeFile}");
    }

    return $compose;
}

it('runs IGSN imports asynchronously in every Docker environment', function (string $composeFile): void {
    $compose = igsnCompose($composeFile);
    $command = $compose['services']['queue']['command'] ?? null;
    expect($command)->toBeString();

    preg_match('/(?:^|\s)--queue=([^\s]+)/', $command, $matches);
    $queues = isset($matches[1]) ? explode(',', $matches[1]) : [];

    expect($queues)->toContain('imports');

    $portalEnvironmentByService = [];
    foreach (['app', 'queue'] as $service) {
        $environment = $compose['services'][$service]['environment'] ?? [];
        expect($environment)
            ->toBeArray()
            ->toContain('QUEUE_CONNECTION=database');

        $portalEnvironmentByService[$service] = array_values(array_filter(
            $environment,
            fn (mixed $value): bool => is_string($value) && str_starts_with($value, 'GFZ_IGSN_PORTAL_'),
        ));
        sort($portalEnvironmentByService[$service]);

        expect($portalEnvironmentByService[$service])->toHaveCount(8);
    }

    expect($portalEnvironmentByService['queue'])->toBe($portalEnvironmentByService['app']);
})->with([
    'development' => 'docker-compose.dev.yml',
    'stage' => 'docker-compose.stage.yml',
    'production' => 'docker-compose.prod.yml',
]);

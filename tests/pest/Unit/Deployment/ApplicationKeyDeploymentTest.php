<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('requires the same deployment key for every stage and production PHP service', function (string $file): void {
    $compose = Yaml::parseFile(base_path($file));
    $services = array_filter($compose['services'], static fn (array $service): bool => str_contains($service['image'] ?? '', 'ernie-app'));
    expect(count($services))->toBeGreaterThanOrEqual(4);

    foreach ($services as $service) {
        $keys = array_values(array_filter($service['environment'], static fn (string $entry): bool => str_starts_with($entry, 'APP_KEY=')));
        expect($keys)->toHaveCount(1)
            ->and($keys[0])->toMatch('/^APP_KEY=\$\{APP_KEY:\?[^}]+\}$/');
    }
})->with(['docker-compose.stage.yml', 'docker-compose.prod.yml']);

it('lets every development PHP service load the same environment file', function (): void {
    $compose = Yaml::parseFile(base_path('docker-compose.dev.yml'));
    $services = array_filter($compose['services'], static fn (array $service): bool => ($service['build']['dockerfile'] ?? null) === 'Dockerfile.dev'
        && ($service['build']['target'] ?? null) === 'app');
    expect(count($services))->toBeGreaterThanOrEqual(4);

    foreach ($services as $service) {
        expect($service['volumes'])->toContain('.:/var/www/html')
            ->and(array_filter($service['environment'], static fn (string $entry): bool => str_starts_with($entry, 'APP_KEY=')))->toBe([]);
    }
});

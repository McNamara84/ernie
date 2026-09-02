<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * @return array<string, mixed>
 */
function schedulerCompose(string $composeFile): array
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

function schedulerCommand(mixed $command): string
{
    if (is_string($command)) {
        return $command;
    }

    if (is_array($command)) {
        return implode("\n", array_map('strval', $command));
    }

    throw new RuntimeException('Scheduler command must be a string or list.');
}

/**
 * @return array<string, string>
 */
function schedulerEnvironment(mixed $environment): array
{
    if (! is_array($environment)) {
        throw new RuntimeException('Scheduler environment must be a list.');
    }

    $values = [];

    foreach ($environment as $entry) {
        if (! is_string($entry) || ! str_contains($entry, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $entry, 2);
        $values[$name] = $value;
    }

    return $values;
}

it('runs a self-contained Laravel scheduler in every Docker environment', function (string $composeFile): void {
    $compose = schedulerCompose($composeFile);
    $services = $compose['services'] ?? null;

    expect($services)->toBeArray()->toHaveKeys(['app', 'scheduler']);

    /** @var array<string, mixed> $services */
    $app = $services['app'];
    $scheduler = $services['scheduler'];
    $command = schedulerCommand($scheduler['command'] ?? null);

    expect($command)
        ->toContain('set -e')
        ->toContain('php artisan rights:update-usage-count --no-interaction')
        ->toContain('exec php artisan schedule:work --no-interaction')
        ->and($scheduler['restart'] ?? null)->toBe('unless-stopped')
        ->and($scheduler['profiles'] ?? null)->toBeNull()
        ->and($scheduler['build']['dockerfile'] ?? null)->toBe($app['build']['dockerfile'] ?? null)
        ->and($scheduler['build']['target'] ?? null)->toBe($app['build']['target'] ?? null)
        ->and($scheduler['networks'] ?? null)->toBe($app['networks'] ?? null);

    $dependencies = $scheduler['depends_on'] ?? null;
    expect($dependencies)->toBeArray()->toHaveKeys(['app', 'db', 'redis'])
        ->and($dependencies['app']['condition'] ?? null)->toBe('service_healthy')
        ->and($dependencies['db']['condition'] ?? null)->toBe('service_healthy');

    expect($scheduler['volumes'] ?? null)->toBeArray()->not->toBeEmpty();

    $appEnvironment = schedulerEnvironment($app['environment'] ?? null);
    $schedulerEnvironment = schedulerEnvironment($scheduler['environment'] ?? null);

    foreach ([
        'APP_ENV',
        'APP_DEBUG',
        'APP_URL',
        'DB_HOST',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_PASSWORD',
        'REDIS_HOST',
        'CACHE_STORE',
        'QUEUE_CONNECTION',
        'DATACITE_QUEUE',
    ] as $variable) {
        expect($schedulerEnvironment)
            ->toHaveKey($variable, $appEnvironment[$variable] ?? null);
    }
})->with([
    'development' => 'docker-compose.dev.yml',
    'stage' => 'docker-compose.stage.yml',
    'production' => 'docker-compose.prod.yml',
]);

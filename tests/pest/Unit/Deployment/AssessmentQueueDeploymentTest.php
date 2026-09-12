<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/** @return array<string, mixed> */
function assessmentCompose(string $composeFile): array
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

it('isolates FAIR assessments in two configurable persistent workers', function (string $composeFile): void {
    $compose = assessmentCompose($composeFile);
    $service = $compose['services']['assessment-queue'] ?? null;

    expect($service)->toBeArray()
        ->and($service['deploy']['replicas'] ?? null)->toBe('${FUJI_ASSESSMENT_CONCURRENCY:-1}');

    $command = $service['command'] ?? null;
    expect($command)->toBeString()
        ->toContain('queue:work ${FUJI_ASSESSMENT_QUEUE_CONNECTION:-assessment}')
        ->toContain('--queue=${FUJI_ASSESSMENT_QUEUE:-assessments}')
        ->toContain('--tries=1')
        ->toContain('--timeout=${FUJI_ASSESSMENT_ITEM_TIMEOUT:-330}');

    $environment = $service['environment'] ?? [];
    expect($environment)
        ->toBeArray()
        ->toContain('QUEUE_CONNECTION=database')
        ->toContain('CACHE_STORE=redis')
        ->toContain('FUJI_ASSESSMENT_QUEUE_CONNECTION=${FUJI_ASSESSMENT_QUEUE_CONNECTION:-assessment}')
        ->toContain('FUJI_ASSESSMENT_QUEUE_RETRY_AFTER=${FUJI_ASSESSMENT_QUEUE_RETRY_AFTER:-390}')
        ->toContain('FUJI_ASSESSMENT_LEASE_SECONDS=${FUJI_ASSESSMENT_LEASE_SECONDS:-390}')
        ->toContain('FUJI_ASSESSMENT_REQUESTS_PER_MINUTE=${FUJI_ASSESSMENT_REQUESTS_PER_MINUTE:-80}')
        ->toContain('FUJI_ASSESSMENT_MINIMUM_INTERVAL_MS=${FUJI_ASSESSMENT_MINIMUM_INTERVAL_MS:-750}');
})->with([
    'development' => 'docker-compose.dev.yml',
    'stage' => 'docker-compose.stage.yml',
    'production' => 'docker-compose.prod.yml',
]);

it('includes FAIR assessment services in runtime stacks without optional profiles', function (string $composeFile): void {
    $services = assessmentCompose($composeFile)['services'];

    expect($services)->toHaveKeys(['fuji', 'assessment-queue'])
        ->and($services['fuji'])->not->toHaveKey('profiles')
        ->and($services['assessment-queue'])->not->toHaveKey('profiles');
})->with([
    'stage' => 'docker-compose.stage.yml',
    'production' => 'docker-compose.prod.yml',
]);

it('keeps local FAIR assessment services behind explicit development profiles', function (): void {
    $services = assessmentCompose('docker-compose.dev.yml')['services'];

    expect($services['fuji']['profiles'] ?? null)->toBe(['assessment', 'parity'])
        ->and($services['assessment-queue']['profiles'] ?? null)->toBe(['assessment', 'parity']);
});

it('does not let the general worker bypass assessment concurrency and rate limits', function (string $composeFile): void {
    $command = assessmentCompose($composeFile)['services']['queue']['command'] ?? null;

    expect($command)->toBeString()
        ->not->toContain('assessments')
        ->not->toContain('FUJI_ASSESSMENT_QUEUE');
})->with([
    'development' => 'docker-compose.dev.yml',
    'stage' => 'docker-compose.stage.yml',
    'production' => 'docker-compose.prod.yml',
]);

it('forwards the assessment queue identity and limiter settings to every app container', function (string $composeFile): void {
    $compose = assessmentCompose($composeFile);
    $environment = $compose['services']['app']['environment'] ?? [];
    $schedulerEnvironment = $compose['services']['scheduler']['environment'] ?? [];

    expect($environment)
        ->toBeArray()
        ->toContain('FUJI_ASSESSMENT_QUEUE_CONNECTION=${FUJI_ASSESSMENT_QUEUE_CONNECTION:-assessment}')
        ->toContain('FUJI_ASSESSMENT_QUEUE=${FUJI_ASSESSMENT_QUEUE:-assessments}')
        ->toContain('FUJI_ASSESSMENT_CONCURRENCY=${FUJI_ASSESSMENT_CONCURRENCY:-1}')
        ->toContain('FUJI_ASSESSMENT_REQUESTS_PER_MINUTE=${FUJI_ASSESSMENT_REQUESTS_PER_MINUTE:-80}')
        ->toContain('FUJI_ASSESSMENT_WINDOW_SECONDS=${FUJI_ASSESSMENT_WINDOW_SECONDS:-60}')
        ->toContain('FUJI_ASSESSMENT_MINIMUM_INTERVAL_MS=${FUJI_ASSESSMENT_MINIMUM_INTERVAL_MS:-750}');

    expect($schedulerEnvironment)
        ->toBeArray()
        ->toContain('FUJI_ASSESSMENT_QUEUE_CONNECTION=${FUJI_ASSESSMENT_QUEUE_CONNECTION:-assessment}')
        ->toContain('FUJI_ASSESSMENT_QUEUE=${FUJI_ASSESSMENT_QUEUE:-assessments}');
})->with([
    'development' => 'docker-compose.dev.yml',
    'stage' => 'docker-compose.stage.yml',
    'production' => 'docker-compose.prod.yml',
]);

<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

function runtimeDeploymentCompose(string $filename): array
{
    $parsed = Yaml::parseFile(base_path($filename));

    expect($parsed)->toBeArray()->toHaveKey('services');

    return $parsed;
}

it('does not declare action closures in application route files', function (): void {
    foreach (['routes/web.php', 'routes/settings.php'] as $filename) {
        $routes = file_get_contents(base_path($filename));

        expect($routes)->toBeString()
            ->not->toMatch('/Route::(?:get|post|put|patch|delete|options|any|match)\([^\n;]*function\s*\(/');
    }
});

it('keeps MySQL 9.7 and explicit resource tuning in stage and production', function (string $filename): void {
    $compose = runtimeDeploymentCompose($filename);
    $database = $compose['services']['db'];
    $environment = implode("\n", $compose['services']['app']['environment']);

    expect($database['image'])->toStartWith('mysql:9.7.')
        ->and($database['command'])->toContain(
            '--innodb-buffer-pool-size=${MYSQL_INNODB_BUFFER_POOL_SIZE:-'.($filename === 'docker-compose.stage.yml' ? '1G' : '3G').'}',
        )
        ->and($database['command'])->toContain(
            '--max-connections=${MYSQL_MAX_CONNECTIONS:-'.($filename === 'docker-compose.stage.yml' ? '40' : '60').'}',
        )
        ->and($environment)->toContain('PHP_FPM_MAX_CHILDREN=')
        ->toContain('PHP_FPM_START_SERVERS=')
        ->toContain('PHP_FPM_MIN_SPARE_SERVERS=')
        ->toContain('PHP_FPM_MAX_SPARE_SERVERS=')
        ->toContain('PHP_FPM_MAX_REQUESTS=');
})->with([
    'stage' => 'docker-compose.stage.yml',
    'production' => 'docker-compose.prod.yml',
]);

it('starts F-UJI only through the assessment profile', function (string $filename): void {
    $compose = runtimeDeploymentCompose($filename);

    expect($compose['services']['fuji']['profiles'] ?? null)->toBe(['assessment']);
})->with([
    'stage' => 'docker-compose.stage.yml',
    'production' => 'docker-compose.prod.yml',
]);

it('isolates bootstrap caches and assigns migrations to the app role', function (string $filename): void {
    $compose = runtimeDeploymentCompose($filename);
    $services = $compose['services'];

    foreach (['app', 'queue', 'scheduler'] as $serviceName) {
        $volumes = $services[$serviceName]['volumes'] ?? [];
        expect(implode("\n", $volumes))->not->toContain('bootstrap/cache');
    }

    expect($services['app']['environment'])->toContain('ERNIE_RUN_MIGRATIONS=1')
        ->and($services['queue']['environment'])->toContain('ERNIE_RUN_MIGRATIONS=0')
        ->and($services['scheduler']['environment'])->toContain('ERNIE_RUN_MIGRATIONS=0');
})->with([
    'stage' => 'docker-compose.stage.yml',
    'production' => 'docker-compose.prod.yml',
]);

it('verifies the bundled production OPcache with immutable-code settings', function (): void {
    $dockerfile = file_get_contents(base_path('Dockerfile'));
    $opcache = file_get_contents(base_path('docker/php/opcache-production.ini'));

    expect($dockerfile)->toBeString()
        ->toContain('COPY docker/php/opcache-production.ini')
        ->toContain('RUN php --ri "Zend OPcache"')
        ->not->toMatch('/docker-php-ext-install[^\n]*opcache/')
        ->and($opcache)->toBeString()
        ->toContain('opcache.enable=1')
        ->toContain('opcache.validate_timestamps=0')
        ->toContain('opcache.jit=off');
});

it('builds Laravel production caches without swallowing optimization errors', function (): void {
    $entrypoint = file_get_contents(base_path('docker-entrypoint.sh'));

    expect($entrypoint)->toBeString()
        ->toContain('php artisan optimize --no-interaction')
        ->not->toMatch('/php artisan optimize --no-interaction[^\n]*(?:\|\| true|2>\/dev\/null)/')
        ->toContain('ERNIE_RUN_MIGRATIONS')
        ->toContain('ERROR: Migration failed after ${MAX_MIGRATION_ATTEMPTS} attempts')
        ->toContain('Refusing to start the application against an outdated schema')
        ->not->toContain('Container will continue to start')
        ->toContain('php-fpm -tt');
});

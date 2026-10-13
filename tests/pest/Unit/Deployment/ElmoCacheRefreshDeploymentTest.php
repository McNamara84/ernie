<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('routes each deployment to the intended ELMO pair with a separate long-running worker', function (string $file, string $host): void {
    $compose = Yaml::parseFile(base_path($file));
    $services = $compose['services'];
    $worker = $services['elmo-cache-refresh'];
    expect($worker['command'])->toContain('queue:work elmo', '--queue=elmo-cache-refresh', '--tries=3', '--timeout=${ELMO_CACHE_REFRESH_JOB_TIMEOUT:-330}')
        ->and($worker['stop_grace_period'])->toBe('360s')
        ->and($worker['container_name'])->not->toBe($services['queue']['container_name'])
        ->and($services['queue']['command'])->not->toContain('elmo-cache-refresh');
    foreach (['app', 'queue', 'scheduler', 'elmo-cache-refresh'] as $name) {
        $env = $services[$name]['environment'];
        expect($env)->toContain('ELMO_BASE_URL=${ELMO_BASE_URL:-'.$host.'/elmo}',
            'ELMO_CACHE_REFRESH_ENABLED=${ELMO_CACHE_REFRESH_ENABLED:-false}',
            'ELMO_MSL_CACHE_REFRESH_READY=${ELMO_MSL_CACHE_REFRESH_READY:-false}',
            'ELMO_MSL_BASE_URL=${ELMO_MSL_BASE_URL:-'.$host.'/elmo-msl}',
            'ELMO_API_KEY=${ELMO_API_KEY:-}', 'ELMO_MSL_API_KEY=${ELMO_MSL_API_KEY:-}',
            'ELMO_CACHE_REFRESH_RETRY_AFTER=${ELMO_CACHE_REFRESH_RETRY_AFTER:-390}');
    }
})->with([
    ['docker-compose.dev.yml', 'https://env.rz-vm182.gfz.de'],
    ['docker-compose.stage.yml', 'https://env.rz-vm182.gfz.de'],
    ['docker-compose.prod.yml', 'https://dataservices.gfz.de'],
]);

it('uses a persistent queue whose reservation exceeds both HTTP and job timeouts', function (): void {
    expect(config('queue.connections.elmo.driver'))->toBe('database')
        ->and(config('elmo.connect_timeout'))->toBeLessThanOrEqual(config('elmo.timeout'))
        ->and(config('elmo.timeout'))->toBeLessThan(config('elmo.job_timeout'))
        ->and(config('elmo.job_timeout'))->toBeLessThan(config('queue.connections.elmo.retry_after'));
});

it('requires an explicit global opt in and a separate MSL readiness opt in', function (): void {
    // Evaluate actual defaults rather than PHPUnit's forced disablement.
    foreach (['ELMO_CACHE_REFRESH_ENABLED', 'ELMO_MSL_CACHE_REFRESH_READY'] as $variable) {
        $value = getenv($variable);
        $envValue = $_ENV[$variable] ?? null;
        $serverValue = $_SERVER[$variable] ?? null;
        putenv($variable);
        unset($_ENV[$variable], $_SERVER[$variable]);
        $this->beforeApplicationDestroyed(function () use ($variable, $value, $envValue, $serverValue): void {
            if ($value !== false) {
                putenv("{$variable}={$value}");
            }
            if ($envValue !== null) {
                $_ENV[$variable] = $envValue;
            }
            if ($serverValue !== null) {
                $_SERVER[$variable] = $serverValue;
            }
        });
    }
    $defaults = require base_path('config/elmo.php');
    expect($defaults['enabled'])->toBeFalse()
        ->and($defaults['targets']['elmo-msl']['ready'])->toBeFalse();
    foreach (['.env.example', '.env.docker.example'] as $file) {
        $contents = file_get_contents(base_path($file));
        expect($contents)->toContain('ELMO_CACHE_REFRESH_ENABLED=false', 'ELMO_MSL_CACHE_REFRESH_READY=false')
            ->not->toContain('ELMO_CACHE_REFRESH_ENABLED=true', 'ELMO_MSL_CACHE_REFRESH_READY=true');
    }
});

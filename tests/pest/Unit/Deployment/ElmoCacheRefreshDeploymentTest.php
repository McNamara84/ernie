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

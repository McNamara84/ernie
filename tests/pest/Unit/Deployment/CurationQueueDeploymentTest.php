<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('consumes the application database curation queue in every deployment', function (string $file): void {
    $services = Yaml::parseFile(base_path($file))['services'];
    $worker = $services['curation-queue'];
    expect($worker['command'])->toContain('queue:work curation', '--queue=curation', '--tries=3', '--timeout=60')
        ->and($worker['stop_grace_period'])->toBe('75s')
        ->and($worker['restart'])->toBe('unless-stopped')
        ->and($worker)->not->toHaveKey('profiles')
        ->and($worker['container_name'])->not->toBe($services['queue']['container_name'])
        ->and($worker['environment'])->toBe($services['queue']['environment'])
        ->and($worker['depends_on'])->toBe($services['queue']['depends_on'])
        ->and($worker['volumes'])->toBe($services['queue']['volumes'])
        ->and($worker['networks'])->toBe($services['queue']['networks'])
        ->and($worker['image'] ?? $worker['build'])->toBe($services['queue']['image'] ?? $services['queue']['build'])
        ->and($services['queue']['command'])->not->toContain('curation');

    expect(config('queue.connections.curation.driver'))->toBe('database')
        ->and(config('queue.connections.curation.connection'))->toBeNull()
        ->and(config('queue.connections.curation.table'))->toBe('jobs')
        ->and(config('queue.connections.curation.queue'))->toBe('curation')
        ->and(config('queue.connections.curation.after_commit'))->toBeFalse()
        ->and(config('queue.connections.curation.retry_after'))->toBeGreaterThan(75);
})->with(['docker-compose.dev.yml', 'docker-compose.stage.yml', 'docker-compose.prod.yml']);

<?php

declare(strict_types=1);

use App\Jobs\DiscoverAssistantSuggestionsJob;
use App\Jobs\DiscoverOrcidsJob;
use App\Jobs\DiscoverRelationsJob;
use App\Jobs\DiscoverRorsJob;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

it('isolates discovery with a dedicated bounded consumer in every deployment', function (string $file) {
    $compose = Yaml::parseFile(base_path($file));
    $service = $compose['services']['assistance-queue'];
    expect($service['command'])->toContain('queue:work assistance --queue=${ASSISTANCE_QUEUE:-assistance}')
        ->and($service['cpus'])->toBe('${ASSISTANCE_WORKER_CPUS:-0.5}')
        ->and($compose['services']['queue']['command'])->not->toContain('--queue=assistance');
    foreach (['app', 'queue', 'assistance-queue', 'scheduler'] as $name) {
        expect($compose['services'][$name]['environment'])
            ->toContain('ASSISTANCE_QUEUE=${ASSISTANCE_QUEUE:-assistance}')
            ->toContain('ASSISTANCE_JOB_TIMEOUT=${ASSISTANCE_JOB_TIMEOUT:-3600}')
            ->toContain('ASSISTANCE_QUEUE_RETRY_AFTER=${ASSISTANCE_QUEUE_RETRY_AFTER:-3660}')
            ->toContain('DB_QUEUE_RETRY_AFTER=${DB_QUEUE_RETRY_AFTER:-7260}');
    }
})->with(['docker-compose.dev.yml', 'docker-compose.stage.yml', 'docker-compose.prod.yml']);

it('routes legacy and generic discovery jobs to the persistent assistance connection', function (string $jobClass) {
    config(['queue.connections.assistance.queue' => 'custom-assistance', 'queue.connections.assistance.job_timeout' => 300]);
    $job = $jobClass === DiscoverAssistantSuggestionsJob::class
        ? new $jobClass('size-format-suggestion', (string) Str::uuid())
        : new $jobClass((string) Str::uuid());
    expect($job->connection)->toBe('assistance')
        ->and($job->queue)->toBe('custom-assistance')
        ->and($job->timeout)->toBeLessThanOrEqual(300)
        ->and(config('queue.connections.assistance.driver'))->toBe('database')
        ->and(config('queue.connections.assistance.after_commit'))->toBeTrue()
        ->and(config('queue.connections.assistance.retry_after'))->toBeGreaterThan($job->timeout);
})->with([DiscoverAssistantSuggestionsJob::class, DiscoverOrcidsJob::class, DiscoverRorsJob::class, DiscoverRelationsJob::class]);

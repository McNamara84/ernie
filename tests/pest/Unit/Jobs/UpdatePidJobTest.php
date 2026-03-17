<?php

declare(strict_types=1);

use App\Jobs\UpdatePidJob;
use App\Models\PidSetting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

covers(UpdatePidJob::class);

beforeEach(function (): void {
    Cache::flush();
});

test('constructor rejects invalid PID type', function (): void {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('Invalid PID type');

    new UpdatePidJob('invalid_type', Str::uuid()->toString());
});

test('constructor rejects invalid job ID format', function (): void {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('Invalid jobId format');

    new UpdatePidJob('pid4inst', 'not-a-uuid');
});

test('getCacheKey returns expected format', function (): void {
    $jobId = Str::uuid()->toString();
    expect(UpdatePidJob::getCacheKey($jobId))->toBe("pid_update:{$jobId}");
});

test('handle sets running status in cache', function (): void {
    $jobId = Str::uuid()->toString();

    PidSetting::create([
        'type' => 'pid4inst',
        'display_name' => 'PID4INST Instruments',
        'is_active' => true,
        'is_elmo_active' => false,
    ]);

    Artisan::shouldReceive('call')
        ->once()
        ->andReturn(0);

    Artisan::shouldReceive('output')
        ->never();

    $job = new UpdatePidJob('pid4inst', $jobId);
    $job->handle();

    $cached = Cache::get(UpdatePidJob::getCacheKey($jobId));
    expect($cached)->toBeArray()
        ->and($cached['status'])->toBe('completed')
        ->and($cached['pidType'])->toBe('pid4inst');
});

test('handle marks cache as failed on non-zero exit code', function (): void {
    $jobId = Str::uuid()->toString();

    PidSetting::create([
        'type' => 'pid4inst',
        'display_name' => 'PID4INST Instruments',
        'is_active' => true,
        'is_elmo_active' => false,
    ]);

    Artisan::shouldReceive('call')
        ->once()
        ->andReturn(1);

    Artisan::shouldReceive('output')
        ->once()
        ->andReturn('Some error output');

    $job = new UpdatePidJob('pid4inst', $jobId);
    $job->handle();

    $cached = Cache::get(UpdatePidJob::getCacheKey($jobId));
    expect($cached)->toBeArray()
        ->and($cached['status'])->toBe('failed')
        ->and($cached['error'])->toContain('exited with code 1');
});

test('handle marks cache as failed on exception', function (): void {
    $jobId = Str::uuid()->toString();

    PidSetting::create([
        'type' => 'pid4inst',
        'display_name' => 'PID4INST Instruments',
        'is_active' => true,
        'is_elmo_active' => false,
    ]);

    Artisan::shouldReceive('call')
        ->once()
        ->andThrow(new RuntimeException('Connection failed'));

    $job = new UpdatePidJob('pid4inst', $jobId);

    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'Connection failed');

    $cached = Cache::get(UpdatePidJob::getCacheKey($jobId));
    expect($cached['status'])->toBe('failed')
        ->and($cached['error'])->toBe('Connection failed');
});

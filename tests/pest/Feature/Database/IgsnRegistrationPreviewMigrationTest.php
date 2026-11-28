<?php

declare(strict_types=1);

use App\Models\IgsnMetadata;
use App\Models\IgsnRegistrationRun;
use App\Models\Resource;
use App\Models\ResourceDoiPublication;
use Illuminate\Support\Str;

uses()->group('database', 'mysql-sensitive');

function recordHistoricalIgsnPublication(IgsnMetadata $sample, ?string $doi, bool $testMode): void
{
    ResourceDoiPublication::create([
        'uuid' => Str::uuid()->toString(), 'resource_id' => $sample->resource_id,
        'test_mode' => $testMode, 'endpoint' => 'https://example.test', 'repository' => 'igsn.repo',
        'prefix' => $doi === null ? '10.60510' : explode('/', $doi, 2)[0], 'doi' => $doi,
        'target_url' => 'https://example.test/sample', 'metadata_hash' => str_repeat('a', 64),
        'payload' => [], 'status' => 'succeeded',
    ]);
}

test('the preview migration distinguishes live, sandbox, and unverifiable historical registrations', function (): void {
    $migration = require database_path('migrations/2026_11_27_000001_add_igsn_registration_previews.php');
    $migration->down();

    $samples = [];
    foreach (['live', 'sandbox', 'both', 'unknown', 'changed', 'failed', 'uploaded', 'missing-doi'] as $name) {
        $resource = Resource::factory()->create(['doi' => $name === 'missing-doi' ? null : '10.60510/'.$name]);
        $samples[$name] = IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => $name === 'uploaded' ? 'uploaded' : 'registered',
        ]);
    }

    foreach ([['live', false], ['both', true], ['changed', false], ['uploaded', true]] as [$name, $testMode]) {
        recordHistoricalIgsnPublication($samples[$name], '10.60510/'.($name === 'changed' ? 'previous' : $name), $testMode);
    }
    recordHistoricalIgsnPublication($samples['missing-doi'], null, false);
    foreach ([['both', false, 'updated'], ['sandbox', true, 'registered'], ['failed', false, 'failed']] as [$name, $testMode, $status]) {
        $run = IgsnRegistrationRun::create(['status' => 'completed', 'test_mode' => $testMode, 'datacite_endpoint' => 'https://example.test']);
        $run->items()->create(['resource_id' => $samples[$name]->resource_id, 'identifier' => '10.60510/'.$name, 'status' => $status]);
    }
    $migration->up();

    foreach ($samples as $name => $sample) {
        $status = match ($name) {
            'live', 'both' => 'registered',
            'uploaded' => 'uploaded',
            default => 'unverified',
        };
        expect($sample->refresh()->upload_status)->toBe($status)
            ->and($sample->simulated_registered_at !== null)->toBe($name === 'sandbox');
    }
    expect(IgsnRegistrationRun::where('is_simulation', true)->exists())->toBeFalse();
});

test('the backfill matches legacy handles to their assigned DOI only within the configured IGSN prefix', function (): void {
    config(['datacite.production.igsn_prefix' => '10.98765']);
    $migration = require database_path('migrations/2026_11_27_000001_add_igsn_registration_previews.php');
    $migration->down();

    $samples = [];
    foreach (['ICDP1001', 'ICDP1002', 'ICDP1003', '10.98765/icdp1004', 'ICDP1005', 'ICDP1006'] as $identifier) {
        $resource = Resource::factory()->create(['doi' => $identifier]);
        $samples[$identifier] = IgsnMetadata::create(['resource_id' => $resource->id, 'upload_status' => 'registered']);
    }
    recordHistoricalIgsnPublication($samples['ICDP1001'], '10.98765/icdp1001', false);
    recordHistoricalIgsnPublication($samples['ICDP1001'], '10.98765/ICDP1001', true);
    recordHistoricalIgsnPublication($samples['ICDP1002'], '10.98765/icdp1002', true);
    recordHistoricalIgsnPublication($samples['ICDP1003'], '10.60510/icdp1003', false);
    recordHistoricalIgsnPublication($samples['ICDP1006'], '10.98765/icdp1003', false);

    $run = IgsnRegistrationRun::create(['status' => 'completed', 'test_mode' => false, 'datacite_endpoint' => 'https://example.test']);
    $run->items()->create(['resource_id' => $samples['10.98765/icdp1004']->resource_id, 'identifier' => 'ICDP1004', 'status' => 'updated']);
    $run->items()->create(['resource_id' => $samples['ICDP1005']->resource_id, 'identifier' => '10.98765/icdp1005', 'status' => 'registered']);

    $migration->up();

    foreach ($samples as $identifier => $sample) {
        $live = in_array($identifier, ['ICDP1001', '10.98765/icdp1004', 'ICDP1005'], true);
        expect($sample->refresh()->upload_status)->toBe($live ? 'registered' : 'unverified')
            ->and($sample->simulated_registered_at !== null)->toBe($identifier === 'ICDP1002')
            ->and($sample->resource->doi)->toBe($identifier);
    }
});

test('rolling back leaves unverified statuses assigned after the backfill intact', function (): void {
    $migration = require database_path('migrations/2026_11_27_000001_add_igsn_registration_previews.php');
    $migration->down();
    $historical = Resource::factory()->create(['doi' => '10.60510/sandbox']);
    $sandbox = IgsnMetadata::create(['resource_id' => $historical->id, 'upload_status' => 'registered']);
    recordHistoricalIgsnPublication($sandbox, '10.60510/sandbox', true);
    $migration->up();

    $resource = Resource::factory()->create();
    $sample = IgsnMetadata::create(['resource_id' => $resource->id, 'upload_status' => 'unverified']);

    $migration->down();
    try {
        expect($sample->refresh()->upload_status)->toBe('unverified')
            ->and($sandbox->refresh()->upload_status)->toBe('unverified');
    } finally {
        $migration->up();
    }
    expect($sandbox->refresh()->simulated_registered_at)->not->toBeNull()
        ->and($sample->refresh()->simulated_registered_at)->toBeNull();
});

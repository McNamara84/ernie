<?php

declare(strict_types=1);

use App\Models\IgsnMetadata;
use App\Models\IgsnRegistrationRun;
use App\Models\Resource;
use App\Models\ResourceDoiPublication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('the preview migration distinguishes live, sandbox, and unverifiable historical registrations', function (): void {
    $migration = require database_path('migrations/2026_11_27_000001_add_igsn_registration_previews.php');
    $migration->down();

    $samples = [];
    foreach (['live', 'sandbox', 'both', 'unknown', 'changed', 'failed', 'uploaded'] as $name) {
        $resource = Resource::factory()->create(['doi' => '10.60510/'.$name]);
        $samples[$name] = IgsnMetadata::create([
            'resource_id' => $resource->id,
            'upload_status' => $name === 'uploaded' ? 'uploaded' : 'registered',
        ]);
    }

    foreach ([['live', false], ['both', true], ['changed', false]] as [$name, $testMode]) {
        ResourceDoiPublication::create([
            'uuid' => Str::uuid()->toString(), 'resource_id' => $samples[$name]->resource_id,
            'test_mode' => $testMode, 'endpoint' => 'https://example.test', 'repository' => 'igsn.repo',
            'prefix' => '10.60510', 'doi' => '10.60510/'.($name === 'changed' ? 'previous' : $name),
            'target_url' => 'https://example.test/sample', 'metadata_hash' => str_repeat('a', 64),
            'payload' => [], 'status' => 'succeeded',
        ]);
    }
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

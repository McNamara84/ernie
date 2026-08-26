<?php

declare(strict_types=1);

use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Observers\IgsnMetadataObserver;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

covers(IgsnMetadataObserver::class);

beforeEach(function (): void {
    Storage::fake('igsn-images');
    Config::set('igsn_images.disk', 'igsn-images');
});

it('deletes the previous managed image only after a storage path update commits', function (): void {
    $resource = Resource::factory()->create(['doi' => '10.60510/metadata-update']);
    $oldPath = 'igsn-sample-images/metadata-update/old.jpg';
    $newPath = 'igsn-sample-images/metadata-update/new.jpg';
    $metadata = IgsnMetadata::query()->create([
        'resource_id' => $resource->id,
        'sample_image_storage_path' => $oldPath,
    ]);
    Storage::disk('igsn-images')->put($oldPath, 'old image');
    Storage::disk('igsn-images')->put($newPath, 'new image');

    $startingTransactionLevel = DB::transactionLevel();
    DB::beginTransaction();
    try {
        $metadata->update(['sample_image_storage_path' => $newPath]);

        Storage::disk('igsn-images')->assertExists($oldPath);
        DB::commit();
    } finally {
        while (DB::transactionLevel() > $startingTransactionLevel) {
            DB::rollBack();
        }
    }

    expect($metadata->refresh()->sample_image_storage_path)->toBe($newPath);
    Storage::disk('igsn-images')->assertMissing($oldPath);
    Storage::disk('igsn-images')->assertExists($newPath);
});

it('deletes a managed image only after the metadata deletion commits', function (): void {
    $resource = Resource::factory()->create(['doi' => '10.60510/metadata-delete']);
    $path = 'igsn-sample-images/metadata-delete/sample.jpg';
    $metadata = IgsnMetadata::query()->create([
        'resource_id' => $resource->id,
        'sample_image_storage_path' => $path,
    ]);
    Storage::disk('igsn-images')->put($path, 'image');

    $startingTransactionLevel = DB::transactionLevel();
    DB::beginTransaction();
    try {
        $metadata->delete();

        Storage::disk('igsn-images')->assertExists($path);
        DB::commit();
    } finally {
        while (DB::transactionLevel() > $startingTransactionLevel) {
            DB::rollBack();
        }
    }

    expect(IgsnMetadata::query()->whereKey($metadata->id)->exists())->toBeFalse();
    Storage::disk('igsn-images')->assertMissing($path);
});

it('keeps a managed image when the metadata deletion rolls back', function (): void {
    $resource = Resource::factory()->create(['doi' => '10.60510/metadata-delete-rollback']);
    $path = 'igsn-sample-images/metadata-delete-rollback/sample.jpg';
    $metadata = IgsnMetadata::query()->create([
        'resource_id' => $resource->id,
        'sample_image_storage_path' => $path,
    ]);
    Storage::disk('igsn-images')->put($path, 'image');

    $startingTransactionLevel = DB::transactionLevel();
    DB::beginTransaction();
    try {
        $metadata->delete();

        Storage::disk('igsn-images')->assertExists($path);
        DB::rollBack();
    } finally {
        while (DB::transactionLevel() > $startingTransactionLevel) {
            DB::rollBack();
        }
    }

    expect(IgsnMetadata::query()->whereKey($metadata->id)->exists())->toBeTrue();
    Storage::disk('igsn-images')->assertExists($path);
});

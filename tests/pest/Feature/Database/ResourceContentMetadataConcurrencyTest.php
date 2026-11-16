<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\TitleType;
use App\Models\User;
use App\Services\SizeFormat\ResourceContentMetadataService;
use Illuminate\Support\Facades\DB;

uses()->group('mysql-sensitive');

it('protects an editor snapshot after assistance commits on an independent MySQL connection', function () {
    $actor = User::factory()->curator()->create();
    $type = ResourceType::firstOrCreate(['slug' => 'dataset'], ['name' => 'Dataset']);
    $titleType = TitleType::firstOrCreate(['slug' => 'MainTitle'], ['name' => 'Main Title']);
    $resource = Resource::factory()->create(['doi' => null, 'resource_type_id' => $type->id, 'created_by_user_id' => $actor->id]);
    $fingerprint = app(ResourceContentMetadataService::class)->state($resource)['fingerprint'];
    $connection = config('database.default');
    config(['database.connections.content_metadata_writer' => config('database.connections.'.$connection)]);
    DB::commit();
    try {
        $writer = DB::connection('content_metadata_writer');
        expect($writer->selectOne('SELECT CONNECTION_ID() AS id')->id)->not->toBe(DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
        $writer->transaction(function () use ($writer, $resource): void {
            $writer->table('resources')->where('id', $resource->id)->lockForUpdate()->first();
            $writer->table('formats')->insert(['resource_id' => $resource->id, 'value' => 'text/csv', 'created_at' => now(), 'updated_at' => now()]);
            $writer->table('sizes')->insert(['resource_id' => $resource->id, 'numeric_value' => '9999999999999999.0001', 'unit' => 'B', 'type' => 'Primary Data Size', 'created_at' => now(), 'updated_at' => now()]);
        });
        $payload = ['resourceId' => $resource->id, 'intent' => 'autosave', 'resourceType' => $type->id,
            'titles' => [['title' => 'Concurrent content metadata', 'titleType' => 'main-title']],
            'contentMetadataFingerprint' => $fingerprint, 'formats' => [], 'sizes' => [],
        ];
        $this->actingAs($actor)->postJson('/editor/resources/draft', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('contentMetadataFingerprint');
        $this->postJson('/editor/resources/draft', [...$payload, 'contentMetadataChanged' => false])
            ->assertOk()->assertJsonPath('contentMetadata.formats.0.value', 'text/csv')
            ->assertJsonPath('contentMetadata.sizes.0.numeric_value', '9999999999999999.0001');
        expect($resource->formats()->count())->toBe(1)->and($resource->sizes()->sole()->numeric_value)->toBe('9999999999999999.0001');
    } finally {
        DB::purge('content_metadata_writer');
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $resource->delete();
        $actor->delete();
        if ($titleType->wasRecentlyCreated) {
            $titleType->delete();
        }
        if ($type->wasRecentlyCreated) {
            $type->delete();
        }
        DB::purge();
        DB::beginTransaction();
    }
})->skip(fn () => DB::connection()->getDriverName() !== 'mysql', 'Requires the pinned MySQL 9.7 server.');

<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\TitleType;
use App\Models\User;
use App\Services\SizeFormat\ContentMetadataImportEvidenceService;
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

it('serializes concurrent attempts to consume import evidence and follows transaction outcomes', function (bool $commit) {
    $actor = User::factory()->curator()->create();
    $service = app(ContentMetadataImportEvidenceService::class);
    $metadata = $service->issue(['formats' => [['value' => 'legacy import']]], $actor->id);
    DB::commit();
    DB::disconnect();
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if ($pair === false || ($pid = pcntl_fork()) === -1) {
        throw new RuntimeException('Could not start concurrent import evidence consumer.');
    }
    if ($pid === 0) {
        fclose($pair[0]);
        DB::purge();
        stream_set_timeout($pair[1], 20);
        fgets($pair[1]);
        try {
            DB::connection()->beforeExecuting(function (string $query) use ($pair): void {
                if (str_contains($query, 'content_metadata_import_rows') && str_contains($query, 'for update')) {
                    fwrite($pair[1], "locking\n");
                }
            });
            $result = DB::transaction(fn (): array => app(ContentMetadataImportEvidenceService::class)->consume($metadata, $actor->id, false));
            fwrite($pair[1], count($result)."\n");
            exit(0);
        } catch (Throwable $exception) {
            fwrite($pair[1], 'error: '.$exception->getMessage()."\n");
            exit(1);
        }
    }
    fclose($pair[1]);
    stream_set_timeout($pair[0], 20);
    try {
        DB::beginTransaction();
        expect($service->consume($metadata, $actor->id, false))->toHaveCount(1);
        fwrite($pair[0], "go\n");
        expect(trim((string) fgets($pair[0])))->toBe('locking');
        $read = [$pair[0]];
        $write = $except = null;
        expect(stream_select($read, $write, $except, 0, 200000))->toBe(0);
        if ($commit) {
            DB::commit();
        } else {
            DB::rollBack();
        }
        expect(trim((string) fgets($pair[0])))->toBe($commit ? '0' : '1');
        pcntl_waitpid($pid, $status);
        expect(pcntl_wexitstatus($status))->toBe(0);
        $this->assertDatabaseCount('content_metadata_import_rows', 0);
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        fclose($pair[0]);
        pcntl_waitpid($pid, $status);
        DB::purge();
        $actor->delete();
        DB::beginTransaction();
    }
})->with(['commit' => true, 'rollback' => false])
    ->skip(fn () => DB::connection()->getDriverName() !== 'mysql', 'Requires the pinned MySQL 9.7 server.');

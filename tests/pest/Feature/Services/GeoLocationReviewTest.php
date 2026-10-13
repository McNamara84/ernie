<?php

declare(strict_types=1);

use App\Models\Datacenter;
use App\Models\GeoLocation;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\User;
use App\Services\DataCiteToResourceTransformer;
use App\Services\Editor\EditorDataTransformer;
use App\Services\GeoLocations\GeoLocationWriterService;
use App\Services\GeoLocations\GeoLocationWriteSessionService;
use App\Services\ResourceStorageService;
use App\Support\GeoLocations\GeoLocationWriteReport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(['ResourceTypeSeeder', 'TitleTypeSeeder', 'DescriptionTypeSeeder', 'LanguageSeeder', 'PublisherSeeder']);
    $this->user = User::factory()->create();
    $this->resource = Resource::factory()->create(['created_by_user_id' => $this->user->id]);
    $this->payload = [
        'resourceId' => $this->resource->id, 'year' => 2026,
        'resourceType' => ResourceType::where('name', 'Dataset')->value('id'),
        'accessLevel' => 'open', 'language' => 'en',
        'titles' => [['title' => 'Reviewed resource', 'titleType' => 'main-title']],
        'licenses' => [Right::factory()->create()->identifier],
        'authors' => [['type' => 'person', 'firstName' => 'Jane', 'lastName' => 'Doe', 'affiliations' => []]],
        'descriptions' => [['descriptionType' => 'abstract', 'description' => 'Review regression test.']],
        'datacenter_id' => Datacenter::create(['name' => 'Review test centre'])->id,
    ];
    $this->coverage = ['type' => 'point', 'latMin' => '52.38', 'lonMin' => '13.06', 'description' => 'GFZ'];
});

it('reports validated-save merges and reuses the retained IDs on subsequent saves', function () {
    $response = $this->actingAs($this->user)->postJson('/editor/resources', [...$this->payload,
        'spatialTemporalCoverages' => [['id' => 'new-first', ...$this->coverage], ['id' => 'new-copy', ...$this->coverage]],
    ])->assertOk()->assertJsonPath('geoLocationChanges.removedCount', 1)
        ->assertJsonPath('geoLocationChanges.removedIds', ['new-copy']);
    $id = $response->json('geoLocationChanges.idMap.new-first');
    expect($id)->toBeString()->and($response->json('geoLocationChanges.idMap.new-copy'))->toBe($id);
    $this->postJson('/editor/resources', [...$this->payload,
        'spatialTemporalCoverages' => [['id' => $id, ...$this->coverage]],
    ])->assertOk()->assertJsonPath('geoLocationChanges.removedCount', 0)
        ->assertJsonPath("geoLocationChanges.idMap.{$id}", $id);
    expect($this->resource->geoLocations()->pluck('id')->all())->toBe([(int) $id]);
});

it('preserves historical duplicate IDs and hidden metadata through validated saves', function (bool $includeCoverages) {
    $old = GeoLocation::factory()->count(3)->create([
        'resource_id' => $this->resource->id, 'geo_type' => 'point',
        'point_latitude' => -89.999997, 'point_longitude' => -171.470888,
        'country' => 'stored country', 'elevation' => 42,
    ]);
    $payload = $this->payload;
    if ($includeCoverages) {
        $payload['spatialTemporalCoverages'] = app(EditorDataTransformer::class)->transformCoverages($this->resource->load('geoLocations'));
    }
    $this->actingAs($this->user)->postJson('/editor/resources', $payload)->assertOk()->assertJsonPath('geoLocationChanges.removedCount', 0);
    expect($this->resource->geoLocations()->pluck('id')->all())->toBe($old->pluck('id')->all())
        ->and($this->resource->geoLocations()->pluck('country')->unique()->all())->toBe(['stored country']);
})->with([true, false]);

it('preserves copied hidden metadata through validated request normalization', function () {
    $old = GeoLocation::factory()->create([
        'resource_id' => $this->resource->id, 'geo_type' => 'point',
        'point_latitude' => 52.38, 'point_longitude' => 13.06,
        'country' => 'stored country', 'elevation' => 42,
    ]);
    $coverage = app(EditorDataTransformer::class)->transformCoverages($this->resource->load('geoLocations'))[0];
    $coverage = [...$coverage, 'id' => 'edited-copy', 'copyFromId' => (string) $old->id, 'lonMin' => '14'];
    $response = $this->actingAs($this->user)->postJson('/editor/resources', [...$this->payload,
        'spatialTemporalCoverages' => [$coverage],
    ])->assertOk();
    $location = $this->resource->geoLocations()->sole();
    expect($response->json('geoLocationChanges.idMap.edited-copy'))->toBe((string) $location->id)
        ->and($location->country)->toBe('stored country')->and($location->elevation)->toBe('42.00')
        ->and($location->point_longitude)->toBe('14.00000000');
});

it('rejects incomplete vertices without silently changing stored geometry', function (string $endpoint) {
    $points = [['latitude' => null, 'longitude' => 0], ['latitude' => 1, 'longitude' => 0],
        ['latitude' => 1, 'longitude' => 1], ['latitude' => 0, 'longitude' => 1]];
    $old = GeoLocation::factory()->create(['resource_id' => $this->resource->id, 'geo_type' => 'polygon', 'polygon_points' => $points]);
    $coverage = app(EditorDataTransformer::class)->transformCoverages($this->resource->load('geoLocations'))[0];
    $this->actingAs($this->user)->postJson($endpoint, [...$this->payload, 'intent' => 'autosave', 'spatialTemporalCoverages' => [$coverage]])
        ->assertUnprocessable()->assertJsonValidationErrors('spatialTemporalCoverages.0.polygonPoints.0.lat');
    expect($old->fresh()->polygon_points)->toBe($points);
})->with(['/editor/resources', '/editor/resources/draft']);

it('loads the location collection once for a large batch of unique and duplicate writes', function () {
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $duplicates = app(GeoLocationWriterService::class)->batch($this->resource, function (GeoLocationWriteSessionService $session): int {
            $duplicates = 0;
            for ($index = 0; $index < 128; $index++) {
                $attributes = ['geo_type' => 'point', 'point_latitude' => 52.38, 'point_longitude' => 13.06, 'place' => "Site {$index}"];
                $first = $session->write($attributes);
                $copy = $session->write($attributes);
                expect($copy->location->id)->toBe($first->location->id);
                $duplicates += (int) $copy->duplicate;
            }

            return $duplicates;
        });
        $reads = array_filter(DB::getQueryLog(), fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select')
            && (str_contains($query['query'], 'from "geo_locations"') || str_contains($query['query'], 'from `geo_locations`')));
        expect($reads)->toHaveCount(1)->and($duplicates)->toBe(128);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($this->resource->geoLocations()->count())->toBe(128);
});

it('keeps location reads bounded in the production editor and DataCite import paths', function (string $path, int $count) {
    $locations = [];
    for ($index = 0; $index < $count; $index++) {
        $location = $path === 'editor'
            ? [...$this->coverage, 'id' => "incoming-{$index}", 'description' => "Site {$index}"]
            : ['geoLocationPlace' => "Site {$index}", 'geoLocationPoint' => ['pointLatitude' => 52.38, 'pointLongitude' => 13.06]];
        $locations[] = $location;
        $locations[] = $path === 'editor' ? [...$location, 'id' => "copy-{$index}"] : $location;
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        if ($path === 'editor') {
            [$resource, , $changes] = app(ResourceStorageService::class)->store([...$this->payload, 'spatialTemporalCoverages' => $locations], $this->user->id);
            expect($changes['removedCount'])->toBe($count);
        } else {
            $report = new GeoLocationWriteReport;
            $resource = app(DataCiteToResourceTransformer::class)->transform(['id' => '10.5880/review.large', 'attributes' => [
                'doi' => '10.5880/review.large', 'titles' => [['title' => 'Large import']], 'publicationYear' => 2026,
                'types' => ['resourceTypeGeneral' => 'Dataset'], 'geoLocations' => $locations,
            ]], $this->user->id, $report);
            expect($report->removedCount)->toBe($count);
        }
        $reads = array_filter(DB::getQueryLog(), fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select')
            && (str_contains($query['query'], 'from "geo_locations"') || str_contains($query['query'], 'from `geo_locations`')));
        expect(count($reads))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(2);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($resource->geoLocations()->count())->toBe($count);
})->with([['editor', 4], ['editor', 64], ['datacite', 4], ['datacite', 64]]);

it('updates the batch index when identities change or rows are manually deleted', function () {
    app(GeoLocationWriterService::class)->batch($this->resource, function (GeoLocationWriteSessionService $session): void {
        $first = $session->write(['place' => 'first'])->location;
        $session->write(['place' => 'changed'], $first);
        $second = $session->write(['place' => 'first']);
        expect($second->duplicate)->toBeFalse()->and($second->location->id)->not->toBe($first->id);
        expect($session->write(['place' => 'changed'])->location->id)->toBe($first->id);
        $session->delete($second->location);
        expect($session->write(['place' => 'first'])->duplicate)->toBeFalse();
    });
    expect($this->resource->geoLocations()->count())->toBe(2);
});

it('rolls a whole batch back and does not retain its identity index for the next operation', function () {
    try {
        app(GeoLocationWriterService::class)->batch($this->resource, function (GeoLocationWriteSessionService $session): void {
            $session->write(['place' => 'first']);
            $session->write(['place' => 'second']);
            throw new RuntimeException('abort');
        });
    } catch (RuntimeException) {
    }
    expect($this->resource->geoLocations()->count())->toBe(0);
    expect(app(GeoLocationWriterService::class)->write($this->resource, ['place' => 'first'])->duplicate)->toBeFalse();
});

it('reports JSON upload deduplication and retains different location descriptions', function (bool $reverseKeys) {
    $point = ['geoLocationPlace' => 'GFZ', 'geoLocationPoint' => ['pointLatitude' => 52.38, 'pointLongitude' => 13.06]];
    $json = json_encode(['data' => ['type' => 'dois', 'attributes' => [
        'doi' => '10.5880/test.json-geos', 'creators' => [['name' => 'Test Author', 'nameType' => 'Personal']],
        'titles' => [['title' => 'JSON locations']], 'publisher' => 'GFZ', 'publicationYear' => 2026,
        'types' => ['resourceTypeGeneral' => 'Dataset'],
        'geoLocations' => [$point, $reverseKeys ? array_reverse($point, true) : $point, [...$point, 'geoLocationPlace' => 'Different description']],
    ]]], JSON_THROW_ON_ERROR);
    $response = $this->actingAs($this->user)->postJson('/dashboard/upload-json', [
        'file' => UploadedFile::fake()->createWithContent('locations.json', $json),
    ])->assertSuccessful()->assertJsonPath('deduplicatedGeoLocations', 1);
    $locations = Resource::findOrFail($response->json('resourceId'))->geoLocations;
    expect($locations)->toHaveCount(2)->and($locations->pluck('place')->all())->toBe(['GFZ', 'Different description']);
})->with([true, false]);

it('keeps invalid duplicated JSON locations subject to schema validation', function () {
    $point = ['geoLocationPoint' => ['pointLatitude' => 500, 'pointLongitude' => 13.06]];
    $json = json_encode(['data' => ['type' => 'dois', 'attributes' => [
        'creators' => [['name' => 'Test Author']], 'titles' => [['title' => 'Invalid JSON locations']],
        'publisher' => 'GFZ', 'publicationYear' => 2026, 'types' => ['resourceTypeGeneral' => 'Dataset'],
        'geoLocations' => [$point, $point],
    ]]], JSON_THROW_ON_ERROR);
    $this->actingAs($this->user)->postJson('/dashboard/upload-json', [
        'file' => UploadedFile::fake()->createWithContent('invalid-locations.json', $json),
    ])->assertUnprocessable()->assertJsonPath('error.code', 'json_schema_validation_error');
    expect(GeoLocation::count())->toBe(0);
});

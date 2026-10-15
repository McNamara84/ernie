<?php

declare(strict_types=1);

use App\Models\GeoLocation;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\User;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\Editor\EditorDataTransformer;
use App\Services\GeoLocations\GeoLocationIdentityService;
use App\Services\GeoLocations\GeoLocationWriterService;
use App\Services\ResourceStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function pointLocationAttributes(): array
{
    return ['geo_type' => 'point', 'point_latitude' => 52.38, 'point_longitude' => 13.06, 'place' => 'GFZ'];
}

it('merges incoming duplicates using storage precision and keeps the first position', function () {
    $resource = Resource::factory()->create();
    $writer = app(GeoLocationWriterService::class);
    $first = $writer->write($resource, [...pointLocationAttributes(), 'position' => 3]);
    $second = $writer->write($resource, [...pointLocationAttributes(), 'point_longitude' => '13.06000000', 'position' => 7]);
    expect($first->duplicate)->toBeFalse()
        ->and($second->duplicate)->toBeTrue()
        ->and($second->location->id)->toBe($first->location->id)
        ->and($resource->geoLocations()->count())->toBe(1)
        ->and($first->location->fresh()->position)->toBe(3);
});

it('keeps resources independent', function () {
    foreach (Resource::factory()->count(2)->create() as $resource) {
        expect(app(GeoLocationWriterService::class)->write($resource, pointLocationAttributes())->duplicate)->toBeFalse();
    }
    expect(GeoLocation::count())->toBe(2);
});

it('preserves stored order when an import merges a duplicate with or without a position', function (bool $includePosition) {
    $resource = Resource::factory()->create();
    $target = GeoLocation::factory()->create(['resource_id' => $resource->id, ...pointLocationAttributes(), 'position' => 7]);
    $other = GeoLocation::factory()->create(['resource_id' => $resource->id, ...pointLocationAttributes(), 'place' => 'Other', 'position' => 1]);
    $before = $target->fresh()->getAttributes();
    $incoming = pointLocationAttributes();
    if ($includePosition) {
        $incoming['position'] = 0;
    }

    $result = app(GeoLocationWriterService::class)->write($resource, $incoming);
    expect($result->duplicate)->toBeTrue()->and($result->location->id)->toBe($target->id)
        ->and($target->fresh()->getAttributes())->toBe($before)
        ->and($resource->geoLocations()->pluck('id')->all())->toBe([$other->id, $target->id])
        ->and($resource->geoLocations()->pluck('position')->all())->toBe([1, 7]);
})->with([true, false]);

it('includes every scientific field in the identity', function (string $field, mixed $value) {
    $resource = Resource::factory()->create();
    $writer = app(GeoLocationWriterService::class);
    $writer->write($resource, pointLocationAttributes());
    expect($writer->write($resource, [...pointLocationAttributes(), $field => $value])->duplicate)->toBeFalse()
        ->and($resource->geoLocations()->count())->toBe(2);
})->with([
    ['geo_type', 'box'], ['place', 'gfz'], ['point_latitude', 52.38000001], ['point_longitude', 13.06000001],
    ['west_bound_longitude', 1], ['east_bound_longitude', 2], ['south_bound_latitude', 3], ['north_bound_latitude', 4],
    ['polygon_points', [['latitude' => 1, 'longitude' => 2]]],
    ['in_polygon_point_longitude', 1], ['in_polygon_point_latitude', 2], ['elevation', 0.01], ['elevation_unit', 'm'],
    ['location_type', 'outcrop'], ['location_description', 'location'], ['locality_description', 'locality'],
    ['country', 'Germany'], ['province', 'Brandenburg'], ['county', 'Potsdam'], ['city', 'Potsdam'],
    ['start_date', '2026'], ['end_date', '2026-10'], ['temporal_mode', 'instant'],
    ['start_time', '12:00'], ['end_time', '13:00'], ['timezone', 'UTC'],
]);

it('compares polygon numeric values and object keys while preserving point order', function () {
    $identity = app(GeoLocationIdentityService::class);
    $first = new GeoLocation(['polygon_points' => [['longitude' => 13, 'latitude' => 52], ['longitude' => 14, 'latitude' => 53]]]);
    $same = new GeoLocation(['polygon_points' => [['latitude' => '52.0', 'longitude' => 13.0], ['latitude' => 53.0, 'longitude' => '14']]]);
    $reverse = new GeoLocation(['polygon_points' => array_reverse($same->polygon_points)]);
    expect($identity->equal($first, $same))->toBeTrue()->and($identity->equal($first, $reverse))->toBeFalse();
});

it('does not equate malformed polygon values with missing coordinates', function () {
    $identity = app(GeoLocationIdentityService::class);
    expect($identity->equal(
        new GeoLocation(['polygon_points' => [['latitude' => '', 'longitude' => 0]]]),
        new GeoLocation(['polygon_points' => [['latitude' => null, 'longitude' => 0]]]),
    ))->toBeFalse();
});

it('does not lose precision when comparing decimal strings inside polygon JSON', function () {
    expect(app(GeoLocationIdentityService::class)->equal(
        new GeoLocation(['polygon_points' => [['latitude' => '52.38000000000000001', 'longitude' => 13]]]),
        new GeoLocation(['polygon_points' => [['latitude' => 52.38, 'longitude' => 13]]]),
    ))->toBeFalse();
});

it('covers every fillable scientific attribute and excludes only ownership and position', function () {
    expect(array_values(array_diff((new GeoLocation)->getFillable(), ['resource_id', 'position'])))
        ->toEqualCanonicalizing(GeoLocationIdentityService::FIELDS);
});

it('preserves historical duplicates and skips only the additional incoming copy', function () {
    $resource = Resource::factory()->create();
    $old = GeoLocation::factory()->count(3)->create(['resource_id' => $resource->id, ...pointLocationAttributes()]);
    $writer = app(GeoLocationWriterService::class);
    foreach ($old as $location) {
        expect($writer->write($resource, ['position' => 4], $location)->duplicate)->toBeFalse();
    }
    expect($writer->write($resource, pointLocationAttributes())->duplicate)->toBeTrue()
        ->and($resource->geoLocations()->count())->toBe(3);
});

it('preserves historical rows when an enrichment would create a duplicate', function () {
    $resource = Resource::factory()->create();
    $first = GeoLocation::factory()->create(['resource_id' => $resource->id, ...pointLocationAttributes(), 'city' => 'Potsdam']);
    $second = GeoLocation::factory()->create(['resource_id' => $resource->id, ...pointLocationAttributes(), 'city' => null]);
    $result = app(GeoLocationWriterService::class)->write($resource, ['city' => 'Potsdam'], $second);
    expect($result->duplicate)->toBeTrue()->and($result->location->id)->toBe($first->id)
        ->and($second->fresh()->city)->toBeNull()->and($resource->geoLocations()->count())->toBe(2);
});

it('rejects a row owned by a different resource', function () {
    $location = GeoLocation::factory()->create();
    app(GeoLocationWriterService::class)->write(Resource::factory()->create(), ['place' => 'changed'], $location);
})->throws(ValidationException::class);

it('rolls back a location when the owning operation fails', function () {
    $resource = Resource::factory()->create();
    try {
        DB::transaction(function () use ($resource): void {
            app(GeoLocationWriterService::class)->write($resource, pointLocationAttributes());
            throw new RuntimeException('abort');
        });
    } catch (RuntimeException) {
    }
    expect($resource->geoLocations()->count())->toBe(0);
});

it('invalidates landing page render data only after the owning operation commits', function () {
    $resource = Resource::factory()->create();
    $page = LandingPage::factory()->create(['resource_id' => $resource->id]);
    $cache = Mockery::mock(LandingPageRenderDataCacheService::class);
    $cache->shouldReceive('forgetById')->once()->with($page->id)->andReturn(true);
    app()->instance(LandingPageRenderDataCacheService::class, $cache);
    DB::transaction(function () use ($resource, $cache): void {
        app(GeoLocationWriterService::class)->write($resource, pointLocationAttributes());
        $cache->shouldNotHaveReceived('forgetById');
    });
    $cache->shouldHaveReceived('forgetById')->once();
});

it('keeps cached landing data when an operation rolls back', function () {
    $resource = Resource::factory()->create();
    $page = LandingPage::factory()->create(['resource_id' => $resource->id]);
    $cache = Mockery::mock(LandingPageRenderDataCacheService::class);
    $cache->shouldNotReceive('forgetById');
    app()->instance(LandingPageRenderDataCacheService::class, $cache);
    try {
        DB::transaction(function () use ($resource): void {
            app(GeoLocationWriterService::class)->write($resource, pointLocationAttributes());
            throw new RuntimeException('abort');
        });
    } catch (RuntimeException) {
    }
    expect($page->exists)->toBeTrue()->and($resource->geoLocations()->count())->toBe(0);
});

describe('editor synchronization', function () {
    beforeEach(function () {
        $this->seed('TitleTypeSeeder');
        $this->resource = Resource::factory()->create();
        $this->data = ['resourceId' => $this->resource->id, 'titles' => [['title' => 'Changed title', 'titleType' => 'MainTitle']]];
    });

    it('preserves duplicate IDs, polar coordinates and hidden metadata during an unrelated save', function () {
        $old = GeoLocation::factory()->count(3)->create([
            'resource_id' => $this->resource->id, ...pointLocationAttributes(),
            'point_latitude' => -89.999997, 'country' => 'stored country', 'elevation' => 42,
        ]);
        $this->resource->load('geoLocations');
        $coverages = app(EditorDataTransformer::class)->transformCoverages($this->resource);
        [$saved, , $changes] = app(ResourceStorageService::class)->store([...$this->data, 'spatialTemporalCoverages' => $coverages]);
        expect($saved->geoLocations->pluck('id')->all())->toBe($old->pluck('id')->all())
            ->and($saved->geoLocations->pluck('country')->unique()->all())->toBe(['stored country'])
            ->and($saved->geoLocations->first()->point_latitude)->toBe('-89.99999700')
            ->and($changes['removedCount'])->toBe(0);
    });

    it('leaves locations unchanged when the request omits the coverage field', function () {
        $old = GeoLocation::factory()->count(2)->create(['resource_id' => $this->resource->id, ...pointLocationAttributes()]);
        [$saved] = app(ResourceStorageService::class)->store($this->data);
        expect($saved->geoLocations->pluck('id')->all())->toBe($old->pluck('id')->all());
    });

    it('preserves unedited geometry, null type and temporal semantics while changing a description', function () {
        $old = GeoLocation::factory()->create([
            'resource_id' => $this->resource->id, ...pointLocationAttributes(), 'geo_type' => null,
            'west_bound_longitude' => 10, 'east_bound_longitude' => 14,
            'south_bound_latitude' => 50, 'north_bound_latitude' => 54,
            'start_date' => '2026', 'temporal_mode' => null, 'timezone' => 'Z', 'city' => 'stored city',
        ]);
        $this->resource->load('geoLocations');
        $coverage = app(EditorDataTransformer::class)->transformCoverages($this->resource)[0];
        $coverage['description'] = 'Manually updated description';
        [$saved] = app(ResourceStorageService::class)->store([...$this->data, 'spatialTemporalCoverages' => [$coverage]]);
        $location = $saved->geoLocations->first();
        expect($location->id)->toBe($old->id)->and($location->geo_type)->toBeNull()
            ->and($location->point_latitude)->toBe('52.38000000')
            ->and($location->west_bound_longitude)->toBe('10.00000000')
            ->and($location->temporal_mode)->toBeNull()->and($location->timezone)->toBe('Z')
            ->and($location->city)->toBe('stored city')->and($location->place)->toBe('Manually updated description');
    });

    it('allows manual correction of a missing polygon coordinate to zero', function () {
        $old = GeoLocation::factory()->create([
            'resource_id' => $this->resource->id, 'geo_type' => 'polygon',
            'polygon_points' => [['latitude' => null, 'longitude' => 0], ['latitude' => 1, 'longitude' => 0], ['latitude' => 0, 'longitude' => 1]],
        ]);
        $this->resource->load('geoLocations');
        $coverage = app(EditorDataTransformer::class)->transformCoverages($this->resource)[0];
        $coverage['polygonPoints'][0]['lat'] = 0;
        [$saved] = app(ResourceStorageService::class)->store([...$this->data, 'spatialTemporalCoverages' => [$coverage]]);
        expect($saved->geoLocations->first()->polygon_points[0]['latitude'])->toBe(0);
    });

    it('merges new editor entries and reports the retained IDs', function () {
        $coverage = ['type' => 'point', 'latMin' => '52.38', 'lonMin' => '13.06', 'description' => 'GFZ'];
        [$saved, , $changes] = app(ResourceStorageService::class)->store([...$this->data, 'spatialTemporalCoverages' => [
            ['id' => 'new-first', ...$coverage], ['id' => 'new-copy', ...$coverage],
        ]]);
        expect($saved->geoLocations)->toHaveCount(1)->and($changes['removedCount'])->toBe(1)
            ->and($changes['removedIds'])->toBe(['new-copy'])
            ->and($changes['idMap']['new-first'])->toBe((string) $saved->geoLocations->first()->id);
    });

    it('keeps a lone incoming alias represented instead of telling the editor to delete it', function () {
        $old = GeoLocation::factory()->create(['resource_id' => $this->resource->id, ...pointLocationAttributes()]);
        [$saved, , $changes] = app(ResourceStorageService::class)->store([...$this->data, 'spatialTemporalCoverages' => [
            ['id' => 'new-alias', 'type' => 'point', 'latMin' => '52.38', 'lonMin' => '13.06', 'description' => 'GFZ'],
        ]]);
        expect($saved->geoLocations->pluck('id')->all())->toBe([$old->id])
            ->and($changes['idMap']['new-alias'])->toBe((string) $old->id)
            ->and($changes['removedIds'])->toBeEmpty();
    });

    it('prefers an explicitly submitted stored row over an incoming alias regardless of order', function () {
        $old = GeoLocation::factory()->create(['resource_id' => $this->resource->id, ...pointLocationAttributes()]);
        $coverage = ['type' => 'point', 'latMin' => '52.38', 'lonMin' => '13.06', 'description' => 'GFZ'];
        [$saved, , $changes] = app(ResourceStorageService::class)->store([...$this->data, 'spatialTemporalCoverages' => [
            ['id' => 'new-alias', ...$coverage], ['id' => (string) $old->id, ...$coverage],
        ]]);
        expect($saved->geoLocations)->toHaveCount(1)->and($changes['removedIds'])->toBe(['new-alias']);
    });

    it('preserves hidden metadata when concurrent editing recreates a merged row', function () {
        $old = GeoLocation::factory()->create([
            'resource_id' => $this->resource->id, ...pointLocationAttributes(), 'geo_type' => null, 'country' => 'stored country', 'elevation' => 42,
        ]);
        $original = app(EditorDataTransformer::class)->transformCoverages($this->resource->load('geoLocations'))[0];
        [$saved] = app(ResourceStorageService::class)->store([...$this->data, 'spatialTemporalCoverages' => [
            $original, [...$original, 'id' => 'new-edited-row', 'copyFromId' => (string) $old->id, 'latMin' => '53.38'],
        ]]);
        expect($saved->geoLocations)->toHaveCount(2)
            ->and($saved->geoLocations->last()->country)->toBe('stored country')
            ->and($saved->geoLocations->last()->elevation)->toBe('42.00')
            ->and($saved->geoLocations->last()->geo_type)->toBeNull()
            ->and($saved->geoLocations->last()->point_latitude)->toBe('53.38000000');
    });

    it('rejects metadata copies from a different resource', function () {
        $foreign = GeoLocation::factory()->create();
        $result = app(ResourceStorageService::class)->store([...$this->data, 'spatialTemporalCoverages' => [
            ['id' => 'new-edited-row', 'copyFromId' => (string) $foreign->id, 'type' => 'point', 'latMin' => '52', 'lonMin' => '13'],
        ]]);
        expect($result[0])->toBeInstanceOf(Resource::class);
    })->throws(ValidationException::class);

    it('permits explicit manual deletion and preserves the remaining historical ID', function () {
        $old = GeoLocation::factory()->count(2)->create(['resource_id' => $this->resource->id, ...pointLocationAttributes()]);
        $this->resource->load('geoLocations');
        $coverage = app(EditorDataTransformer::class)->transformCoverages($this->resource)[1];
        [$saved] = app(ResourceStorageService::class)->store([...$this->data, 'spatialTemporalCoverages' => [$coverage]]);
        expect($saved->geoLocations->pluck('id')->all())->toBe([$old[1]->id]);
    });

    it('rejects forged coverage IDs without changing stored data', function () {
        $foreign = GeoLocation::factory()->create();
        $result = app(ResourceStorageService::class)->store([...$this->data, 'spatialTemporalCoverages' => [
            ['id' => (string) $foreign->id, 'type' => 'point', 'latMin' => 52, 'lonMin' => 13],
        ]]);
        expect($result[0])->toBeInstanceOf(Resource::class);
    })->throws(ValidationException::class);

    it('preserves historical duplicates through the actual draft request normalization', function (bool $includeCoverages) {
        $user = User::factory()->create();
        $this->resource->update(['created_by_user_id' => $user->id]);
        $old = GeoLocation::factory()->count(3)->create([
            'resource_id' => $this->resource->id, ...pointLocationAttributes(), 'point_latitude' => -89.999997, 'country' => 'stored country',
        ]);
        $payload = ['intent' => 'autosave', 'resourceId' => $this->resource->id, 'titles' => [['title' => 'Unrelated title edit', 'titleType' => 'main-title']]];
        if ($includeCoverages) {
            $payload['spatialTemporalCoverages'] = app(EditorDataTransformer::class)->transformCoverages($this->resource->load('geoLocations'));
        }
        $this->actingAs($user)->postJson('/editor/resources/draft', $payload)->assertOk()->assertJsonPath('geoLocationChanges.removedCount', 0);
        expect($this->resource->geoLocations()->pluck('id')->all())->toBe($old->pluck('id')->all())
            ->and($this->resource->geoLocations()->pluck('country')->unique()->all())->toBe(['stored country']);
    })->with([true, false]);

    it('rejects a non-array coverage payload without deleting historical locations', function () {
        $user = User::factory()->create();
        $this->resource->update(['created_by_user_id' => $user->id]);
        $old = GeoLocation::factory()->count(3)->create(['resource_id' => $this->resource->id, ...pointLocationAttributes()]);
        $this->actingAs($user)->postJson('/editor/resources/draft', [
            'intent' => 'autosave', 'resourceId' => $this->resource->id,
            'spatialTemporalCoverages' => 'malformed',
        ])->assertUnprocessable()->assertJsonValidationErrors('spatialTemporalCoverages');
        expect($this->resource->geoLocations()->pluck('id')->all())->toBe($old->pluck('id')->all());
    });

    it('reports new duplicate IDs through the draft endpoint and reuses the retained ID on subsequent autosave', function () {
        $user = User::factory()->create();
        $this->resource->update(['created_by_user_id' => $user->id]);
        $coverage = ['type' => 'point', 'latMin' => '52.38', 'lonMin' => '13.06', 'description' => 'GFZ'];
        $payload = ['intent' => 'autosave', 'resourceId' => $this->resource->id, 'titles' => [['title' => 'Geo draft', 'titleType' => 'main-title']],
            'spatialTemporalCoverages' => [['id' => 'new-first', ...$coverage], ['id' => 'new-copy', ...$coverage]],
        ];
        $response = $this->actingAs($user)->postJson('/editor/resources/draft', $payload)->assertOk()->assertJsonPath('geoLocationChanges.removedCount', 1);
        $id = $response->json('geoLocationChanges.idMap.new-first');
        expect($id)->toBeString()->and($this->resource->geoLocations()->count())->toBe(1);
        $payload['spatialTemporalCoverages'] = [['id' => $id, ...$coverage]];
        $this->postJson('/editor/resources/draft', $payload)->assertOk()->assertJsonPath('geoLocationChanges.removedCount', 0);
        expect($this->resource->geoLocations()->pluck('id')->all())->toBe([(int) $id]);
    });

    it('preserves historical duplicates submitted by an older client without row IDs', function () {
        $old = GeoLocation::factory()->count(3)->create(['resource_id' => $this->resource->id, ...pointLocationAttributes(), 'country' => 'stored country']);
        $coverages = app(EditorDataTransformer::class)->transformCoverages($this->resource->load('geoLocations'));
        foreach ($coverages as &$coverage) {
            unset($coverage['id']);
        }
        unset($coverage);
        [$saved, , $changes] = app(ResourceStorageService::class)->store([...$this->data, 'spatialTemporalCoverages' => $coverages]);
        expect($saved->geoLocations->pluck('id')->all())->toBe($old->pluck('id')->all())
            ->and($saved->geoLocations->pluck('country')->unique()->all())->toBe(['stored country'])
            ->and($changes['removedCount'])->toBe(0);
    });
});

<?php

declare(strict_types=1);

use App\Models\GeoLocation;
use App\Models\Resource;
use App\Services\Editor\EditorDataTransformer;
use App\Services\SumarioPmdCoverageEnrichmentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function issue1179CreateLegacyResource(string $doi, int $id = 1): void
{
    DB::connection('metaworks')->table('resource')->insert([
        'id' => $id,
        'identifier' => $doi,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function issue1179CreateLegacyCoverage(array $overrides = []): void
{
    DB::connection('metaworks')->table('coverage')->insert(array_merge([
        'id' => 1,
        'resource_id' => 1,
        'minlat' => 49.8099,
        'maxlat' => 50.0529,
        'minlon' => 11.7763,
        'maxlon' => 12.3128,
        'description' => 'DEKORP KTB8501 seismic profile',
        'wkt' => '11.77626257 49.80986519, 12.00000000 49.90000000, 12.31280322 50.05294114',
    ], $overrides));
}

describe('SumarioPmdCoverageEnrichmentService', function () {
    beforeEach(function () {
        Config::set('database.connections.metaworks', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge('metaworks');

        Schema::connection('metaworks')->create('resource', function (Blueprint $table): void {
            $table->integer('id');
            $table->string('identifier')->nullable();
        });

        Schema::connection('metaworks')->create('coverage', function (Blueprint $table): void {
            $table->integer('id');
            $table->integer('resource_id');
            $table->float('minlat')->nullable();
            $table->float('maxlat')->nullable();
            $table->float('minlon')->nullable();
            $table->float('maxlon')->nullable();
            $table->text('description')->nullable();
            $table->text('wkt')->nullable();
        });
    });

    afterEach(function () {
        DB::disconnect('metaworks');
        DB::purge('metaworks');
    });

    it('imports the issue profile as an editor-compatible line and replaces only its matching box', function () {
        $issuePoints = array_map(
            static function (int $index): array {
                $ratio = $index / 91;

                return [
                    'longitude' => round(11.77626257 + ((12.31280322 - 11.77626257) * $ratio), 8),
                    'latitude' => round(49.80986519 + ((50.05294114 - 49.80986519) * $ratio), 8),
                ];
            },
            range(0, 91),
        );
        $issueWkt = implode(', ', array_map(
            static fn (array $point): string => $point['longitude'].' '.$point['latitude'],
            $issuePoints,
        ));

        issue1179CreateLegacyResource('10.5880/GFZ.DEKORP.KTB8501.001');
        issue1179CreateLegacyCoverage(['wkt' => $issueWkt]);

        $resource = Resource::factory()->create(['doi' => '10.5880/gfz.dekorp.ktb8501.001']);
        $matchingBox = GeoLocation::factory()->withBox(11.7763, 12.3128, 49.8099, 50.0529)->create([
            'resource_id' => $resource->id,
            'place' => 'DEKORP KTB8501 seismic profile',
        ]);
        $unrelatedBox = GeoLocation::factory()->withBox(5.0, 6.0, 50.0, 51.0)->create([
            'resource_id' => $resource->id,
            'place' => 'Unrelated area',
        ]);

        $updated = app(SumarioPmdCoverageEnrichmentService::class)->enrich(
            $resource,
            '10.5880/gfz.dekorp.ktb8501.001',
        );

        $line = $resource->geoLocations()->where('geo_type', 'line')->sole();
        $editorCoverages = app(EditorDataTransformer::class)
            ->transformCoverages($resource->fresh('geoLocations'));
        $editorLine = collect($editorCoverages)->sole(fn (array $coverage): bool => $coverage['type'] === 'line');

        expect($updated)->toBeTrue()
            ->and(GeoLocation::query()->whereKey($matchingBox->id)->exists())->toBeFalse()
            ->and(GeoLocation::query()->whereKey($unrelatedBox->id)->exists())->toBeTrue()
            ->and($line->place)->toBe('DEKORP KTB8501 seismic profile')
            ->and($line->polygon_points)->toBe($issuePoints)
            ->and($line->polygon_points)->toHaveCount(92)
            ->and($editorLine['description'])->toBe('DEKORP KTB8501 seismic profile')
            ->and($editorLine['polygonPoints'])->toBe(array_map(
                static fn (array $point): array => [
                    'lat' => $point['latitude'],
                    'lon' => $point['longitude'],
                ],
                $issuePoints,
            ));
    });

    it('imports whitespace-separated lines for non-DEKORP resources', function () {
        issue1179CreateLegacyResource('10.5880/GIPP.TEST.001');
        issue1179CreateLegacyCoverage([
            'description' => 'GIPP profile',
            'wkt' => '10.0 45.0 11.0 46.0 12.0 47.0',
        ]);
        $resource = Resource::factory()->create(['doi' => '10.5880/gipp.test.001']);

        $updated = app(SumarioPmdCoverageEnrichmentService::class)->enrich($resource, $resource->doi);

        expect($updated)->toBeTrue()
            ->and($resource->geoLocations()->where('geo_type', 'line')->sole()->polygon_points)
            ->toBe([
                ['longitude' => 10, 'latitude' => 45],
                ['longitude' => 11, 'latitude' => 46],
                ['longitude' => 12, 'latitude' => 47],
            ]);
    });

    it('uses a normalized place description to select one of multiple equal boxes', function () {
        issue1179CreateLegacyResource('10.5880/multiple.boxes');
        issue1179CreateLegacyCoverage(['description' => 'Profile   Alpha']);
        $resource = Resource::factory()->create(['doi' => '10.5880/multiple.boxes']);
        $matchingBox = GeoLocation::factory()->withBox(11.7763, 12.3128, 49.8099, 50.0529)->create([
            'resource_id' => $resource->id,
            'place' => ' profile alpha ',
        ]);
        $otherBox = GeoLocation::factory()->withBox(11.7763, 12.3128, 49.8099, 50.0529)->create([
            'resource_id' => $resource->id,
            'place' => 'Profile Beta',
        ]);

        app(SumarioPmdCoverageEnrichmentService::class)->enrich($resource, $resource->doi);

        expect(GeoLocation::query()->whereKey($matchingBox->id)->exists())->toBeFalse()
            ->and(GeoLocation::query()->whereKey($otherBox->id)->exists())->toBeTrue()
            ->and($resource->geoLocations()->where('geo_type', 'line')->count())->toBe(1);
    });

    it('preserves a single same-bounds box when its place conflicts with the legacy description', function () {
        issue1179CreateLegacyResource('10.5880/place.conflict');
        issue1179CreateLegacyCoverage(['description' => 'Legacy profile']);
        $resource = Resource::factory()->create(['doi' => '10.5880/place.conflict']);
        $box = GeoLocation::factory()->withBox(11.7763, 12.3128, 49.8099, 50.0529)->create([
            'resource_id' => $resource->id,
            'place' => 'Different DataCite area',
        ]);

        app(SumarioPmdCoverageEnrichmentService::class)->enrich($resource, $resource->doi);

        expect(GeoLocation::query()->whereKey($box->id)->exists())->toBeTrue()
            ->and($resource->geoLocations()->where('geo_type', 'line')->count())->toBe(1);
    });

    it('preserves all same-bounds boxes when no unique description match exists', function () {
        issue1179CreateLegacyResource('10.5880/ambiguous.boxes');
        issue1179CreateLegacyCoverage(['description' => null]);
        $resource = Resource::factory()->create(['doi' => '10.5880/ambiguous.boxes']);
        $firstBox = GeoLocation::factory()->withBox(11.7763, 12.3128, 49.8099, 50.0529)->create([
            'resource_id' => $resource->id,
        ]);
        $secondBox = GeoLocation::factory()->withBox(11.7763, 12.3128, 49.8099, 50.0529)->create([
            'resource_id' => $resource->id,
        ]);

        app(SumarioPmdCoverageEnrichmentService::class)->enrich($resource, $resource->doi);

        expect(GeoLocation::query()->whereKey($firstBox->id)->exists())->toBeTrue()
            ->and(GeoLocation::query()->whereKey($secondBox->id)->exists())->toBeTrue()
            ->and($resource->geoLocations()->where('geo_type', 'line')->count())->toBe(1);
    });

    it('adds a valid line without deleting a box when legacy bounds are incomplete', function () {
        issue1179CreateLegacyResource('10.5880/incomplete.bounds');
        issue1179CreateLegacyCoverage(['maxlon' => null]);
        $resource = Resource::factory()->create(['doi' => '10.5880/incomplete.bounds']);
        $box = GeoLocation::factory()->withBox(11.7763, 12.3128, 49.8099, 50.0529)->create([
            'resource_id' => $resource->id,
        ]);

        app(SumarioPmdCoverageEnrichmentService::class)->enrich($resource, $resource->doi);

        expect(GeoLocation::query()->whereKey($box->id)->exists())->toBeTrue()
            ->and($resource->geoLocations()->where('geo_type', 'line')->count())->toBe(1);
    });

    it('does not delete a box whose bounds are outside the matching tolerance', function () {
        issue1179CreateLegacyResource('10.5880/different.bounds');
        issue1179CreateLegacyCoverage();
        $resource = Resource::factory()->create(['doi' => '10.5880/different.bounds']);
        $box = GeoLocation::factory()->withBox(11.77631, 12.3128, 49.8099, 50.0529)->create([
            'resource_id' => $resource->id,
            'place' => 'DEKORP KTB8501 seismic profile',
        ]);

        app(SumarioPmdCoverageEnrichmentService::class)->enrich($resource, $resource->doi);

        expect(GeoLocation::query()->whereKey($box->id)->exists())->toBeTrue()
            ->and($resource->geoLocations()->where('geo_type', 'line')->count())->toBe(1);
    });

    it('imports every valid legacy line for a resource', function () {
        issue1179CreateLegacyResource('10.5880/multiple.lines');
        issue1179CreateLegacyCoverage();
        issue1179CreateLegacyCoverage([
            'id' => 2,
            'minlat' => 51.0,
            'maxlat' => 52.0,
            'minlon' => 13.0,
            'maxlon' => 14.0,
            'description' => 'Second profile',
            'wkt' => '13.0 51.0 14.0 52.0',
        ]);
        $resource = Resource::factory()->create(['doi' => '10.5880/multiple.lines']);
        GeoLocation::factory()->withBox(11.7763, 12.3128, 49.8099, 50.0529)->create([
            'resource_id' => $resource->id,
            'place' => 'DEKORP KTB8501 seismic profile',
        ]);
        GeoLocation::factory()->withBox(13.0, 14.0, 51.0, 52.0)->create([
            'resource_id' => $resource->id,
            'place' => 'Second profile',
        ]);

        $updated = app(SumarioPmdCoverageEnrichmentService::class)->enrich($resource, $resource->doi);

        expect($updated)->toBeTrue()
            ->and($resource->geoLocations()->where('geo_type', 'line')->count())->toBe(2)
            ->and($resource->geoLocations()->where('geo_type', 'box')->count())->toBe(0);
    });

    it('skips invalid geometries without logging their raw legacy value', function () {
        Log::spy();
        issue1179CreateLegacyResource('10.5880/invalid.geometry');
        issue1179CreateLegacyCoverage(['wkt' => '13.O57855 52.0 14.0 53.0']);
        $resource = Resource::factory()->create(['doi' => '10.5880/invalid.geometry']);
        $box = GeoLocation::factory()->withBox(11.7763, 12.3128, 49.8099, 50.0529)->create([
            'resource_id' => $resource->id,
        ]);

        $updated = app(SumarioPmdCoverageEnrichmentService::class)->enrich($resource, $resource->doi);

        expect($updated)->toBeFalse()
            ->and(GeoLocation::query()->whereKey($box->id)->exists())->toBeTrue()
            ->and($resource->geoLocations()->where('geo_type', 'line')->exists())->toBeFalse();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Legacy coverage geometry could not be parsed as a line.'
                && $context['doi'] === $resource->doi
                && $context['legacy_resource_id'] === 1
                && $context['coverage_id'] === 1
                && ! array_key_exists('wkt', $context));
    });

    it('leaves the resource unchanged when no non-empty legacy geometry exists', function () {
        issue1179CreateLegacyResource('10.5880/empty.geometry');
        issue1179CreateLegacyCoverage(['wkt' => '   ']);
        $resource = Resource::factory()->create(['doi' => '10.5880/empty.geometry']);

        $updated = app(SumarioPmdCoverageEnrichmentService::class)->enrich($resource, $resource->doi);
        $missingUpdated = app(SumarioPmdCoverageEnrichmentService::class)->enrich($resource, '10.5880/missing');

        expect($updated)->toBeFalse()
            ->and($missingUpdated)->toBeFalse()
            ->and($resource->geoLocations()->count())->toBe(0);
    });

    it('preserves DataCite geolocations when the legacy database lookup fails', function () {
        Log::spy();
        Schema::connection('metaworks')->drop('coverage');
        issue1179CreateLegacyResource('10.5880/legacy.failure');
        $resource = Resource::factory()->create(['doi' => '10.5880/legacy.failure']);
        $box = GeoLocation::factory()->withBox(11.7763, 12.3128, 49.8099, 50.0529)->create([
            'resource_id' => $resource->id,
        ]);

        $updated = app(SumarioPmdCoverageEnrichmentService::class)->enrich($resource, $resource->doi);

        expect($updated)->toBeFalse()
            ->and(GeoLocation::query()->whereKey($box->id)->exists())->toBeTrue()
            ->and($resource->geoLocations()->where('geo_type', 'line')->exists())->toBeFalse();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'SUMARIO coverage enrichment failed; preserving DataCite geolocation metadata.'
                && $context['doi'] === $resource->doi
                && $context['resource_id'] === $resource->id
                && is_string($context['error']));
    });
});

<?php

declare(strict_types=1);

use App\Console\Commands\BackfillLegacyCreatorAndMslMetadata;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\Subject;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\Legacy\LegacyCreatorAndMslMetadataBackfillService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

covers(BackfillLegacyCreatorAndMslMetadata::class, LegacyCreatorAndMslMetadataBackfillService::class);

beforeEach(function (): void {
    Config::set('database.connections.metaworks', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('metaworks');

    Schema::connection('metaworks')->create('resource', function (Blueprint $table): void {
        $table->id();
        $table->string('identifier')->nullable();
        $table->string('publicstatus')->nullable();
        $table->text('keywords')->nullable();
    });
    Schema::connection('metaworks')->create('resourceagent', function (Blueprint $table): void {
        $table->unsignedBigInteger('resource_id');
        $table->unsignedInteger('order');
        $table->string('firstname')->nullable();
        $table->string('lastname')->nullable();
        $table->string('name')->nullable();
        $table->string('identifier')->nullable();
        $table->string('identifiertype')->nullable();
    });
    Schema::connection('metaworks')->create('role', function (Blueprint $table): void {
        $table->unsignedBigInteger('resourceagent_resource_id');
        $table->unsignedInteger('resourceagent_order');
        $table->string('role');
    });
    Schema::connection('metaworks')->create('affiliation', function (Blueprint $table): void {
        $table->unsignedBigInteger('resourceagent_resource_id');
        $table->unsignedInteger('resourceagent_order');
        $table->unsignedInteger('order')->default(0);
        $table->string('name')->nullable();
        $table->string('identifier')->nullable();
    });
    Schema::connection('metaworks')->create('contactinfo', function (Blueprint $table): void {
        $table->unsignedBigInteger('resourceagent_resource_id');
        $table->unsignedInteger('resourceagent_order');
        $table->string('email')->nullable();
        $table->string('website')->nullable();
    });
    Schema::connection('metaworks')->create('thesauruskeyword', function (Blueprint $table): void {
        $table->unsignedBigInteger('resource_id');
        $table->string('keyword');
        $table->string('thesaurus');
    });
    Schema::connection('metaworks')->create('thesaurusvalue', function (Blueprint $table): void {
        $table->string('keyword');
        $table->string('thesaurus');
        $table->string('uri')->nullable();
        $table->text('description')->nullable();
    });
});

afterEach(function (): void {
    foreach (['thesaurusvalue', 'thesauruskeyword', 'contactinfo', 'affiliation', 'role', 'resourceagent', 'resource'] as $table) {
        Schema::connection('metaworks')->dropIfExists($table);
    }
    DB::disconnect('metaworks');
});

/** @return array{resource: Resource, creator: ResourceCreator, legacy_id: int} */
function createLegacyCreatorBackfillFixture(string $doi): array
{
    $legacyId = DB::connection('metaworks')->table('resource')->insertGetId(['identifier' => $doi]);
    DB::connection('metaworks')->table('resourceagent')->insert([
        'resource_id' => $legacyId,
        'order' => 0,
        'firstname' => 'Philipp S.',
        'lastname' => 'Sommer',
        'name' => 'Sommer, Philipp S.',
        'identifier' => '0000-0001-6171-7716',
        'identifiertype' => 'ORCID',
    ]);
    DB::connection('metaworks')->table('role')->insert([
        'resourceagent_resource_id' => $legacyId,
        'resourceagent_order' => 0,
        'role' => 'Creator',
    ]);

    $person = Person::factory()->create([
        'given_name' => 'Philipp',
        'family_name' => 'Sommer',
        'name_identifier' => '0000-0001-6171-7716',
        'name_identifier_scheme' => 'ORCID',
    ]);
    $resource = Resource::factory()->withDoi($doi)->create([
        'legacy_source' => 'sumario-pmd',
        'legacy_source_id' => $legacyId,
    ]);
    $creator = ResourceCreator::factory()->forPerson($person)->create([
        'resource_id' => $resource->id,
    ]);

    return compact('resource', 'creator') + ['legacy_id' => $legacyId];
}

it('is dry-run-first, additive, resource-specific, and idempotent', function (): void {
    $doi = '10.5880/gfz.1.4.2021.008';
    $legacyId = DB::connection('metaworks')->table('resource')->insertGetId([
        'identifier' => $doi,
        'publicstatus' => 'released',
    ]);
    DB::connection('metaworks')->table('resourceagent')->insert([
        'resource_id' => $legacyId,
        'order' => 0,
        'firstname' => 'Philipp S.',
        'lastname' => 'Sommer',
        'name' => 'Sommer, Philipp S.',
        'identifier' => '0000-0001-6171-7716',
        'identifiertype' => 'ORCID',
    ]);
    DB::connection('metaworks')->table('role')->insert([
        'resourceagent_resource_id' => $legacyId,
        'resourceagent_order' => 0,
        'role' => 'Creator',
    ]);
    foreach ([
        ['lava flow', 'EPOS WP16 Analogue Geologic Structure', 'http://epos/WP16Vocabulary/AnalogueGeologicStructure/lava-flow'],
        ['volcano', 'EPOS WP16 Analogue Geologic Structure', null],
    ] as [$keyword, $scheme, $uri]) {
        DB::connection('metaworks')->table('thesauruskeyword')->insert([
            'resource_id' => $legacyId,
            'keyword' => $keyword,
            'thesaurus' => $scheme,
        ]);
        DB::connection('metaworks')->table('thesaurusvalue')->insert([
            'keyword' => $keyword,
            'thesaurus' => $scheme,
            'uri' => $uri,
            'description' => null,
        ]);
    }

    $person = Person::factory()->create([
        'given_name' => 'Philipp',
        'family_name' => 'Sommer',
        'name_identifier' => '0000-0001-6171-7716',
        'name_identifier_scheme' => 'ORCID',
    ]);
    $resource = Resource::factory()->withDoi($doi)->create([
        'legacy_source' => 'sumario-pmd',
        'legacy_source_id' => $legacyId,
    ]);
    $creator = ResourceCreator::factory()->create([
        'resource_id' => $resource->id,
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'position' => 1,
    ]);
    $existingSubject = Subject::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'lava flow',
        'subject_scheme' => 'EPOS WP16 Analogue Geologic Structure',
        'value_uri' => null,
        'breadcrumb_path' => null,
    ]);

    $service = app(LegacyCreatorAndMslMetadataBackfillService::class);
    $dryRun = $service->run(dois: [$doi]);

    expect($dryRun)->toMatchArray([
        'scanned' => 1,
        'changed' => 1,
        'creator_snapshots_written' => 1,
        'visible_creator_changes' => 1,
        'subjects_created' => 1,
        'subjects_enriched' => 1,
        'subject_conflicts' => 0,
        'sync_resource_ids' => [],
    ])->and($creator->fresh()->hasNameSnapshot())->toBeFalse()
        ->and($existingSubject->fresh()->value_uri)->toBeNull()
        ->and($resource->subjects()->count())->toBe(1);

    $applied = $service->run(apply: true, dois: [$doi]);

    expect($applied['sync_resource_ids'])->toBe([$resource->id])
        ->and($creator->fresh())->toMatchArray([
            'name_snapshot' => 'Sommer, Philipp S.',
            'given_name_snapshot' => 'Philipp S.',
            'family_name_snapshot' => 'Sommer',
        ])->and($person->fresh()->given_name)->toBe('Philipp')
        ->and($existingSubject->fresh()->value_uri)
        ->toBe('http://epos/WP16Vocabulary/AnalogueGeologicStructure/lava-flow')
        ->and($existingSubject->fresh()->breadcrumb_path)->toBe('lava flow')
        ->and($resource->subjects()->count())->toBe(2)
        ->and($resource->subjects()->where('value', 'volcano')->firstOrFail()->value_uri)->toBeNull();

    $secondApply = $service->run(apply: true, dois: [$doi]);
    expect($secondApply)->toMatchArray([
        'changed' => 0,
        'unchanged' => 1,
        'creator_snapshots_written' => 0,
        'subjects_created' => 0,
        'subjects_enriched' => 0,
        'sync_resource_ids' => [],
    ]);

    $this->artisan('resources:backfill-legacy-creator-and-msl-metadata', [
        '--doi' => [$doi],
    ])->expectsOutput('Dry run only; no data was changed and no DataCite sync was queued.')
        ->assertSuccessful();
});

it('preserves an existing different snapshot and conflicting subject URI for manual review', function (): void {
    $doi = '10.5880/conflict';
    $legacyId = DB::connection('metaworks')->table('resource')->insertGetId(['identifier' => $doi]);
    DB::connection('metaworks')->table('resourceagent')->insert([
        'resource_id' => $legacyId,
        'order' => 0,
        'firstname' => 'Philipp S.',
        'lastname' => 'Sommer',
        'name' => 'Sommer, Philipp S.',
        'identifier' => '0000-0001-6171-7716',
        'identifiertype' => 'ORCID',
    ]);
    DB::connection('metaworks')->table('role')->insert([
        'resourceagent_resource_id' => $legacyId,
        'resourceagent_order' => 0,
        'role' => 'Creator',
    ]);
    DB::connection('metaworks')->table('thesauruskeyword')->insert([
        'resource_id' => $legacyId,
        'keyword' => 'lava flow',
        'thesaurus' => 'EPOS WP16 Analogue Geologic Structure',
    ]);
    DB::connection('metaworks')->table('thesaurusvalue')->insert([
        'keyword' => 'lava flow',
        'thesaurus' => 'EPOS WP16 Analogue Geologic Structure',
        'uri' => 'https://legacy.example/source',
    ]);

    $person = Person::factory()->create([
        'given_name' => 'Philipp',
        'family_name' => 'Sommer',
        'name_identifier' => '0000-0001-6171-7716',
        'name_identifier_scheme' => 'ORCID',
    ]);
    $resource = Resource::factory()->withDoi($doi)->create([
        'legacy_source' => 'sumario-pmd',
        'legacy_source_id' => $legacyId,
    ]);
    $creator = ResourceCreator::factory()->create([
        'resource_id' => $resource->id,
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'name_snapshot' => 'Sommer, Philipp A.',
        'given_name_snapshot' => 'Philipp A.',
        'family_name_snapshot' => 'Sommer',
    ]);
    $subject = Subject::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'lava flow',
        'subject_scheme' => 'EPOS MSL vocabulary',
        'value_uri' => 'https://curated.example/value',
    ]);

    $result = app(LegacyCreatorAndMslMetadataBackfillService::class)->run(apply: true);

    expect($result['manual_review'])->toBe(1)
        ->and($result['subject_conflicts'])->toBe(1)
        ->and($result['sync_resource_ids'])->toBe([])
        ->and($creator->fresh()->given_name_snapshot)->toBe('Philipp A.')
        ->and($subject->fresh()->value_uri)->toBe('https://curated.example/value');
});

it('rejects a creator or subject change made after the scan as concurrent', function (): void {
    ['resource' => $resource, 'creator' => $creator] = createLegacyCreatorBackfillFixture('10.5880/concurrent');
    $changed = false;

    DB::listen(function (QueryExecuted $query) use ($creator, &$changed): void {
        if ($changed || $query->connectionName !== 'metaworks' || ! str_contains($query->sql, 'resourceagent')) {
            return;
        }

        $changed = true;
        $creator->update([
            'name_snapshot' => 'Sommer, Philipp C.',
            'given_name_snapshot' => 'Philipp C.',
            'family_name_snapshot' => 'Sommer',
        ]);
    });

    $result = app(LegacyCreatorAndMslMetadataBackfillService::class)->run(apply: true);

    expect($changed)->toBeTrue()
        ->and($result)->toMatchArray([
            'changed' => 0,
            'unchanged' => 0,
            'concurrent_changes' => 1,
            'sync_resource_ids' => [],
        ])->and($result['records'][0]['status'])->toBe('concurrent_change')
        ->and($creator->fresh()->given_name_snapshot)->toBe('Philipp C.')
        ->and($resource->subjects()->count())->toBe(0);
});

it('reports a failed published landing-page cache invalidation without losing the applied change', function (): void {
    ['resource' => $resource, 'creator' => $creator] = createLegacyCreatorBackfillFixture('10.5880/cache-failure');
    $landingPage = LandingPage::factory()->published()->create(['resource_id' => $resource->id]);
    $cache = Mockery::mock(LandingPageRenderDataCacheService::class);
    $cache->shouldReceive('forgetById')->once()->with($landingPage->id)->andReturnFalse();
    app()->instance(LandingPageRenderDataCacheService::class, $cache);

    $result = app(LegacyCreatorAndMslMetadataBackfillService::class)->run(apply: true);

    expect($result)->toMatchArray([
        'changed' => 1,
        'cache_invalidation_failures' => 1,
        'sync_resource_ids' => [$resource->id],
    ])->and($result['records'][0]['cache_invalidation_failed'])->toBe(1)
        ->and($result['records'][0]['status'])->toBe('updated_with_warnings')
        ->and($creator->fresh()->given_name_snapshot)->toBe('Philipp S.');
});

it('uses DOI fallback only when explicitly enabled', function (): void {
    ['resource' => $resource] = createLegacyCreatorBackfillFixture('10.5880/doi-fallback');
    $resource->update(['legacy_source' => null, 'legacy_source_id' => null]);
    $service = app(LegacyCreatorAndMslMetadataBackfillService::class);

    expect($service->run(dois: [$resource->doi])['scanned'])->toBe(0);

    $result = $service->run(dois: [$resource->doi], matchByDoi: true);

    expect($result)->toMatchArray([
        'scanned' => 1,
        'changed' => 1,
        'creator_snapshots_written' => 1,
    ])->and($result['records'][0]['match_method'])->toBe('doi');
});

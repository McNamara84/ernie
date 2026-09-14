<?php

declare(strict_types=1);

use App\Console\Commands\BackfillLegacyCreatorAndMslMetadata;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\Subject;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\ImportedResourceDataCiteSyncDispatcherService;
use App\Services\ImportProgressService;
use App\Services\Legacy\LegacyCreatorAndMslMetadataBackfillService;
use Illuminate\Console\Command;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class LegacyBackfillFailingCsvStreamWrapper
{
    public mixed $context;

    public static int $successfulWrites = 0;

    private int $writes = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->writes = 0;

        return true;
    }

    public function stream_write(string $data): int
    {
        if ($this->writes >= self::$successfulWrites) {
            return 0;
        }

        $this->writes++;

        return strlen($data);
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }
}

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

/** @return array{resource: Resource, creator: ResourceCreator, person: Person, legacy_id: int} */
function createLegacyCreatorBackfillFixture(string $doi, ?Person $person = null): array
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

    $person ??= Person::factory()->create([
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

    return compact('resource', 'creator', 'person') + ['legacy_id' => $legacyId];
}

it('sorts creators by position and id before dry-run position matching', function (): void {
    $resource = Resource::factory()->create();
    $firstPerson = Person::factory()->create([
        'given_name' => 'Alice',
        'family_name' => 'Alpha',
    ]);
    $secondPerson = Person::factory()->create([
        'given_name' => 'Bob',
        'family_name' => 'Beta',
    ]);
    $firstCreator = ResourceCreator::factory()->forPerson($firstPerson)->create([
        'resource_id' => $resource->id,
        'position' => 1,
    ]);
    $secondCreator = ResourceCreator::factory()->forPerson($secondPerson)->create([
        'resource_id' => $resource->id,
        'position' => 1,
    ]);
    $resource->setRelation('creators', $firstCreator->newCollection([
        $secondCreator->load('creatorable'),
        $firstCreator->load('creatorable'),
    ]));
    $resource->setRelation('subjects', Subject::query()->where('resource_id', $resource->id)->get());

    $backfillResource = new ReflectionMethod(LegacyCreatorAndMslMetadataBackfillService::class, 'backfillResource');
    $result = $backfillResource->invoke(
        app(LegacyCreatorAndMslMetadataBackfillService::class),
        $resource,
        [
            [
                'name' => 'Alpha, Alice',
                'nameType' => 'Personal',
                'givenName' => 'Alice',
                'familyName' => 'Alpha',
            ],
            [
                'name' => 'Beta, Bob',
                'nameType' => 'Personal',
                'givenName' => 'Bob',
                'familyName' => 'Beta',
            ],
        ],
        [],
        false,
    );

    expect($result)->toMatchArray([
        'creator_snapshots_written' => 2,
        'creator_match_methods' => 'position_and_name:identical|position_and_name:identical',
    ])->and($resource->creators->pluck('id')->all())->toBe([
        $firstCreator->id,
        $secondCreator->id,
    ])->and($firstCreator->fresh()->hasNameSnapshot())->toBeFalse()
        ->and($secondCreator->fresh()->hasNameSnapshot())->toBeFalse();
});

it('backfills an unstructured snapshot onto a nameless person through an unambiguous position', function (): void {
    $doi = '10.5880/unstructured-position-snapshot';
    $legacyId = DB::connection('metaworks')->table('resource')->insertGetId(['identifier' => $doi]);
    DB::connection('metaworks')->table('resourceagent')->insert([
        'resource_id' => $legacyId,
        'order' => 0,
        'firstname' => null,
        'lastname' => null,
        'name' => 'The Artist',
        'identifier' => null,
        'identifiertype' => null,
    ]);
    DB::connection('metaworks')->table('role')->insert([
        'resourceagent_resource_id' => $legacyId,
        'resourceagent_order' => 0,
        'role' => 'Creator',
    ]);
    $person = Person::factory()->create([
        'given_name' => '',
        'family_name' => '',
    ]);
    $resource = Resource::factory()->withDoi($doi)->create([
        'legacy_source' => 'sumario-pmd',
        'legacy_source_id' => $legacyId,
    ]);
    $creator = ResourceCreator::factory()->forPerson($person)->create([
        'resource_id' => $resource->id,
        'position' => 0,
    ]);
    $service = app(LegacyCreatorAndMslMetadataBackfillService::class);

    $dryRun = $service->run(dois: [$doi], retainRecords: true);

    expect($dryRun)->toMatchArray([
        'changed' => 1,
        'creator_snapshots_written' => 1,
        'manual_review' => 0,
    ])->and($dryRun['records'][0]['creator_match_methods'])->toBe('position_only:merged')
        ->and($creator->fresh()->hasNameSnapshot())->toBeFalse();

    $applied = $service->run(apply: true, dois: [$doi], retainRecords: true);

    expect($applied)->toMatchArray([
        'changed' => 1,
        'creator_snapshots_written' => 1,
        'manual_review' => 0,
    ])->and($creator->fresh())->toMatchArray([
        'name_snapshot' => 'The Artist',
        'given_name_snapshot' => null,
        'family_name_snapshot' => null,
    ]);
});

it('persists an ORCID-matched unstructured legacy spelling without replacing global person fields', function (): void {
    $doi = '10.5880/unstructured-orcid-snapshot';
    $legacyId = DB::connection('metaworks')->table('resource')->insertGetId(['identifier' => $doi]);
    DB::connection('metaworks')->table('resourceagent')->insert([
        'resource_id' => $legacyId,
        'order' => 0,
        'firstname' => null,
        'lastname' => null,
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
        'position' => 0,
    ]);

    $result = app(LegacyCreatorAndMslMetadataBackfillService::class)->run(
        apply: true,
        dois: [$doi],
        retainRecords: true,
    );

    expect($result)->toMatchArray([
        'changed' => 1,
        'creator_snapshots_written' => 1,
        'manual_review' => 0,
    ])->and($result['records'][0]['creator_match_methods'])->toBe('orcid:snapshot_only')
        ->and($creator->fresh())->toMatchArray([
            'name_snapshot' => 'Sommer, Philipp S.',
            'given_name_snapshot' => null,
            'family_name_snapshot' => null,
        ])->and($person->fresh())->toMatchArray([
            'given_name' => 'Philipp',
            'family_name' => 'Sommer',
        ]);
});

it('truncates legacy creator snapshots to their database column limits', function (): void {
    $doi = '10.5880/bounded-legacy-snapshots';
    $name = str_repeat('Ä', ResourceCreator::MAX_NAME_SNAPSHOT_LENGTH + 5);
    $givenName = str_repeat('Ö', ResourceCreator::MAX_STRUCTURED_NAME_SNAPSHOT_LENGTH + 5);
    $familyName = str_repeat('Ü', ResourceCreator::MAX_STRUCTURED_NAME_SNAPSHOT_LENGTH + 5);
    $orcid = '0000-0002-1825-0097';
    $legacyId = DB::connection('metaworks')->table('resource')->insertGetId(['identifier' => $doi]);
    DB::connection('metaworks')->table('resourceagent')->insert([
        'resource_id' => $legacyId,
        'order' => 0,
        'firstname' => $givenName,
        'lastname' => $familyName,
        'name' => $name,
        'identifier' => $orcid,
        'identifiertype' => 'ORCID',
    ]);
    DB::connection('metaworks')->table('role')->insert([
        'resourceagent_resource_id' => $legacyId,
        'resourceagent_order' => 0,
        'role' => 'Creator',
    ]);
    $person = Person::factory()->create([
        'given_name' => $givenName,
        'family_name' => $familyName,
        'name_identifier' => $orcid,
        'name_identifier_scheme' => 'ORCID',
    ]);
    $resource = Resource::factory()->withDoi($doi)->create([
        'legacy_source' => 'sumario-pmd',
        'legacy_source_id' => $legacyId,
    ]);
    $creator = ResourceCreator::factory()->forPerson($person)->create([
        'resource_id' => $resource->id,
    ]);

    $result = app(LegacyCreatorAndMslMetadataBackfillService::class)->run(
        apply: true,
        dois: [$doi],
    );

    expect($result)->toMatchArray([
        'changed' => 1,
        'errors' => 0,
        'creator_snapshots_written' => 1,
    ])->and($creator->fresh())->toMatchArray([
        'name_snapshot' => str_repeat('Ä', ResourceCreator::MAX_NAME_SNAPSHOT_LENGTH),
        'given_name_snapshot' => str_repeat('Ö', ResourceCreator::MAX_STRUCTURED_NAME_SNAPSHOT_LENGTH),
        'family_name_snapshot' => str_repeat('Ü', ResourceCreator::MAX_STRUCTURED_NAME_SNAPSHOT_LENGTH),
    ]);
});

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
        ['volcano', 'epos wp16 analogue geologic structure', null],
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

it('keeps hierarchical MSL paths from distinct WP16 categories in the canonical storage shape', function (): void {
    ['resource' => $resource, 'legacy_id' => $legacyId] = createLegacyCreatorBackfillFixture(
        '10.5880/distinct-wp16-categories',
    );
    $legacySubjects = [
        [
            'scheme' => 'EPOS WP16 Analogue Material',
            'uri' => 'http://epos/WP16Vocabulary/AnalogueMaterial/Rock/Granite',
        ],
        [
            'scheme' => 'EPOS WP16 Rock Physics Material',
            'uri' => 'http://epos/WP16Vocabulary/RockPhysicsMaterial/Rock/Granite',
        ],
    ];
    foreach ($legacySubjects as $legacySubject) {
        DB::connection('metaworks')->table('thesauruskeyword')->insert([
            'resource_id' => $legacyId,
            'keyword' => 'Rock > Granite',
            'thesaurus' => $legacySubject['scheme'],
        ]);
        DB::connection('metaworks')->table('thesaurusvalue')->insert([
            'keyword' => 'Rock > Granite',
            'thesaurus' => $legacySubject['scheme'],
            'uri' => $legacySubject['uri'],
            'description' => null,
        ]);
    }
    Subject::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'Granite',
        'subject_scheme' => 'EPOS WP16 Analogue Material',
        'value_uri' => null,
        'breadcrumb_path' => 'Rock > Granite',
    ]);

    $result = app(LegacyCreatorAndMslMetadataBackfillService::class)->run(
        apply: true,
        dois: [$resource->doi],
    );
    $subjects = $resource->subjects()->orderBy('subject_scheme')->get();

    expect($result)->toMatchArray([
        'subjects_created' => 1,
        'subjects_enriched' => 1,
        'subject_conflicts' => 0,
    ])->and($subjects)->toHaveCount(2)
        ->and($subjects->pluck('value')->all())->toBe(['Granite', 'Granite'])
        ->and($subjects->pluck('breadcrumb_path')->all())->toBe([
            'Rock > Granite',
            'Rock > Granite',
        ])
        ->and($subjects->pluck('subject_scheme')->all())->toBe(array_column($legacySubjects, 'scheme'))
        ->and($subjects->pluck('value_uri')->all())->toBe(array_column($legacySubjects, 'uri'));

    expect(app(LegacyCreatorAndMslMetadataBackfillService::class)->run(
        apply: true,
        dois: [$resource->doi],
    ))->toMatchArray([
        'changed' => 0,
        'subjects_created' => 0,
        'subjects_enriched' => 0,
    ]);
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
        'subject_scheme' => 'EPOS WP16 Analogue Geologic Structure',
        'value_uri' => 'https://curated.example/value',
    ]);

    $result = app(LegacyCreatorAndMslMetadataBackfillService::class)->run(
        apply: true,
        retainRecords: true,
    );

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

    $result = app(LegacyCreatorAndMslMetadataBackfillService::class)->run(
        apply: true,
        retainRecords: true,
    );

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

it('rejects a related person change made after the scan as concurrent', function (
    string $field,
    mixed $newValue,
): void {
    ['resource' => $resource, 'creator' => $creator, 'person' => $person] = createLegacyCreatorBackfillFixture(
        "10.5880/concurrent-person-{$field}",
    );
    $changed = false;

    DB::listen(function (QueryExecuted $query) use ($person, $field, $newValue, &$changed): void {
        if ($changed || $query->connectionName !== 'metaworks' || ! str_contains($query->sql, 'resourceagent')) {
            return;
        }

        $changed = true;
        $person->update([$field => $newValue]);
    });

    $result = app(LegacyCreatorAndMslMetadataBackfillService::class)->run(
        apply: true,
        retainRecords: true,
    );

    expect($changed)->toBeTrue()
        ->and($result)->toMatchArray([
            'changed' => 0,
            'unchanged' => 0,
            'concurrent_changes' => 1,
            'sync_resource_ids' => [],
        ])->and($result['records'][0]['status'])->toBe('concurrent_change')
        ->and($creator->fresh()->hasNameSnapshot())->toBeFalse()
        ->and($person->fresh()->getAttribute($field))->toBe($newValue);
})->with([
    'given name' => ['given_name', 'Changed'],
    'family name' => ['family_name', 'Changed'],
    'ORCID' => ['name_identifier', '0000-0002-1825-0097'],
    'ORCID scheme' => ['name_identifier_scheme', null],
]);

it('rejects changed resource match keys after resolving the legacy resource', function (
    string $field,
    mixed $newValue,
): void {
    ['resource' => $resource, 'creator' => $creator] = createLegacyCreatorBackfillFixture(
        "10.5880/concurrent-{$field}",
    );
    $changed = false;

    DB::listen(function (QueryExecuted $query) use ($resource, $field, $newValue, &$changed): void {
        if ($changed || $query->connectionName !== 'metaworks' || ! str_contains($query->sql, 'resourceagent')) {
            return;
        }

        $changed = true;
        $resource->update([$field => $newValue]);
    });

    $result = app(LegacyCreatorAndMslMetadataBackfillService::class)->run(
        apply: true,
        retainRecords: true,
    );

    expect($changed)->toBeTrue()
        ->and($result)->toMatchArray([
            'changed' => 0,
            'unchanged' => 0,
            'concurrent_changes' => 1,
            'sync_resource_ids' => [],
        ])->and($result['records'][0]['status'])->toBe('concurrent_change')
        ->and($creator->fresh()->hasNameSnapshot())->toBeFalse()
        ->and($resource->fresh()->getAttribute($field))->toBe($newValue);
})->with([
    'doi' => ['doi', '10.5880/concurrent-doi-relinked'],
    'legacy source' => ['legacy_source', 'another-source'],
    'legacy source id' => ['legacy_source_id', 999999],
]);

it('reports a failed published landing-page cache invalidation without losing the applied change', function (): void {
    ['resource' => $resource, 'creator' => $creator] = createLegacyCreatorBackfillFixture('10.5880/cache-failure');
    $landingPage = LandingPage::factory()->published()->create(['resource_id' => $resource->id]);
    $cache = Mockery::mock(LandingPageRenderDataCacheService::class);
    $cache->shouldReceive('forgetById')->once()->with($landingPage->id)->andReturnFalse();
    app()->instance(LandingPageRenderDataCacheService::class, $cache);

    $result = app(LegacyCreatorAndMslMetadataBackfillService::class)->run(
        apply: true,
        retainRecords: true,
    );

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

    $result = $service->run(
        dois: [$resource->doi],
        matchByDoi: true,
        retainRecords: true,
    );

    expect($result)->toMatchArray([
        'scanned' => 1,
        'changed' => 1,
        'creator_snapshots_written' => 1,
    ])->and($result['records'][0]['match_method'])->toBe('doi');
});

it('reserves manual review for an ambiguous DOI fallback match', function (): void {
    ['resource' => $resource] = createLegacyCreatorBackfillFixture('10.5880/ambiguous-doi-fallback');
    $resource->update(['legacy_source' => null, 'legacy_source_id' => null]);
    DB::connection('metaworks')->table('resource')->insert([
        'identifier' => $resource->doi,
    ]);

    $result = app(LegacyCreatorAndMslMetadataBackfillService::class)->run(
        dois: [$resource->doi],
        matchByDoi: true,
        retainRecords: true,
    );

    expect($result)->toMatchArray([
        'manual_review' => 1,
        'errors' => 0,
    ])->and($result['records'][0]['status'])->toBe('manual_review')
        ->and($result['records'][0]['message'])->toContain('Multiple SUMARIO resources');
});

it('reports a storage query failure as an error and makes the command fail', function (): void {
    ['resource' => $resource, 'creator' => $creator] = createLegacyCreatorBackfillFixture(
        '10.5880/storage-query-failure',
    );
    $failed = false;

    DB::listen(function (QueryExecuted $query) use (&$failed): void {
        if ($failed
            || $query->connectionName === 'metaworks'
            || ! str_starts_with(mb_strtolower(ltrim($query->sql)), 'update')
            || ! str_contains($query->sql, 'resource_creators')
        ) {
            return;
        }

        $failed = true;
        throw new QueryException(
            $query->connectionName,
            $query->sql,
            $query->bindings,
            new PDOException('Simulated resource creator storage failure.'),
        );
    });

    $service = app(LegacyCreatorAndMslMetadataBackfillService::class);
    $result = $service->run(
        apply: true,
        dois: [$resource->doi],
        retainRecords: true,
    );

    expect($failed)->toBeTrue()
        ->and($result)->toMatchArray([
            'changed' => 0,
            'manual_review' => 0,
            'errors' => 1,
            'sync_resource_ids' => [],
        ])->and($result['records'][0]['status'])->toBe('error')
        ->and($creator->fresh()->hasNameSnapshot())->toBeFalse();

    $failed = false;
    $exitCode = Artisan::call('resources:backfill-legacy-creator-and-msl-metadata', [
        '--apply' => true,
        '--doi' => [$resource->doi],
    ]);

    expect($failed)->toBeTrue()
        ->and($exitCode)->toBe(Command::FAILURE)
        ->and($creator->fresh()->hasNameSnapshot())->toBeFalse();
});

it('streams audit records without retaining the full result set', function (): void {
    ['resource' => $resource] = createLegacyCreatorBackfillFixture('10.5880/streamed-report');
    $records = [];

    $result = app(LegacyCreatorAndMslMetadataBackfillService::class)->run(
        dois: [$resource->doi],
        chunk: 1,
        recordConsumer: function (array $record) use (&$records): void {
            $records[] = $record;
        },
    );

    expect($result['scanned'])->toBe(1)
        ->and($result['records'])->toBe([])
        ->and($records)->toHaveCount(1)
        ->and($records[0]['resource_id'])->toBe($resource->id)
        ->and($records[0]['status'])->toBe('would_update');
});

it('preserves all DataCite sync candidates when report streaming fails after applied rows', function (): void {
    $first = createLegacyCreatorBackfillFixture('10.5880/report-failure-first');
    $second = createLegacyCreatorBackfillFixture('10.5880/report-failure-second', $first['person']);
    $third = createLegacyCreatorBackfillFixture('10.5880/report-failure-third', $first['person']);
    $consumerCalls = 0;

    $result = app(LegacyCreatorAndMslMetadataBackfillService::class)->run(
        apply: true,
        chunk: 1,
        recordConsumer: function (array $_record) use (&$consumerCalls): void {
            $consumerCalls++;
            if ($consumerCalls === 2) {
                throw new RuntimeException('Simulated full report disk.');
            }
        },
    );

    expect($consumerCalls)->toBe(2)
        ->and($result['record_consumer_error'])->toBe('Simulated full report disk.')
        ->and($result['scanned'])->toBe(2)
        ->and($result['changed'])->toBe(2)
        ->and($result['sync_resource_ids'])->toBe([
            $first['resource']->id,
            $second['resource']->id,
        ])
        ->and($first['creator']->fresh()->given_name_snapshot)->toBe('Philipp S.')
        ->and($second['creator']->fresh()->given_name_snapshot)->toBe('Philipp S.')
        ->and($third['creator']->fresh()->hasNameSnapshot())->toBeFalse();
});

it('dispatches applied DataCite changes before failing for an incomplete CSV report', function (): void {
    ['resource' => $resource, 'creator' => $creator] = createLegacyCreatorBackfillFixture(
        '10.5880/incomplete-command-report',
    );
    Config::set('datacite.test_mode', true);
    $scheme = 'legacy-backfill-failing-csv';
    $wrapperDirectory = (string) getcwd().DIRECTORY_SEPARATOR.$scheme.':';
    File::makeDirectory($wrapperDirectory);
    expect(stream_wrapper_register($scheme, LegacyBackfillFailingCsvStreamWrapper::class))->toBeTrue();

    try {
        LegacyBackfillFailingCsvStreamWrapper::$successfulWrites = 1;
        $exitCode = Artisan::call('resources:backfill-legacy-creator-and-msl-metadata', [
            '--doi' => [$resource->doi],
            '--apply' => true,
            '--report' => $scheme.'://report.csv',
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(Command::FAILURE)
            ->and($output)->toContain('DataCite full-metadata sync run:')
            ->and($output)->toContain('Unable to write the complete backfill report:')
            ->and($creator->fresh()->given_name_snapshot)->toBe('Philipp S.');

        preg_match('/DataCite full-metadata sync run: ([0-9a-f-]+)/i', $output, $matches);
        $syncRunId = $matches[1] ?? null;
        expect($syncRunId)->toBeString();
        if (! is_string($syncRunId)) {
            return;
        }

        $progress = app(ImportProgressService::class)->get(
            ImportProgressService::TYPE_RESOURCE,
            $syncRunId,
        );
        expect($progress)->toMatchArray([
            'status' => 'completed',
            'sync_total' => 1,
            'sync_full_metadata_total' => 1,
            'sync_skipped_test_mode' => true,
        ]);
    } finally {
        stream_wrapper_unregister($scheme);
        File::deleteDirectory($wrapperDirectory);
    }
});

it('preserves a retryable DataCite sync run when batch dispatch fails', function (): void {
    ['resource' => $resource, 'creator' => $creator] = createLegacyCreatorBackfillFixture(
        '10.5880/failed-sync-dispatch',
    );
    $reportPath = sys_get_temp_dir().'/ernie-failed-sync-dispatch-'.bin2hex(random_bytes(8)).'.csv';
    Config::set('datacite.test_mode', false);
    $dispatcher = Mockery::mock(ImportedResourceDataCiteSyncDispatcherService::class);
    $dispatcher->shouldReceive('dispatch')
        ->once()
        ->andThrow(new RuntimeException('Queue connection unavailable.'));
    app()->instance(ImportedResourceDataCiteSyncDispatcherService::class, $dispatcher);

    try {
        $exitCode = Artisan::call('resources:backfill-legacy-creator-and-msl-metadata', [
            '--doi' => [$resource->doi],
            '--apply' => true,
            '--report' => $reportPath,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(Command::FAILURE)
            ->and($output)->toContain('Unable to dispatch the DataCite synchronization: Queue connection unavailable.')
            ->and($output)->toContain('DataCite full-metadata sync run:')
            ->and($creator->fresh()->given_name_snapshot)->toBe('Philipp S.');

        preg_match('/DataCite full-metadata sync run: ([0-9a-f-]+)/i', $output, $matches);
        $syncRunId = $matches[1] ?? null;
        expect($syncRunId)->toBeString();
        if (! is_string($syncRunId)) {
            return;
        }

        $progress = app(ImportProgressService::class);
        expect($progress->get(ImportProgressService::TYPE_RESOURCE, $syncRunId))->toMatchArray([
            'status' => 'completed',
            'phase' => 'completed',
            'sync_total' => 1,
            'sync_processed' => 1,
            'sync_succeeded' => 0,
            'sync_failed' => 1,
            'sync_full_metadata_total' => 1,
            'sync_retry_available' => true,
        ])->and($progress->failedResourceIds(ImportProgressService::TYPE_RESOURCE, $syncRunId))
            ->toBe([$resource->id])
            ->and($progress->fullMetadataResourceIds(ImportProgressService::TYPE_RESOURCE, $syncRunId))
            ->toBe([$resource->id]);

        $stream = fopen($reportPath, 'rb');
        expect($stream)->not->toBeFalse();
        if ($stream === false) {
            return;
        }

        try {
            $columns = fgetcsv($stream, escape: '');
            $row = fgetcsv($stream, escape: '');
        } finally {
            fclose($stream);
        }

        expect($columns)->toBeArray()
            ->and($row)->toBeArray();
        if (! is_array($columns) || ! is_array($row)) {
            return;
        }

        $record = array_combine($columns, $row);
        expect($record)->toBeArray()
            ->and($record['datacite_sync_status'] ?? null)->toBe('pending:'.$syncRunId)
            ->and($record['datacite_sync_status'] ?? null)->not->toStartWith('queued:');
    } finally {
        if (file_exists($reportPath)) {
            unlink($reportPath);
        }
    }
});

it('streams the command report to CSV while resources are processed', function (): void {
    ['resource' => $resource] = createLegacyCreatorBackfillFixture('10.5880/streamed-command-report');
    Config::set('datacite.test_mode', true);
    $reportPath = sys_get_temp_dir().'/ernie-backfill-'.bin2hex(random_bytes(8)).'.csv';

    try {
        $this->artisan('resources:backfill-legacy-creator-and-msl-metadata', [
            '--doi' => [$resource->doi],
            '--apply' => true,
            '--report' => $reportPath,
        ])->expectsOutput('Backfill report written to '.$reportPath)
            ->assertSuccessful();

        $stream = fopen($reportPath, 'rb');
        expect($stream)->not->toBeFalse();
        if ($stream === false) {
            return;
        }

        try {
            $columns = fgetcsv($stream, escape: '');
            $row = fgetcsv($stream, escape: '');
            $end = fgetcsv($stream, escape: '');
        } finally {
            fclose($stream);
        }

        expect($columns)->toBeArray()
            ->and($row)->toBeArray()
            ->and($end)->toBeFalse();

        if (! is_array($columns) || ! is_array($row)) {
            return;
        }

        $record = array_combine($columns, $row);
        expect($record)->toBeArray()
            ->and($record['resource_id'] ?? null)->toBe((string) $resource->id)
            ->and($record['status'] ?? null)->toBe('updated')
            ->and($record['datacite_sync_status'] ?? null)->toBe('skipped_test_mode');
    } finally {
        if (file_exists($reportPath)) {
            unlink($reportPath);
        }
    }
});

it('rejects retrying a DataCite synchronization that is still running', function (): void {
    $syncRunId = '5cd20d2a-77f1-4ba0-bf98-e03076e9e8b4';
    app(ImportProgressService::class)->update(ImportProgressService::TYPE_RESOURCE, $syncRunId, [
        'status' => 'running',
        'phase' => 'syncing',
        'sync_failed' => 1,
        'sync_retry_available' => true,
    ]);

    $dispatcher = Mockery::mock(ImportedResourceDataCiteSyncDispatcherService::class);
    $dispatcher->shouldNotReceive('retryFailures');
    app()->instance(ImportedResourceDataCiteSyncDispatcherService::class, $dispatcher);

    $this->artisan('resources:backfill-legacy-creator-and-msl-metadata', [
        '--retry-sync' => $syncRunId,
    ])->expectsOutput('A DataCite synchronization is already running.')
        ->assertFailed();

    expect(app(ImportProgressService::class)->get(
        ImportProgressService::TYPE_RESOURCE,
        $syncRunId,
    ))->toMatchArray([
        'status' => 'running',
        'sync_failed' => 1,
        'sync_retry_available' => true,
    ]);
});

it('retries failed DataCite synchronizations after the prior run completed', function (): void {
    $syncRunId = 'c2a7d31a-d056-47fb-b5ac-fbe6bab2695d';
    app(ImportProgressService::class)->update(ImportProgressService::TYPE_RESOURCE, $syncRunId, [
        'status' => 'completed',
        'phase' => 'completed',
        'sync_failed' => 1,
        'sync_retry_available' => true,
    ]);

    $dispatcher = Mockery::mock(ImportedResourceDataCiteSyncDispatcherService::class);
    $dispatcher->shouldReceive('retryFailures')
        ->once()
        ->with(ImportProgressService::TYPE_RESOURCE, $syncRunId)
        ->andReturnTrue();
    app()->instance(ImportedResourceDataCiteSyncDispatcherService::class, $dispatcher);

    $this->artisan('resources:backfill-legacy-creator-and-msl-metadata', [
        '--retry-sync' => $syncRunId,
    ])->expectsOutput('Failed DataCite synchronizations were queued again.')
        ->assertSuccessful();
});

it('uses a bounded existence query for the legacy database preflight', function (): void {
    ['resource' => $resource] = createLegacyCreatorBackfillFixture('10.5880/preflight-exists');
    $preflightSql = null;

    DB::listen(function (QueryExecuted $query) use (&$preflightSql): void {
        if ($preflightSql === null && $query->connectionName === 'metaworks') {
            $preflightSql = mb_strtolower($query->sql);
        }
    });

    app(LegacyCreatorAndMslMetadataBackfillService::class)->run(dois: [$resource->doi]);

    expect($preflightSql)->toContain('exists')
        ->and($preflightSql)->not->toContain('count(');
});

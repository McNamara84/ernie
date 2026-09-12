<?php

declare(strict_types=1);

use App\Models\Person;
use App\Models\User;
use App\Services\DataCiteCreatorNameMergeService;
use App\Services\DataCiteJsonExporter;
use App\Services\DataCiteSubjectMergeService;
use App\Services\DataCiteToResourceTransformer;
use App\Services\Editor\EditorDataTransformer;
use App\Services\LegacyKeywordService;
use App\Services\LegacyResourceLookupService;
use App\Services\Xml\OriginalDataCiteSubjectExtractionService;
use Database\Seeders\ContributorTypeSeeder;
use Database\Seeders\DescriptionTypeSeeder;
use Database\Seeders\IdentifierTypeSeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PublisherSeeder;
use Database\Seeders\RelationTypeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\TitleTypeSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    test()->seed(ResourceTypeSeeder::class);
    test()->seed(TitleTypeSeeder::class);
    test()->seed(DescriptionTypeSeeder::class);
    test()->seed(ContributorTypeSeeder::class);
    test()->seed(IdentifierTypeSeeder::class);
    test()->seed(LanguageSeeder::class);
    test()->seed(PublisherSeeder::class);
    test()->seed(RelationTypeSeeder::class);

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
        $table->text('keywords')->nullable();
    });
    Schema::connection('metaworks')->create('relatedidentifier', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('resource_id');
        $table->string('identifier')->nullable();
        $table->string('identifiertype')->nullable();
        $table->string('relationtype')->nullable();
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
});

afterEach(function (): void {
    Schema::connection('metaworks')->dropIfExists('contactinfo');
    Schema::connection('metaworks')->dropIfExists('affiliation');
    Schema::connection('metaworks')->dropIfExists('role');
    Schema::connection('metaworks')->dropIfExists('resourceagent');
    Schema::connection('metaworks')->dropIfExists('thesaurusvalue');
    Schema::connection('metaworks')->dropIfExists('thesauruskeyword');
    Schema::connection('metaworks')->dropIfExists('relatedidentifier');
    Schema::connection('metaworks')->dropIfExists('resource');
    DB::disconnect('metaworks');
});

it('imports Issue 1318 creator spelling per resource and all Issue 1319 legacy MSL subjects', function (): void {
    $doi = '10.5880/fidgeo.2024.038';
    $legacyResourceId = DB::connection('metaworks')->table('resource')->insertGetId([
        'identifier' => $doi,
        'keywords' => null,
    ]);
    DB::connection('metaworks')->table('resourceagent')->insert([
        'resource_id' => $legacyResourceId,
        'order' => 0,
        'firstname' => 'Philipp S.',
        'lastname' => 'Sommer',
        'name' => 'Sommer, Philipp S.',
        'identifier' => '0000-0001-6171-7716',
        'identifiertype' => 'ORCID',
    ]);
    DB::connection('metaworks')->table('role')->insert([
        'resourceagent_resource_id' => $legacyResourceId,
        'resourceagent_order' => 0,
        'role' => 'Creator',
    ]);

    $legacySubjects = [
        ['lava flow', 'EPOS WP16 Analogue Geologic Structure'],
        ['volcano', 'EPOS WP16 Analogue Geologic Structure'],
        ['magmatic process', 'EPOS WP16 Analogue Process/Hazard'],
        ['tectonic uplift', 'EPOS WP16 Analogue Process/Hazard'],
        ['tectonic setting > intraplate tectonic setting', 'epos wp16 analogue main setting'],
        ['volcanic features', 'EPOS WP16 Analogue Geologic Feature'],
    ];
    foreach ($legacySubjects as [$keyword, $scheme]) {
        DB::connection('metaworks')->table('thesauruskeyword')->insert([
            'resource_id' => $legacyResourceId,
            'keyword' => $keyword,
            'thesaurus' => $scheme,
        ]);
        DB::connection('metaworks')->table('thesaurusvalue')->insert([
            'keyword' => $keyword,
            'thesaurus' => $scheme,
            'uri' => null,
            'description' => null,
        ]);
    }

    $person = Person::factory()->create([
        'given_name' => 'Philipp',
        'family_name' => 'Sommer',
        'name_identifier' => 'https://orcid.org/0000-0001-6171-7716',
        'name_identifier_scheme' => 'ORCID',
        'scheme_uri' => 'https://orcid.org/',
    ]);
    $record = [
        'id' => $doi,
        'attributes' => [
            'doi' => $doi,
            'publicationYear' => 2024,
            'titles' => [['title' => 'Legacy MSL regression dataset']],
            'creators' => [[
                'name' => 'Sommer, Philipp',
                'givenName' => 'Philipp',
                'familyName' => 'Sommer',
                'nameType' => 'Personal',
                'nameIdentifiers' => [[
                    'nameIdentifier' => '0000-0001-6171-7716',
                    'nameIdentifierScheme' => 'ORCID',
                ]],
            ]],
            'subjects' => [],
        ],
    ];

    $metadata = (new LegacyResourceLookupService(new LegacyKeywordService))->importMetadataByDoi($doi);
    $record = (new DataCiteSubjectMergeService)->mergeIntoDoiRecord($record, $metadata['subjects']);
    $record = (new DataCiteCreatorNameMergeService)->mergeIntoDoiRecord($record, $metadata['creators']);
    $resource = (new DataCiteToResourceTransformer)->transform($record, User::factory()->create()->id);

    $creator = $resource->creators()->firstOrFail();
    expect($creator->creatorable_id)->toBe($person->id)
        ->and($creator->name_snapshot)->toBe('Sommer, Philipp S.')
        ->and($creator->given_name_snapshot)->toBe('Philipp S.')
        ->and($person->fresh()->given_name)->toBe('Philipp')
        ->and($resource->subjects()->count())->toBe(6)
        ->and($resource->subjects()->pluck('subject_scheme')->unique()->values()->all())
        ->toHaveCount(4)
        ->and($resource->subjects()->whereNotNull('value_uri')->count())->toBe(0)
        ->and($resource->subjects()->whereNotNull('scheme_uri')->count())->toBe(0);

    $resource->load(['creators.creatorable', 'creators.affiliations', 'subjects']);
    $export = (new DataCiteJsonExporter)->export($resource);
    expect($export['data']['attributes']['creators'][0]['givenName'])->toBe('Philipp S.')
        ->and($export['data']['attributes']['subjects'])->toHaveCount(6)
        ->and(array_filter(
            array_column($export['data']['attributes']['subjects'], 'valueUri'),
        ))->toBe([]);
});

it('persists identical MSL paths from distinct WP16 source categories', function (): void {
    $doi = '10.5880/distinct-wp16-import-categories';
    $legacyResourceId = DB::connection('metaworks')->table('resource')->insertGetId([
        'identifier' => $doi,
        'keywords' => null,
    ]);
    $legacySubjects = [
        [
            'scheme' => 'EPOS WP16 Analogue Material',
            'uri' => 'http://epos/WP16Vocabulary/AnalogueMaterial/Granite',
        ],
        [
            'scheme' => 'EPOS WP16 Rock Physics Material',
            'uri' => 'http://epos/WP16Vocabulary/RockPhysicsMaterial/Granite',
        ],
    ];
    foreach ($legacySubjects as $legacySubject) {
        DB::connection('metaworks')->table('thesauruskeyword')->insert([
            'resource_id' => $legacyResourceId,
            'keyword' => 'Granite',
            'thesaurus' => $legacySubject['scheme'],
        ]);
        DB::connection('metaworks')->table('thesaurusvalue')->insert([
            'keyword' => 'Granite',
            'thesaurus' => $legacySubject['scheme'],
            'uri' => $legacySubject['uri'],
            'description' => null,
        ]);
    }
    $record = [
        'id' => $doi,
        'attributes' => [
            'doi' => $doi,
            'publicationYear' => 2026,
            'titles' => [['title' => 'Distinct WP16 category identities']],
            'creators' => [[
                'name' => 'Importer, Test',
                'givenName' => 'Test',
                'familyName' => 'Importer',
                'nameType' => 'Personal',
            ]],
            'subjects' => [],
        ],
    ];

    $legacyMetadata = (new LegacyResourceLookupService(new LegacyKeywordService))
        ->importMetadataByDoi($doi);
    $mergedRecord = (new DataCiteSubjectMergeService)->mergeIntoDoiRecord(
        $record,
        $legacyMetadata['subjects'],
    );
    $resource = (new DataCiteToResourceTransformer)->transform(
        $mergedRecord,
        User::factory()->create()->id,
    );
    $subjects = $resource->subjects()->orderBy('subject_scheme')->get();
    $exportedSubjects = (new DataCiteJsonExporter)->export($resource->fresh())['data']['attributes']['subjects'];

    expect($subjects)->toHaveCount(2)
        ->and($subjects->pluck('subject_scheme')->all())->toBe(array_column($legacySubjects, 'scheme'))
        ->and($subjects->pluck('value_uri')->all())->toBe(array_column($legacySubjects, 'uri'))
        ->and(array_column($exportedSubjects, 'subjectScheme'))->toBe(array_column($legacySubjects, 'scheme'))
        ->and(array_column($exportedSubjects, 'valueUri'))->toBe(array_column($legacySubjects, 'uri'));
});

it('persists the Issue 1091 keyword pattern and exposes it in the editor shape', function (): void {
    $doi = '10.5880/GFZ.LKUT.2026.004';
    $thesaurus = 'NASA/GCMD Earth Science Keywords';
    $legacyKeywords = [
        [
            'path' => 'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY',
            'uuid' => '11111111-1111-4111-8111-111111111111',
        ],
        [
            'path' => 'EARTH SCIENCE > SOLID EARTH > TECTONICS',
            'uuid' => '22222222-2222-4222-8222-222222222222',
        ],
        [
            'path' => 'EARTH SCIENCE > SOLID EARTH > CRUSTAL DYNAMICS',
            'uuid' => '33333333-3333-4333-8333-333333333333',
        ],
    ];
    $legacyResourceId = DB::connection('metaworks')->table('resource')->insertGetId([
        'identifier' => $doi,
        'keywords' => 'GNSS, Crustal deformation',
    ]);

    foreach ($legacyKeywords as $keyword) {
        DB::connection('metaworks')->table('thesauruskeyword')->insert([
            'resource_id' => $legacyResourceId,
            'keyword' => $keyword['path'],
            'thesaurus' => $thesaurus,
        ]);
        DB::connection('metaworks')->table('thesaurusvalue')->insert([
            'keyword' => $keyword['path'],
            'thesaurus' => $thesaurus,
            'uri' => "http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/{$keyword['uuid']}",
            'description' => null,
        ]);
    }

    $doiRecord = [
        'id' => mb_strtolower($doi),
        'attributes' => [
            'doi' => mb_strtolower($doi),
            'publicationYear' => 2026,
            'titles' => [['title' => 'Issue 1091 regression dataset']],
            'creators' => [[
                'familyName' => 'Importer',
                'givenName' => 'Test',
                'nameType' => 'Personal',
            ]],
            'subjects' => [[
                // DataCite wins, while the matching legacy subject must not be appended again.
                'subject' => $legacyKeywords[0]['path'],
                'subjectScheme' => 'Science Keywords',
                'schemeUri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
                'valueUri' => "https://gcmd.earthdata.nasa.gov/kms/concept/{$legacyKeywords[0]['uuid']}",
                'lang' => 'en',
            ]],
        ],
    ];

    $lookup = new LegacyResourceLookupService(new LegacyKeywordService);
    $legacyMetadata = $lookup->importMetadataByDoi(mb_strtolower($doi));
    $mergedRecord = (new DataCiteSubjectMergeService)->mergeIntoDoiRecord(
        $doiRecord,
        $legacyMetadata['subjects'],
    );
    $resource = (new DataCiteToResourceTransformer)->transform(
        $mergedRecord,
        User::factory()->create()->id,
    );

    $subjects = $resource->subjects()->orderBy('id')->get();
    expect($subjects)->toHaveCount(5)
        ->and($subjects->whereNotNull('subject_scheme'))->toHaveCount(3)
        ->and($subjects->whereNull('subject_scheme'))->toHaveCount(2)
        ->and($subjects->whereNotNull('subject_scheme')->pluck('value')->all())->toBe([
            'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY',
            'EARTH SCIENCE > SOLID EARTH > CRUSTAL DYNAMICS',
            'EARTH SCIENCE > SOLID EARTH > TECTONICS',
        ])
        ->and($subjects->whereNotNull('subject_scheme')->pluck('subject_scheme')->unique()->values()->all())->toBe([
            'Science Keywords',
        ])
        ->and($subjects->whereNotNull('subject_scheme')->pluck('scheme_uri')->unique()->values()->all())->toBe([
            'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
        ])
        ->and($subjects->whereNull('subject_scheme')->pluck('value')->all())->toBe([
            'GNSS',
            'Crustal deformation',
        ]);

    $resource->load('subjects');
    $editorTransformer = new EditorDataTransformer;
    $editorControlledKeywords = $editorTransformer->transformGcmdKeywords($resource);

    expect($editorControlledKeywords)->toHaveCount(3)
        ->and(array_column($editorControlledKeywords, 'text'))->toBe([
            'SEISMOLOGY',
            'CRUSTAL DYNAMICS',
            'TECTONICS',
        ])
        ->and(array_column($editorControlledKeywords, 'path'))->toBe([
            'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY',
            'EARTH SCIENCE > SOLID EARTH > CRUSTAL DYNAMICS',
            'EARTH SCIENCE > SOLID EARTH > TECTONICS',
        ])
        ->and($editorTransformer->transformFreeKeywords($resource))->toBe([
            'GNSS',
            'Crustal deformation',
        ]);
});

it('enriches and persists all Issue 1115 GEMET subjects with editor-selectable IDs', function (): void {
    Storage::fake('local');

    $doi = '10.5880/igets.bu.l1.001';
    $legacyThesaurus = 'GEMET - INSPIRE themes, version 1.0';
    $canonicalScheme = 'GEMET - GEneral Multilingual Environmental Thesaurus';
    $concepts = [
        'geodesy' => '3638',
        'geophysics' => '3655',
        'hydrology' => '4118',
    ];
    Storage::disk('local')->put('gemet-thesaurus.json', json_encode([
        'data' => [[
            'id' => 'http://www.eionet.europa.eu/gemet/supergroup/1',
            'text' => 'Earth sciences',
            'scheme' => $canonicalScheme,
            'schemeURI' => 'http://www.eionet.europa.eu/gemet/concept/',
            'children' => array_map(
                static fn (string $label, string $conceptId): array => [
                    'id' => "http://www.eionet.europa.eu/gemet/concept/{$conceptId}",
                    'text' => $label,
                    'scheme' => 'GEMET - GEneral Multilingual Environmental Thesaurus',
                    'schemeURI' => 'http://www.eionet.europa.eu/gemet/concept/',
                    'children' => [],
                ],
                array_keys($concepts),
                array_values($concepts),
            ),
        ]],
    ], JSON_THROW_ON_ERROR));

    $legacyResourceId = DB::connection('metaworks')->table('resource')->insertGetId([
        'identifier' => $doi,
        'keywords' => null,
    ]);
    foreach ($concepts as $keyword => $conceptId) {
        DB::connection('metaworks')->table('thesauruskeyword')->insert([
            'resource_id' => $legacyResourceId,
            'keyword' => $keyword,
            'thesaurus' => $legacyThesaurus,
        ]);
        DB::connection('metaworks')->table('thesaurusvalue')->insert([
            'keyword' => $keyword,
            'thesaurus' => $legacyThesaurus,
            'uri' => "http://www.eionet.europa.eu/gemet/concept/{$conceptId}",
            'description' => null,
        ]);
    }

    $xmlSubjects = implode('', array_map(
        static fn (string $keyword): string => '<subject subjectScheme="GEMET - INSPIRE themes, version 1.0">'.$keyword.'</subject>',
        array_keys($concepts),
    ));
    $doiRecord = [
        'id' => $doi,
        'attributes' => [
            'doi' => $doi,
            'publicationYear' => 2025,
            'titles' => [['title' => 'Issue 1115 regression dataset']],
            'creators' => [[
                'familyName' => 'Importer',
                'givenName' => 'Test',
                'nameType' => 'Personal',
            ]],
            'xml' => base64_encode(
                '<resource xmlns="http://datacite.org/schema/kernel-4"><subjects>'.$xmlSubjects.'</subjects></resource>',
            ),
            'subjects' => array_map(
                static fn (string $keyword): array => [
                    'subject' => $keyword,
                    'subjectScheme' => 'GEMET - INSPIRE themes, version 1.0',
                ],
                array_keys($concepts),
            ),
        ],
    ];

    $sourceRecord = app(OriginalDataCiteSubjectExtractionService::class)
        ->preferOriginalSubjects($doiRecord, $doi);
    $legacyMetadata = (new LegacyResourceLookupService(new LegacyKeywordService))
        ->importMetadataByDoi($doi);
    $mergedRecord = (new DataCiteSubjectMergeService)->mergeIntoDoiRecord(
        $sourceRecord,
        $legacyMetadata['subjects'],
    );
    $resource = (new DataCiteToResourceTransformer)->transform(
        $mergedRecord,
        User::factory()->create()->id,
    );

    $subjects = $resource->subjects()->orderBy('value')->get();
    expect($subjects)->toHaveCount(3)
        ->and($subjects->pluck('subject_scheme')->unique()->values()->all())->toBe([$canonicalScheme])
        ->and($subjects->pluck('value_uri')->all())->toBe([
            'http://www.eionet.europa.eu/gemet/concept/3638',
            'http://www.eionet.europa.eu/gemet/concept/3655',
            'http://www.eionet.europa.eu/gemet/concept/4118',
        ])
        ->and($subjects->pluck('breadcrumb_path')->all())->toBe([
            'Earth sciences > geodesy',
            'Earth sciences > geophysics',
            'Earth sciences > hydrology',
        ]);

    $resource->load('subjects');
    $editorKeywords = (new EditorDataTransformer)->transformGemetKeywords($resource);

    expect(array_column($editorKeywords, 'id'))->toBe([
        'http://www.eionet.europa.eu/gemet/concept/3638',
        'http://www.eionet.europa.eu/gemet/concept/3655',
        'http://www.eionet.europa.eu/gemet/concept/4118',
    ])->and(array_column($editorKeywords, 'text'))->toBe([
        'geodesy',
        'geophysics',
        'hydrology',
    ]);
});

it('persists only the nine Issue 1123 original XML subjects', function (): void {
    $doi = '10.5880/TRR228DB.398';
    $originalSubjects = array_map(
        static fn (int $number): string => "Original keyword {$number}",
        range(1, 9),
    );
    $xmlSubjects = implode('', array_map(
        static fn (string $keyword): string => '<subject>'.$keyword.'</subject>',
        $originalSubjects,
    ));
    $doiRecord = [
        'id' => $doi,
        'attributes' => [
            'doi' => $doi,
            'publicationYear' => 2024,
            'titles' => [['title' => 'Issue 1123 regression dataset']],
            'creators' => [[
                'familyName' => 'Importer',
                'givenName' => 'Test',
                'nameType' => 'Personal',
            ]],
            'xml' => base64_encode(
                '<resource xmlns="http://datacite.org/schema/kernel-4"><subjects>'.$xmlSubjects.'</subjects></resource>',
            ),
            'subjects' => [
                ...array_map(static fn (string $keyword): array => ['subject' => $keyword], $originalSubjects),
                ['subject' => 'FOS: Biological sciences'],
            ],
        ],
    ];

    $sourceRecord = app(OriginalDataCiteSubjectExtractionService::class)
        ->preferOriginalSubjects($doiRecord, $doi);
    $resource = (new DataCiteToResourceTransformer)->transform(
        $sourceRecord,
        User::factory()->create()->id,
    );

    expect($resource->subjects()->count())->toBe(9)
        ->and($resource->subjects()->orderBy('id')->pluck('value')->all())->toBe($originalSubjects)
        ->and($resource->subjects()->where('value', 'FOS: Biological sciences')->exists())->toBeFalse();
});

it('preserves arbitrary DataCite subject scheme names during import and re-export', function (): void {
    $schemes = [
        'Research Instrument Taxonomy',
        'Offshore Platform Classification',
        'Local GEMET-derived Vocabulary',
    ];
    $doiRecord = [
        'id' => '10.5880/custom-subject-schemes',
        'attributes' => [
            'doi' => '10.5880/custom-subject-schemes',
            'publicationYear' => 2026,
            'titles' => [['title' => 'Custom subject schemes']],
            'creators' => [[
                'familyName' => 'Importer',
                'givenName' => 'Test',
                'nameType' => 'Personal',
            ]],
            'subjects' => array_map(
                static fn (string $scheme, int $index): array => [
                    'subject' => "Custom subject {$index}",
                    'subjectScheme' => $scheme,
                    'schemeUri' => "https://example.test/schemes/{$index}",
                ],
                $schemes,
                array_keys($schemes),
            ),
        ],
    ];

    $resource = (new DataCiteToResourceTransformer)->transform(
        $doiRecord,
        User::factory()->create()->id,
    );
    $exportedSubjects = (new DataCiteJsonExporter)->export($resource->fresh())['data']['attributes']['subjects'];

    expect($resource->subjects()->orderBy('id')->pluck('subject_scheme')->all())->toBe($schemes)
        ->and(array_column($exportedSubjects, 'subjectScheme'))->toBe($schemes);
});

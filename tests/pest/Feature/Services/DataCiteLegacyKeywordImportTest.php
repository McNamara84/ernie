<?php

declare(strict_types=1);

use App\Models\User;
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
});

afterEach(function (): void {
    Schema::connection('metaworks')->dropIfExists('thesaurusvalue');
    Schema::connection('metaworks')->dropIfExists('thesauruskeyword');
    Schema::connection('metaworks')->dropIfExists('relatedidentifier');
    Schema::connection('metaworks')->dropIfExists('resource');
    DB::disconnect('metaworks');
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

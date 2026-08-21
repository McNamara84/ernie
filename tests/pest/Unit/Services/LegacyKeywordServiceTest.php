<?php

declare(strict_types=1);

use App\Models\OldDataset;
use App\Services\LegacyKeywordService;
use App\Services\SubjectBreadcrumbPathResolverService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

covers(LegacyKeywordService::class);

beforeEach(function (): void {
    Config::set('database.connections.metaworks', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    DB::purge('metaworks');

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

    $this->service = new LegacyKeywordService;
    $this->dataset = new OldDataset;
    $this->dataset->forceFill([
        'id' => 9663,
        'identifier' => '10.5880/GFZ.LKUT.2026.004',
        'keywords' => null,
    ]);
    $this->dataset->exists = true;
});

afterEach(function (): void {
    Schema::connection('metaworks')->dropIfExists('thesaurusvalue');
    Schema::connection('metaworks')->dropIfExists('thesauruskeyword');
    DB::disconnect('metaworks');
});

it('loads and transforms controlled keywords in deterministic order', function (): void {
    $thesaurus = 'NASA/GCMD Earth Science Keywords';
    DB::connection('metaworks')->table('thesauruskeyword')->insert([
        ['resource_id' => 9663, 'keyword' => 'EARTH SCIENCE > SOLID EARTH > TECTONICS', 'thesaurus' => $thesaurus],
        ['resource_id' => 9663, 'keyword' => 'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY', 'thesaurus' => $thesaurus],
        ['resource_id' => 9999, 'keyword' => 'SHOULD NOT MATCH', 'thesaurus' => $thesaurus],
        ['resource_id' => 9663, 'keyword' => 'UNSUPPORTED', 'thesaurus' => 'Unrelated thesaurus'],
    ]);
    DB::connection('metaworks')->table('thesaurusvalue')->insert([
        [
            'keyword' => 'EARTH SCIENCE > SOLID EARTH > TECTONICS',
            'thesaurus' => $thesaurus,
            'uri' => 'http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/11111111-1111-4111-8111-111111111111',
            'description' => 'Tectonics description',
        ],
        [
            'keyword' => 'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY',
            'thesaurus' => $thesaurus,
            'uri' => 'http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/22222222-2222-4222-8222-222222222222',
            'description' => 'Seismology description',
        ],
        [
            'keyword' => 'SHOULD NOT MATCH',
            'thesaurus' => $thesaurus,
            'uri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/33333333-3333-4333-8333-333333333333',
            'description' => null,
        ],
        [
            'keyword' => 'UNSUPPORTED',
            'thesaurus' => 'Unrelated thesaurus',
            'uri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/44444444-4444-4444-8444-444444444444',
            'description' => null,
        ],
    ]);

    $keywords = $this->service->controlledKeywords($this->dataset);

    expect($keywords)->toHaveCount(2)
        ->and(array_column($keywords, 'path'))->toBe([
            'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY',
            'EARTH SCIENCE > SOLID EARTH > TECTONICS',
        ])
        ->and($keywords[0])->toMatchArray([
            'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/22222222-2222-4222-8222-222222222222',
            'scheme' => 'Science Keywords',
            'schemeURI' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
            'uuid' => '22222222-2222-4222-8222-222222222222',
            'description' => 'Seismology description',
        ]);
});

it('loads all three Issue 1115 GEMET keywords from the legacy database', function (): void {
    $thesaurus = 'GEMET - INSPIRE themes, version 1.0';
    $concepts = [
        'geodesy' => '3638',
        'geophysics' => '3655',
        'hydrology' => '4118',
    ];

    foreach ($concepts as $keyword => $conceptId) {
        DB::connection('metaworks')->table('thesauruskeyword')->insert([
            'resource_id' => 9663,
            'keyword' => $keyword,
            'thesaurus' => $thesaurus,
        ]);
        DB::connection('metaworks')->table('thesaurusvalue')->insert([
            'keyword' => $keyword,
            'thesaurus' => $thesaurus,
            'uri' => "http://www.eionet.europa.eu/gemet/concept/{$conceptId}",
            'description' => null,
        ]);
    }

    $subjects = $this->service->dataCiteSubjects($this->dataset);

    expect($subjects)->toHaveCount(3)
        ->and(array_column($subjects, 'subject'))->toBe(['geodesy', 'geophysics', 'hydrology'])
        ->and(array_column($subjects, 'valueUri'))->toBe([
            'http://www.eionet.europa.eu/gemet/concept/3638',
            'http://www.eionet.europa.eu/gemet/concept/3655',
            'http://www.eionet.europa.eu/gemet/concept/4118',
        ])
        ->and(array_unique(array_column($subjects, 'subjectScheme')))->toBe([
            'GEMET - GEneral Multilingual Environmental Thesaurus',
        ]);
});

it('splits, trims, and filters comma-separated free keywords', function (): void {
    $this->dataset->keywords = '  GNSS, , Crustal deformation ,,Seismology  ';

    expect($this->service->freeKeywords($this->dataset))->toBe([
        'GNSS',
        'Crustal deformation',
        'Seismology',
    ]);
});

it('returns no free keywords for null, blank, or non-string values', function (mixed $keywords): void {
    $this->dataset->setRawAttributes([
        'id' => 9663,
        'identifier' => '10.5880/GFZ.LKUT.2026.004',
        'keywords' => $keywords,
    ]);

    expect($this->service->freeKeywords($this->dataset))->toBe([]);
})->with([null, '', '   ', 123]);

it('converts controlled and free keywords to canonical DataCite subjects', function (): void {
    $thesaurus = 'NASA/GCMD Earth Science Keywords';
    $keyword = 'EARTH SCIENCE > SOLID EARTH > TECTONICS';
    DB::connection('metaworks')->table('thesauruskeyword')->insert([
        'resource_id' => 9663,
        'keyword' => $keyword,
        'thesaurus' => $thesaurus,
    ]);
    DB::connection('metaworks')->table('thesaurusvalue')->insert([
        'keyword' => $keyword,
        'thesaurus' => $thesaurus,
        'uri' => 'http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/11111111-1111-4111-8111-111111111111',
        'description' => null,
    ]);
    $this->dataset->keywords = 'GNSS, Crustal deformation';

    expect($this->service->dataCiteSubjects($this->dataset))->toBe([
        [
            'subject' => $keyword,
            'subjectScheme' => 'Science Keywords',
            'valueUri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/11111111-1111-4111-8111-111111111111',
            'lang' => 'en',
            'schemeUri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
        ],
        ['subject' => 'GNSS'],
        ['subject' => 'Crustal deformation'],
    ]);
});

it('skips invalid controlled keywords and records enough context for diagnosis', function (): void {
    Log::spy();
    $thesaurus = 'NASA/GCMD Earth Science Keywords';
    DB::connection('metaworks')->table('thesauruskeyword')->insert([
        'resource_id' => 9663,
        'keyword' => '',
        'thesaurus' => $thesaurus,
    ]);
    DB::connection('metaworks')->table('thesaurusvalue')->insert([
        'keyword' => '',
        'thesaurus' => $thesaurus,
        'uri' => null,
        'description' => null,
    ]);

    expect($this->service->controlledKeywords($this->dataset))->toBe([]);
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Skipping invalid legacy controlled keyword'
            && $context['doi'] === '10.5880/GFZ.LKUT.2026.004'
            && $context['legacy_resource_id'] === 9663
            && $context['thesaurus'] === $thesaurus);
});

it('continues after an individual controlled keyword transformation throws', function (): void {
    Log::spy();
    $thesaurus = 'NASA/GCMD Earth Science Keywords';
    DB::connection('metaworks')->table('thesauruskeyword')->insert([
        'resource_id' => 9663,
        'keyword' => 'BROKEN KEYWORD',
        'thesaurus' => $thesaurus,
    ]);
    DB::connection('metaworks')->table('thesaurusvalue')->insert([
        'keyword' => 'BROKEN KEYWORD',
        'thesaurus' => $thesaurus,
        'uri' => null,
        'description' => null,
    ]);
    $this->app->bind(
        SubjectBreadcrumbPathResolverService::class,
        static fn (): never => throw new RuntimeException('Vocabulary storage unavailable'),
    );

    expect($this->service->controlledKeywords($this->dataset))->toBe([]);
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Skipping legacy controlled keyword after transformation failure'
            && $context['doi'] === '10.5880/GFZ.LKUT.2026.004'
            && $context['legacy_resource_id'] === 9663
            && $context['keyword'] === 'BROKEN KEYWORD'
            && $context['error'] === 'Vocabulary storage unavailable');
});

it('returns an empty subject list when no legacy keywords exist', function (): void {
    expect($this->service->dataCiteSubjects($this->dataset))->toBe([]);
});

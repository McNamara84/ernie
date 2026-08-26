<?php

declare(strict_types=1);

use App\Services\Citations\LegacyCitationCacheService;
use App\Services\Citations\RelatedIdentifierCitationLabelService;
use App\Services\DataCiteApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

covers(RelatedIdentifierCitationLabelService::class);

function relatedIdentifierCitationLabelService(
    DataCiteApiService $dataCite,
    ?LegacyCitationCacheService $legacy = null,
): RelatedIdentifierCitationLabelService {
    if ($legacy === null) {
        $legacy = Mockery::mock(LegacyCitationCacheService::class);
        $legacy->shouldReceive('find')->zeroOrMoreTimes()->andReturnNull();
        $legacy->shouldReceive('findMany')->zeroOrMoreTimes()->andReturn([]);
        $legacy->shouldReceive('findUrl')->zeroOrMoreTimes()->andReturnNull();
        $legacy->shouldReceive('findManyUrls')->zeroOrMoreTimes()->andReturn([]);
    }

    return new RelatedIdentifierCitationLabelService($dataCite, $legacy);
}

it('resolves a citation label for DOI identifiers', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldReceive('normalizeDoi')->once()->with('10.1234/example')->andReturn('10.1234/example');
    $dataCite->shouldReceive('getMetadata')->once()->with('10.1234/example')->andReturn(['title' => 'Example']);
    $dataCite->shouldReceive('buildCitationFromMetadata')->once()->with(['title' => 'Example'])->andReturn('Doe, J. (2026): Example. Publisher.');

    $service = relatedIdentifierCitationLabelService($dataCite);

    expect($service->resolve('10.1234/example', 'DOI'))->toBe('Doe, J. (2026): Example. Publisher.');
});

it('uses a legacy citation before requesting DOI metadata', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldReceive('normalizeDoi')->once()->with('10.1007/example')->andReturn('10.1007/example');
    $dataCite->shouldNotReceive('getMetadata');
    $dataCite->shouldNotReceive('buildCitationFromMetadata');

    $legacy = Mockery::mock(LegacyCitationCacheService::class);
    $legacy->shouldReceive('find')->once()->with('10.1007/example')->andReturn('Legacy citation');

    $service = relatedIdentifierCitationLabelService($dataCite, $legacy);

    expect($service->resolve('10.1007/example', 'DOI'))->toBe('Legacy citation');
});

it('falls back to DOI metadata after a legacy cache miss', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldReceive('normalizeDoi')->once()->with('10.1007/example')->andReturn('10.1007/example');
    $dataCite->shouldReceive('getMetadata')->once()->with('10.1007/example')->andReturn(['title' => 'Example']);
    $dataCite->shouldReceive('buildCitationFromMetadata')->once()->andReturn('Generated citation');

    $legacy = Mockery::mock(LegacyCitationCacheService::class);
    $legacy->shouldReceive('find')->once()->with('10.1007/example')->andReturnNull();

    $service = relatedIdentifierCitationLabelService($dataCite, $legacy);

    expect($service->resolve('10.1007/example', 'DOI'))->toBe('Generated citation');
});

it('resolves a citation label for DOI resolver URLs stored as URL', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldReceive('normalizeDoi')->once()->with('https://doi.org/10.1234/example')->andReturn('10.1234/example');
    $dataCite->shouldReceive('getMetadata')->once()->with('10.1234/example')->andReturn(['title' => 'Example']);
    $dataCite->shouldReceive('buildCitationFromMetadata')->once()->with(['title' => 'Example'])->andReturn('Doe, J. (2026): Example. Publisher.');

    $service = relatedIdentifierCitationLabelService($dataCite);

    expect($service->resolve('https://doi.org/10.1234/example', 'URL'))->toBe('Doe, J. (2026): Example. Publisher.');
});

it('uses a literal URL citation before attempting DOI normalization', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldNotReceive('normalizeDoi');
    $dataCite->shouldNotReceive('getMetadata');
    $dataCite->shouldNotReceive('buildCitationFromMetadata');

    $legacy = Mockery::mock(LegacyCitationCacheService::class);
    $legacy->shouldReceive('findUrl')
        ->once()
        ->with('https://www.researchgate.net/publication/337654804')
        ->andReturn('  Miranda et al. (2018): Petrologia e geoquímica.  ');
    $legacy->shouldNotReceive('find');

    $service = relatedIdentifierCitationLabelService($dataCite, $legacy);

    expect($service->resolve(' https://www.researchgate.net/publication/337654804 ', 'URL'))
        ->toBe('Miranda et al. (2018): Petrologia e geoquímica.');
});

it('returns null for non DOI-like URL identifiers', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldReceive('normalizeDoi')->once()->with('https://example.org/page')->andReturn('https://example.org/page');
    $dataCite->shouldNotReceive('getMetadata');
    $dataCite->shouldNotReceive('buildCitationFromMetadata');

    $service = relatedIdentifierCitationLabelService($dataCite);

    expect($service->resolve('https://example.org/page', 'URL'))->toBeNull();
});

it('uses a literal URL cache hit even after the best-effort HTTP budget expires', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldNotReceive('normalizeDoi');
    $dataCite->shouldNotReceive('getMetadata');

    $legacy = Mockery::mock(LegacyCitationCacheService::class);
    $legacy->shouldReceive('findUrl')
        ->once()
        ->with('https://example.org/curated')
        ->andReturn('Curated URL citation');

    $service = relatedIdentifierCitationLabelService($dataCite, $legacy);

    expect($service->resolveBestEffort('https://example.org/curated', 'URL', microtime(true) - 1))
        ->toBe('Curated URL citation');
});

it('returns null for unsupported identifier types', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldNotReceive('normalizeDoi');
    $dataCite->shouldNotReceive('getMetadata');
    $dataCite->shouldNotReceive('buildCitationFromMetadata');

    $service = relatedIdentifierCitationLabelService($dataCite);

    expect($service->resolve('1234/5678', 'Handle'))->toBeNull();
});

it('rejects DOI values containing whitespace', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldReceive('normalizeDoi')
        ->once()
        ->with('10.1234/not valid')
        ->andReturn('10.1234/not valid');
    $dataCite->shouldNotReceive('getMetadata');

    expect(relatedIdentifierCitationLabelService($dataCite)->resolve('10.1234/not valid', 'DOI'))
        ->toBeNull();
});

it('returns null when metadata lookup fails', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldReceive('normalizeDoi')->once()->with('10.1234/missing')->andReturn('10.1234/missing');
    $dataCite->shouldReceive('getMetadata')->once()->with('10.1234/missing')->andReturnNull();
    $dataCite->shouldNotReceive('buildCitationFromMetadata');

    $service = relatedIdentifierCitationLabelService($dataCite);

    expect($service->resolve('10.1234/missing', 'DOI'))->toBeNull();
});

it('uses the provided timeout for best-effort resolution without caching transient failures', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldReceive('normalizeDoi')->once()->with('10.1234/example')->andReturn('10.1234/example');
    $dataCite->shouldReceive('getMetadata')->once()->with('10.1234/example', 0.5, false)->andReturn(['title' => 'Example']);
    $dataCite->shouldReceive('buildCitationFromMetadata')->once()->with(['title' => 'Example'])->andReturn('Doe, J. (2026): Example. Publisher.');

    $service = relatedIdentifierCitationLabelService($dataCite);

    expect($service->resolve('10.1234/example', 'DOI', 0.5))->toBe('Doe, J. (2026): Example. Publisher.');
});

it('skips best-effort resolution when the aggregate budget is exhausted', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldNotReceive('normalizeDoi');
    $dataCite->shouldNotReceive('getMetadata');
    $dataCite->shouldNotReceive('buildCitationFromMetadata');

    $service = relatedIdentifierCitationLabelService($dataCite);

    expect($service->resolveBestEffort('10.1234/example', 'DOI', microtime(true) - 1))->toBeNull();
});

it('resolves within the remaining best-effort budget', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldReceive('normalizeDoi')->once()->with('10.1234/budget')->andReturn('10.1234/budget');
    $dataCite->shouldReceive('getMetadata')
        ->once()
        ->with(
            '10.1234/budget',
            Mockery::on(fn (float $timeout): bool => $timeout > 0.1 && $timeout <= 0.75),
            false,
        )
        ->andReturn(['title' => 'Budget']);
    $dataCite->shouldReceive('buildCitationFromMetadata')->once()->andReturn('Budget citation');

    $service = relatedIdentifierCitationLabelService($dataCite);

    expect($service->resolveBestEffort('10.1234/budget', 'DOI', microtime(true) + 1))->toBe('Budget citation');
});

it('skips best-effort resolution when too little aggregate budget remains', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldNotReceive('normalizeDoi');
    $dataCite->shouldNotReceive('getMetadata');

    $service = relatedIdentifierCitationLabelService($dataCite);

    expect($service->resolveBestEffort('10.1234/example', 'DOI', microtime(true) + 0.05))->toBeNull();
});

it('batch-resolves legacy citations before spending the shared best-effort HTTP budget', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class)->makePartial();
    $dataCite->shouldReceive('getMetadata')
        ->once()
        ->with(
            '10.1234/metadata',
            Mockery::on(fn (float $timeout): bool => $timeout >= 0.1 && $timeout <= 0.75),
            false,
        )
        ->andReturn(['title' => 'Metadata']);
    $dataCite->shouldReceive('buildCitationFromMetadata')->once()->andReturn('Metadata citation');

    $legacy = Mockery::mock(LegacyCitationCacheService::class);
    $legacy->shouldReceive('findMany')
        ->once()
        ->with(['10.1234/legacy', '10.1234/metadata'])
        ->andReturn(['10.1234/legacy' => 'Legacy citation']);

    $resolved = relatedIdentifierCitationLabelService($dataCite, $legacy)->resolveBestEffortBatch([
        [
            'relatedIdentifier' => ' 10.1234/LEGACY ',
            'relatedIdentifierType' => 'DOI',
        ],
        [
            'relatedIdentifier' => '10.1234/metadata',
            'relatedIdentifierType' => 'DOI',
        ],
        [
            'relatedIdentifier' => '10.1234/manual',
            'relatedIdentifierType' => 'DOI',
            'citationLabel' => ' Manual citation ',
        ],
    ], microtime(true) + 1);

    expect($resolved[0])->toMatchArray([
        'relatedIdentifier' => '10.1234/LEGACY',
        'citationLabel' => 'Legacy citation',
    ])->and($resolved[1]['citationLabel'])->toBe('Metadata citation')
        ->and($resolved[2]['citationLabel'])->toBe('Manual citation');
});

it('batch-resolves literal URLs before DOI cache and metadata fallbacks', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class)->makePartial();
    $dataCite->shouldReceive('getMetadata')
        ->once()
        ->with(
            '10.1234/metadata',
            Mockery::on(fn (float $timeout): bool => $timeout >= 0.1 && $timeout <= 0.75),
            false,
        )
        ->andReturn(['title' => 'Metadata']);
    $dataCite->shouldReceive('buildCitationFromMetadata')->once()->andReturn('Metadata citation');

    $legacy = Mockery::mock(LegacyCitationCacheService::class);
    $legacy->shouldReceive('findManyUrls')
        ->once()
        ->with([
            'https://www.researchgate.net/publication/337654804',
            'https://example.org/unresolved',
        ])
        ->andReturn([
            'https://www.researchgate.net/publication/337654804' => 'Miranda legacy citation',
        ]);
    $legacy->shouldReceive('findMany')
        ->once()
        ->with(['10.1234/legacy', '10.1234/metadata'])
        ->andReturn(['10.1234/legacy' => 'Legacy DOI citation']);

    $resolved = relatedIdentifierCitationLabelService($dataCite, $legacy)->resolveBestEffortBatch([
        [
            'relatedIdentifier' => ' https://www.researchgate.net/publication/337654804 ',
            'relatedIdentifierType' => 'URL',
        ],
        [
            'relatedIdentifier' => '10.1234/legacy',
            'relatedIdentifierType' => 'DOI',
        ],
        [
            'relatedIdentifier' => '10.1234/metadata',
            'relatedIdentifierType' => 'DOI',
        ],
        [
            'relatedIdentifier' => 'https://example.org/unresolved',
            'relatedIdentifierType' => 'URL',
        ],
        [
            'relatedIdentifier' => 'https://example.org/manual',
            'relatedIdentifierType' => 'URL',
            'citationLabel' => ' Manual URL citation ',
        ],
    ], microtime(true) + 1);

    expect($resolved[0]['citationLabel'])->toBe('Miranda legacy citation')
        ->and($resolved[1]['citationLabel'])->toBe('Legacy DOI citation')
        ->and($resolved[2]['citationLabel'])->toBe('Metadata citation')
        ->and($resolved[3])->not->toHaveKey('citationLabel')
        ->and($resolved[4]['citationLabel'])->toBe('Manual URL citation');
});

it('resolves all 17 citation labels from issue 1086 with the existing ERNIE formatter', function () {
    $dois = [
        '10.1186/s40645-023-00560-4',
        '10.1093/petrology/egac086',
        '10.1093/petrology/egae048',
        '10.1029/2023gc011409',
        '10.3389/feart.2020.554598',
        '10.1029/2021jb022331',
        '10.1093/petrology/egae057',
        '10.1016/j.chemgeo.2009.02.013',
        '10.1016/j.lithos.2018.01.012',
        '10.1016/j.tecto.2021.229001',
        '10.1016/j.epsl.2012.11.012',
        '10.2204/iodp.proc.304305.202.2009',
        '10.1016/j.gca.2014.06.012',
        '10.5880/fidgeo.2026.043',
        '10.1007/s00410-007-0210-z',
        '10.1093/petrology/egaa082',
        '10.1093/petrology/egab034',
    ];
    $outcomes = [];

    foreach ($dois as $index => $doi) {
        $outcomes[$doi] = [
            'status' => 'resolved',
            'metadata' => [
                'author' => [['family' => 'Fixture', 'given' => 'Issue']],
                'issued' => ['date-parts' => [[2026]]],
                'title' => 'Issue #1086 reference '.($index + 1),
                'publisher' => 'Test Publisher',
                'DOI' => $doi,
            ],
        ];
    }

    $dataCite = Mockery::mock(DataCiteApiService::class)->makePartial();
    $dataCite->shouldReceive('getMetadataBatch')
        ->once()
        ->with($dois)
        ->andReturn($outcomes);

    $relatedIdentifiers = array_map(static fn (string $doi): array => [
        'relatedIdentifier' => $doi,
        'relatedIdentifierType' => 'DOI',
        'relationType' => 'Cites',
    ], $dois);

    $resolved = relatedIdentifierCitationLabelService($dataCite)
        ->resolveExhaustive($relatedIdentifiers);

    expect($resolved)->toHaveCount(17)
        ->and(array_filter(
            array_column($resolved, 'citationLabel'),
            static fn (string $label): bool => trim($label) === '',
        ))->toBe([])
        ->and($resolved[0]['citationLabel'])
        ->toBe('Fixture, I. (2026): Issue #1086 reference 1. Test Publisher. https://doi.org/10.1186/s40645-023-00560-4')
        ->and($resolved[16]['citationLabel'])->toContain('https://doi.org/10.1093/petrology/egab034');
});

it('preserves manual labels, deduplicates DOI forms, and ignores unsupported relations', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class)->makePartial();
    $dataCite->shouldReceive('getMetadataBatch')
        ->once()
        ->with(['10.1234/shared'])
        ->andReturn([
            '10.1234/shared' => [
                'status' => 'resolved',
                'metadata' => ['title' => 'Shared'],
            ],
        ]);
    $dataCite->shouldReceive('buildCitationFromMetadata')
        ->once()
        ->with(['title' => 'Shared'])
        ->andReturn('Generated shared citation');

    $resolved = relatedIdentifierCitationLabelService($dataCite)->resolveExhaustive([
        [
            'relatedIdentifier' => ' 10.1234/SHARED ',
            'relatedIdentifierType' => 'DOI',
        ],
        [
            'relatedIdentifier' => 'https://doi.org/10.1234/shared',
            'relatedIdentifierType' => 'URL',
        ],
        [
            'relatedIdentifier' => '10.1234/manual',
            'relatedIdentifierType' => 'DOI',
            'citationLabel' => ' Manual citation ',
        ],
        [
            'relatedIdentifier' => 'https://example.org/page',
            'relatedIdentifierType' => 'URL',
        ],
        [
            'relatedIdentifier' => 'hdl:1234/5678',
            'relatedIdentifierType' => 'Handle',
        ],
    ]);

    expect($resolved[0])->toMatchArray([
        'relatedIdentifier' => '10.1234/SHARED',
        'citationLabel' => 'Generated shared citation',
    ])->and($resolved[1]['citationLabel'])->toBe('Generated shared citation')
        ->and($resolved[2]['citationLabel'])->toBe('Manual citation')
        ->and($resolved[3])->not->toHaveKey('citationLabel')
        ->and($resolved[4])->not->toHaveKey('citationLabel');
});

it('queries DOI metadata only for exhaustive legacy cache misses', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class)->makePartial();
    $dataCite->shouldReceive('getMetadataBatch')
        ->once()
        ->with(['10.1234/metadata'])
        ->andReturn([
            '10.1234/metadata' => [
                'status' => 'resolved',
                'metadata' => ['title' => 'Metadata result'],
            ],
        ]);
    $dataCite->shouldReceive('buildCitationFromMetadata')
        ->once()
        ->with(['title' => 'Metadata result'])
        ->andReturn('Metadata citation');

    $legacy = Mockery::mock(LegacyCitationCacheService::class);
    $legacy->shouldReceive('findMany')
        ->once()
        ->with(['10.1234/legacy', '10.1234/metadata'])
        ->andReturn(['10.1234/legacy' => 'Legacy citation']);

    $resolved = relatedIdentifierCitationLabelService($dataCite, $legacy)->resolveExhaustive([
        [
            'relatedIdentifier' => '10.1234/legacy',
            'relatedIdentifierType' => 'DOI',
        ],
        [
            'relatedIdentifier' => '10.1234/metadata',
            'relatedIdentifierType' => 'DOI',
        ],
    ]);

    expect($resolved[0]['citationLabel'])->toBe('Legacy citation')
        ->and($resolved[1]['citationLabel'])->toBe('Metadata citation');
});

it('resolves a complete storage payload through one exhaustive metadata batch', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class)->makePartial();
    $dataCite->shouldReceive('getMetadataBatch')
        ->once()
        ->with(['10.1234/storage-one', '10.1234/storage-two'])
        ->andReturn([
            '10.1234/storage-one' => [
                'status' => 'resolved',
                'metadata' => ['title' => 'Storage one'],
            ],
            '10.1234/storage-two' => [
                'status' => 'resolved',
                'metadata' => ['title' => 'Storage two'],
            ],
        ]);
    $dataCite->shouldReceive('buildCitationFromMetadata')
        ->once()
        ->with(['title' => 'Storage one'])
        ->andReturn('First storage citation');
    $dataCite->shouldReceive('buildCitationFromMetadata')
        ->once()
        ->with(['title' => 'Storage two'])
        ->andReturn('Second storage citation');

    $resolved = relatedIdentifierCitationLabelService($dataCite)->resolveExhaustiveForStorage([
        [
            'identifier' => ' 10.1234/STORAGE-ONE ',
            'identifierType' => 'DOI',
            'relationType' => 'Cites',
        ],
        [
            'identifier' => 'https://doi.org/10.1234/storage-two',
            'identifierType' => 'URL',
            'relationType' => 'References',
        ],
        [
            'identifier' => '10.1234/manual',
            'identifierType' => 'DOI',
            'relationType' => 'Cites',
            'citationLabel' => ' Manual citation ',
        ],
        [
            'identifier' => 'hdl:1234/5678',
            'identifierType' => 'Handle',
            'relationType' => 'References',
        ],
    ]);

    expect($resolved[0])->toMatchArray([
        'identifier' => '10.1234/STORAGE-ONE',
        'identifierType' => 'DOI',
        'relationType' => 'Cites',
        'citationLabel' => 'First storage citation',
    ])->and($resolved[1]['citationLabel'])->toBe('Second storage citation')
        ->and($resolved[2]['citationLabel'])->toBe('Manual citation')
        ->and($resolved[3])->not->toHaveKey('citationLabel')
        ->and($resolved[0])->not->toHaveKey('relatedIdentifier')
        ->and($resolved[0])->not->toHaveKey('relatedIdentifierType');
});

it('resolves literal URL labels for best-effort storage payloads in one batch', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldNotReceive('normalizeDoi');
    $dataCite->shouldNotReceive('getMetadata');

    $legacy = Mockery::mock(LegacyCitationCacheService::class);
    $legacy->shouldReceive('findManyUrls')
        ->once()
        ->with(['https://www.researchgate.net/publication/337654804'])
        ->andReturn([
            'https://www.researchgate.net/publication/337654804' => 'Miranda legacy citation',
        ]);
    $legacy->shouldNotReceive('findMany');

    $resolved = relatedIdentifierCitationLabelService($dataCite, $legacy)
        ->resolveBestEffortBatchForStorage([
            [
                'identifier' => ' https://www.researchgate.net/publication/337654804 ',
                'identifierType' => 'URL',
                'relationType' => 'Cites',
            ],
            [
                'identifier' => 'https://example.org/manual',
                'identifierType' => 'URL',
                'relationType' => 'References',
                'citationLabel' => ' Manual citation ',
            ],
        ], microtime(true) - 1);

    expect($resolved)->toBe([
        [
            'identifier' => 'https://www.researchgate.net/publication/337654804',
            'identifierType' => 'URL',
            'relationType' => 'Cites',
            'citationLabel' => 'Miranda legacy citation',
        ],
        [
            'identifier' => 'https://example.org/manual',
            'identifierType' => 'URL',
            'relationType' => 'References',
            'citationLabel' => 'Manual citation',
        ],
    ]);
});

it('prefers a literal cache entry for a DOI resolver URL during exhaustive resolution', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldNotReceive('normalizeDoi');
    $dataCite->shouldNotReceive('getMetadataBatch');

    $legacy = Mockery::mock(LegacyCitationCacheService::class);
    $legacy->shouldReceive('findManyUrls')
        ->once()
        ->with(['https://doi.org/10.1234/curated'])
        ->andReturn([
            'https://doi.org/10.1234/curated' => 'Curated resolver-URL citation',
        ]);
    $legacy->shouldNotReceive('findMany');

    $resolved = relatedIdentifierCitationLabelService($dataCite, $legacy)->resolveExhaustive([[
        'relatedIdentifier' => 'https://doi.org/10.1234/curated',
        'relatedIdentifierType' => 'URL',
    ]]);

    expect($resolved[0]['citationLabel'])->toBe('Curated resolver-URL citation');
});

it('bypasses a cached transient failure during exhaustive storage resolution', function () {
    Cache::flush();

    Http::fake([
        'https://doi.org/10.5880/transient-storage' => Http::sequence()
            ->push('Unavailable', 503)
            ->push([
                'author' => [['family' => 'Recovered', 'given' => 'Retry']],
                'issued' => ['date-parts' => [[2026]]],
                'title' => 'Recovered after transient failure',
                'publisher' => 'GFZ',
                'DOI' => '10.5880/transient-storage',
            ]),
        'https://doi.org/10.5880/second-storage' => Http::response([
            'author' => [['family' => 'Batch', 'given' => 'Second']],
            'issued' => ['date-parts' => [[2026]]],
            'title' => 'Resolved in the same batch',
            'publisher' => 'GFZ',
            'DOI' => '10.5880/second-storage',
        ]),
    ]);

    $dataCite = new DataCiteApiService;

    expect($dataCite->getMetadata('10.5880/transient-storage'))->toBeNull();

    $legacy = Mockery::mock(LegacyCitationCacheService::class);
    $legacy->shouldReceive('findMany')
        ->once()
        ->with(['10.5880/transient-storage', '10.5880/second-storage'])
        ->andReturn([]);

    $resolved = relatedIdentifierCitationLabelService($dataCite, $legacy)->resolveExhaustiveForStorage([
        [
            'identifier' => '10.5880/transient-storage',
            'identifierType' => 'DOI',
        ],
        [
            'identifier' => '10.5880/second-storage',
            'identifierType' => 'DOI',
        ],
    ]);

    expect($resolved[0]['citationLabel'])
        ->toBe('Recovered, R. (2026): Recovered after transient failure. GFZ. https://doi.org/10.5880/transient-storage')
        ->and($resolved[1]['citationLabel'])
        ->toBe('Batch, S. (2026): Resolved in the same batch. GFZ. https://doi.org/10.5880/second-storage');

    Http::assertSentCount(3);
});

it('tolerates a malformed explicit DOI without making a batch request', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class)->makePartial();
    $dataCite->shouldNotReceive('getMetadataBatch');

    $service = relatedIdentifierCitationLabelService($dataCite);

    expect($service->resolveExhaustive([[
        'relatedIdentifier' => 'not-a-doi',
        'relatedIdentifierType' => 'DOI',
    ]]))->toBe([[
        'relatedIdentifier' => 'not-a-doi',
        'relatedIdentifierType' => 'DOI',
    ]]);
});

it('tolerates empty identifiers without making a batch request', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldNotReceive('normalizeDoi');
    $dataCite->shouldNotReceive('getMetadataBatch');

    $service = relatedIdentifierCitationLabelService($dataCite);

    expect($service->resolveExhaustive([
        [],
        [
            'relatedIdentifier' => '   ',
            'relatedIdentifierType' => 'DOI',
        ],
    ]))->toBe([
        [],
        [
            'relatedIdentifier' => '   ',
            'relatedIdentifierType' => 'DOI',
        ],
    ]);
});

it('retains valid DOIs without labels after an exhaustive miss', function (mixed $outcome, string $reason) {
    $dataCite = Mockery::mock(DataCiteApiService::class)->makePartial();
    $dataCite->shouldReceive('getMetadataBatch')
        ->once()
        ->with(['10.1234/unresolved'])
        ->andReturn(['10.1234/unresolved' => $outcome]);

    if (($outcome['status'] ?? null) === 'resolved') {
        $dataCite->shouldReceive('buildCitationFromMetadata')->once()->andReturn('   ');
    }

    Log::spy();
    $service = relatedIdentifierCitationLabelService($dataCite);
    $resolved = $service->resolveExhaustive([[
        'relatedIdentifier' => '10.1234/unresolved',
        'relatedIdentifierType' => 'DOI',
    ]]);

    expect($resolved)->toBe([[
        'relatedIdentifier' => '10.1234/unresolved',
        'relatedIdentifierType' => 'DOI',
    ]]);
    Log::shouldHaveReceived('info')
        ->once()
        ->with(
            'Exhaustive citation label resolution completed.',
            Mockery::on(fn (array $context): bool => $context['unresolved'] === [
                '10.1234/unresolved' => $reason,
            ]),
        );
})->with([
    'exhausted metadata lookup' => [
        ['status' => 'failed', 'reason' => 'Connection timeout after three attempts.'],
        'Connection timeout after three attempts.',
    ],
    'successful metadata without citation text' => [
        ['status' => 'resolved', 'metadata' => ['title' => '']],
        'The DOI metadata did not produce a citation label.',
    ],
    'missing batch outcome' => [
        null,
        'The DOI metadata service returned no result.',
    ],
]);

<?php

declare(strict_types=1);

use App\Exceptions\IncompleteCitationLabelResolutionException;
use App\Services\Citations\RelatedIdentifierCitationLabelService;
use App\Services\DataCiteApiService;

covers(RelatedIdentifierCitationLabelService::class, IncompleteCitationLabelResolutionException::class);

it('resolves a citation label for DOI identifiers', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldReceive('normalizeDoi')->once()->with('10.1234/example')->andReturn('10.1234/example');
    $dataCite->shouldReceive('getMetadata')->once()->with('10.1234/example')->andReturn(['title' => 'Example']);
    $dataCite->shouldReceive('buildCitationFromMetadata')->once()->with(['title' => 'Example'])->andReturn('Doe, J. (2026): Example. Publisher.');

    $service = new RelatedIdentifierCitationLabelService($dataCite);

    expect($service->resolve('10.1234/example', 'DOI'))->toBe('Doe, J. (2026): Example. Publisher.');
});

it('resolves a citation label for DOI resolver URLs stored as URL', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldReceive('normalizeDoi')->once()->with('https://doi.org/10.1234/example')->andReturn('10.1234/example');
    $dataCite->shouldReceive('getMetadata')->once()->with('10.1234/example')->andReturn(['title' => 'Example']);
    $dataCite->shouldReceive('buildCitationFromMetadata')->once()->with(['title' => 'Example'])->andReturn('Doe, J. (2026): Example. Publisher.');

    $service = new RelatedIdentifierCitationLabelService($dataCite);

    expect($service->resolve('https://doi.org/10.1234/example', 'URL'))->toBe('Doe, J. (2026): Example. Publisher.');
});

it('returns null for non DOI-like URL identifiers', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldReceive('normalizeDoi')->once()->with('https://example.org/page')->andReturn('https://example.org/page');
    $dataCite->shouldNotReceive('getMetadata');
    $dataCite->shouldNotReceive('buildCitationFromMetadata');

    $service = new RelatedIdentifierCitationLabelService($dataCite);

    expect($service->resolve('https://example.org/page', 'URL'))->toBeNull();
});

it('returns null for unsupported identifier types', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldNotReceive('normalizeDoi');
    $dataCite->shouldNotReceive('getMetadata');
    $dataCite->shouldNotReceive('buildCitationFromMetadata');

    $service = new RelatedIdentifierCitationLabelService($dataCite);

    expect($service->resolve('1234/5678', 'Handle'))->toBeNull();
});

it('returns null when metadata lookup fails', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldReceive('normalizeDoi')->once()->with('10.1234/missing')->andReturn('10.1234/missing');
    $dataCite->shouldReceive('getMetadata')->once()->with('10.1234/missing')->andReturnNull();
    $dataCite->shouldNotReceive('buildCitationFromMetadata');

    $service = new RelatedIdentifierCitationLabelService($dataCite);

    expect($service->resolve('10.1234/missing', 'DOI'))->toBeNull();
});

it('uses the provided timeout for best-effort resolution without caching transient failures', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldReceive('normalizeDoi')->once()->with('10.1234/example')->andReturn('10.1234/example');
    $dataCite->shouldReceive('getMetadata')->once()->with('10.1234/example', 0.5, false)->andReturn(['title' => 'Example']);
    $dataCite->shouldReceive('buildCitationFromMetadata')->once()->with(['title' => 'Example'])->andReturn('Doe, J. (2026): Example. Publisher.');

    $service = new RelatedIdentifierCitationLabelService($dataCite);

    expect($service->resolve('10.1234/example', 'DOI', 0.5))->toBe('Doe, J. (2026): Example. Publisher.');
});

it('skips best-effort resolution when the aggregate budget is exhausted', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldNotReceive('normalizeDoi');
    $dataCite->shouldNotReceive('getMetadata');
    $dataCite->shouldNotReceive('buildCitationFromMetadata');

    $service = new RelatedIdentifierCitationLabelService($dataCite);

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

    $service = new RelatedIdentifierCitationLabelService($dataCite);

    expect($service->resolveBestEffort('10.1234/budget', 'DOI', microtime(true) + 1))->toBe('Budget citation');
});

it('skips best-effort resolution when too little aggregate budget remains', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldNotReceive('normalizeDoi');
    $dataCite->shouldNotReceive('getMetadata');

    $service = new RelatedIdentifierCitationLabelService($dataCite);

    expect($service->resolveBestEffort('10.1234/example', 'DOI', microtime(true) + 0.05))->toBeNull();
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

    $resolved = (new RelatedIdentifierCitationLabelService($dataCite))
        ->resolveRequired($relatedIdentifiers);

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

    $resolved = (new RelatedIdentifierCitationLabelService($dataCite))->resolveRequired([
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

it('fails required resolution for a malformed explicit DOI without making a batch request', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class)->makePartial();
    $dataCite->shouldNotReceive('getMetadataBatch');

    $service = new RelatedIdentifierCitationLabelService($dataCite);

    try {
        $service->resolveRequired([[
            'relatedIdentifier' => 'not-a-doi',
            'relatedIdentifierType' => 'DOI',
        ]]);

        test()->fail('Expected incomplete citation-label resolution exception.');
    } catch (IncompleteCitationLabelResolutionException $exception) {
        expect($exception->failures)->toBe([
            'not-a-doi' => 'The identifier is not a valid resolvable DOI.',
        ])->and($exception->getMessage())->toContain('The resource was not imported.');
    }
});

it('fails required resolution for an empty explicit DOI and tolerates empty unrelated entries', function () {
    $dataCite = Mockery::mock(DataCiteApiService::class);
    $dataCite->shouldNotReceive('normalizeDoi');
    $dataCite->shouldNotReceive('getMetadataBatch');

    $service = new RelatedIdentifierCitationLabelService($dataCite);

    try {
        $service->resolveRequired([
            [],
            [
                'relatedIdentifier' => '   ',
                'relatedIdentifierType' => 'DOI',
            ],
        ]);

        test()->fail('Expected incomplete citation-label resolution exception.');
    } catch (IncompleteCitationLabelResolutionException $exception) {
        expect($exception->failures)->toBe([
            '[empty DOI at position 1]' => 'The identifier is not a valid resolvable DOI.',
        ]);
    }
});

it('reports remote failures and missing citation text as incomplete', function (mixed $outcome, string $reason) {
    $dataCite = Mockery::mock(DataCiteApiService::class)->makePartial();
    $dataCite->shouldReceive('getMetadataBatch')
        ->once()
        ->with(['10.1234/unresolved'])
        ->andReturn(['10.1234/unresolved' => $outcome]);

    if (($outcome['status'] ?? null) === 'resolved') {
        $dataCite->shouldReceive('buildCitationFromMetadata')->once()->andReturn('   ');
    }

    $service = new RelatedIdentifierCitationLabelService($dataCite);

    try {
        $service->resolveRequired([[
            'relatedIdentifier' => '10.1234/unresolved',
            'relatedIdentifierType' => 'DOI',
        ]]);

        test()->fail('Expected incomplete citation-label resolution exception.');
    } catch (IncompleteCitationLabelResolutionException $exception) {
        expect($exception->failures)->toBe(['10.1234/unresolved' => $reason]);
    }
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

it('abbreviates long unresolved DOI lists in the domain exception message', function () {
    $failures = [];

    foreach (range(1, 6) as $index) {
        $failures["10.1234/failure.{$index}"] = 'Unavailable';
    }

    $exception = new IncompleteCitationLabelResolutionException($failures);

    expect($exception->getMessage())
        ->toContain('6 related DOI(s)')
        ->toContain('10.1234/failure.5, …')
        ->not->toContain('10.1234/failure.6');
});

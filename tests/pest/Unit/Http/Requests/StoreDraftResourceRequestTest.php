<?php

declare(strict_types=1);

use App\Http\Requests\StoreDraftResourceRequest;
use App\Http\Requests\StoreResourceRequest;
use App\Models\RelatedIdentifier;
use App\Models\ResourceCreator;
use Illuminate\Support\Facades\Validator;

covers(StoreDraftResourceRequest::class);

/**
 * @param  array<int, mixed>  $arguments
 */
function invokeDraftRequestMethod(StoreDraftResourceRequest|StoreResourceRequest $request, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionMethod($request, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($request, $arguments);
}

it('normalizes related identifiers and keeps optional related-work fields only when non-empty', function (): void {
    $request = StoreDraftResourceRequest::create('/editor/resources/draft', 'POST', [
        'titles' => [
            ['title' => 'Draft Resource', 'titleType' => 'main-title'],
        ],
        'relatedIdentifiers' => [
            [
                'id' => '42',
                'identifier' => ' 10.5880/test.2026.001 ',
                'identifierType' => ' DOI ',
                'relationType' => ' Other ',
                'relationTypeInformation' => '  Custom relationship  ',
                'citationLabel' => '  Doe, J. (2026): Example citation.  ',
                'source' => '  '.RelatedIdentifier::SOURCE_RELATION_SUGGESTION_ASSISTANT.'  ',
            ],
            [
                'identifier' => ' https://example.org/resource ',
                'identifierType' => 'URL',
                'relationType' => 'References',
                'relationTypeInformation' => '   ',
                'citationLabel' => '   ',
            ],
            [
                'identifier' => '   ',
                'identifierType' => 'DOI',
                'relationType' => 'Cites',
            ],
            'not-an-array',
        ],
    ]);

    invokeDraftRequestMethod($request, 'prepareForValidation');

    expect($request->input('relatedIdentifiers'))->toBe([
        [
            'id' => 42,
            'identifier' => '10.5880/test.2026.001',
            'identifierType' => 'DOI',
            'relationType' => 'Other',
            'relationTypeInformation' => 'Custom relationship',
            'citationLabel' => 'Doe, J. (2026): Example citation.',
            'source' => RelatedIdentifier::SOURCE_RELATION_SUGGESTION_ASSISTANT,
        ],
        [
            'identifier' => 'https://example.org/resource',
            'identifierType' => 'URL',
            'relationType' => 'References',
        ],
    ]);
});

it('keeps fractional related identifier ids invalid instead of coercing them', function (string $requestClass, string $uri): void {
    /** @var StoreDraftResourceRequest|StoreResourceRequest $request */
    $request = $requestClass::create($uri, 'POST', [
        'relatedIdentifiers' => [
            [
                'id' => '42.9',
                'identifier' => '10.5880/test.2026.001',
                'identifierType' => 'DOI',
                'relationType' => 'References',
            ],
        ],
    ]);

    invokeDraftRequestMethod($request, 'prepareForValidation');

    $validator = Validator::make($request->all(), [
        'relatedIdentifiers.*.id' => ['nullable', 'integer', 'min:1'],
    ]);

    expect($request->input('relatedIdentifiers.0.id'))->toBe('42.9')
        ->and($validator->passes())->toBeFalse()
        ->and($validator->errors()->has('relatedIdentifiers.0.id'))->toBeTrue();
})->with([
    'draft request' => [StoreDraftResourceRequest::class, '/editor/resources/draft'],
    'store request' => [StoreResourceRequest::class, '/editor/resources'],
]);

it('normalizes raw rights statements for draft saves', function (): void {
    $request = StoreDraftResourceRequest::create('/editor/resources/draft', 'POST', [
        'titles' => [
            ['title' => 'Draft Resource', 'titleType' => 'main-title'],
        ],
        'licenses' => [' CC-BY-4.0 ', '', 'CC-BY-4.0'],
        'rawRights' => [
            [
                'rights_text' => ' CC BY 4.0 ',
                'rightsURI' => ' http://creativecommons.org/licenses/by/4.0 ',
                'rights_identifier' => ' CC-BY-4.0 ',
                'rightsIdentifierScheme' => ' SPDX ',
                'schemeURI' => ' https://spdx.org/licenses/ ',
                'language' => ' en ',
                'source' => ' xml-upload ',
            ],
            [
                'rights' => '   ',
                'rightsUri' => null,
                'rightsIdentifier' => [],
                'source' => (object) ['ignored' => true],
            ],
            'not-a-statement',
        ],
    ]);

    invokeDraftRequestMethod($request, 'prepareForValidation');

    expect($request->input('licenses'))->toBe(['CC-BY-4.0'])
        ->and($request->input('rawRights'))->toBe([
            [
                'rights' => 'CC BY 4.0',
                'rightsUri' => 'http://creativecommons.org/licenses/by/4.0',
                'rightsIdentifier' => 'CC-BY-4.0',
                'rightsIdentifierScheme' => 'SPDX',
                'schemeUri' => 'https://spdx.org/licenses/',
                'lang' => 'en',
                'source' => 'xml-upload',
            ],
        ]);
});

it('keeps non-array raw rights input unchanged for draft validation', function (): void {
    $request = StoreDraftResourceRequest::create('/editor/resources/draft', 'POST', [
        'titles' => [
            ['title' => 'Draft Resource', 'titleType' => 'main-title'],
        ],
        'rawRights' => 'not-an-array',
    ]);

    invokeDraftRequestMethod($request, 'prepareForValidation');

    expect($request->input('rawRights'))->toBe('not-an-array');
});

it('preserves unstructured creator snapshot metadata during request normalization', function (string $requestClass, string $uri): void {
    /** @var StoreDraftResourceRequest|StoreResourceRequest $request */
    $request = $requestClass::create($uri, 'POST', [
        'titles' => [['title' => 'Snapshot resource', 'titleType' => 'main-title']],
        'authors' => [[
            'type' => 'person',
            'resourceCreatorId' => '42',
            'firstName' => ' ',
            'lastName' => null,
            'nameSnapshot' => '  The Artist  ',
            'position' => 0,
        ]],
    ]);

    invokeDraftRequestMethod($request, 'prepareForValidation');
    $validator = Validator::make($request->all(), $request->rules());
    foreach ($request->after() as $callback) {
        $validator->after($callback);
    }
    $validator->passes();

    expect($request->input('authors.0.resourceCreatorId'))->toBe(42)
        ->and($request->input('authors.0.firstName'))->toBeNull()
        ->and($request->input('authors.0.lastName'))->toBeNull()
        ->and($request->input('authors.0.nameSnapshot'))->toBe('The Artist')
        ->and($validator->errors()->has('authors.0.lastName'))->toBeFalse();
})->with([
    'draft request' => [StoreDraftResourceRequest::class, '/editor/resources/draft'],
    'store request' => [StoreResourceRequest::class, '/editor/resources'],
]);

it('accepts a one-character zero snapshot as a present final creator name', function (): void {
    $request = StoreResourceRequest::create('/editor/resources', 'POST', [
        'titles' => [['title' => 'Snapshot resource', 'titleType' => 'main-title']],
        'authors' => [[
            'type' => 'person',
            'firstName' => null,
            'lastName' => null,
            'nameSnapshot' => '0',
            'position' => 0,
        ]],
    ]);

    invokeDraftRequestMethod($request, 'prepareForValidation');
    $validator = Validator::make($request->all(), $request->rules());
    foreach ($request->after() as $callback) {
        $validator->after($callback);
    }
    $validator->passes();

    expect($request->input('authors.0.nameSnapshot'))->toBe('0')
        ->and($validator->errors()->has('authors.0.lastName'))->toBeFalse();
});

it('aligns creator snapshot validation with the database column length', function (string $requestClass): void {
    /** @var StoreDraftResourceRequest|StoreResourceRequest $request */
    $request = new $requestClass;
    $rules = [
        'authors' => ['array'],
        'authors.*.nameSnapshot' => $request->rules()['authors.*.nameSnapshot'],
    ];
    $atLimit = str_repeat('a', ResourceCreator::MAX_NAME_SNAPSHOT_LENGTH);
    $overLimit = $atLimit.'a';

    expect($rules['authors.*.nameSnapshot'])
        ->toContain('max:'.ResourceCreator::MAX_NAME_SNAPSHOT_LENGTH)
        ->and(Validator::make(['authors' => [['nameSnapshot' => $atLimit]]], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['authors' => [['nameSnapshot' => $overLimit]]], $rules)->passes())->toBeFalse();
})->with([
    'draft request' => StoreDraftResourceRequest::class,
    'store request' => StoreResourceRequest::class,
]);

it('keeps related-work citation label limits aligned between draft and store requests', function (): void {
    $draftRequest = new StoreDraftResourceRequest;
    $storeRequest = new StoreResourceRequest;

    expect($draftRequest->rules()['relatedIdentifiers.*.citationLabel'])
        ->toContain('max:'.RelatedIdentifier::MAX_CITATION_LABEL_CHARACTERS)
        ->and($storeRequest->rules()['relatedIdentifiers.*.citationLabel'])
        ->toContain('max:'.RelatedIdentifier::MAX_CITATION_LABEL_CHARACTERS)
        ->and($draftRequest->rules())->toHaveKey('relatedIdentifiers.*.source')
        ->and($storeRequest->rules())->toHaveKey('relatedIdentifiers.*.source');
});

it('accepts known internal related-work provenance values but rejects unknown values', function (): void {
    foreach ([new StoreDraftResourceRequest, new StoreResourceRequest] as $request) {
        $rules = [
            'relatedIdentifiers' => $request->rules()['relatedIdentifiers'],
            'relatedIdentifiers.*.source' => $request->rules()['relatedIdentifiers.*.source'],
        ];

        foreach (RelatedIdentifier::INTERNAL_SOURCES as $source) {
            expect(Validator::make([
                'relatedIdentifiers' => [['source' => $source]],
            ], $rules)->errors()->toArray())->toBe([]);
        }

        expect(Validator::make([
            'relatedIdentifiers' => [['source' => 'client_assigned']],
        ], $rules)->errors()->toArray())->toHaveKey('relatedIdentifiers.0.source');
    }
});

it('accepts funding references and instruments beyond the former array limits', function (): void {
    foreach ([new StoreDraftResourceRequest, new StoreResourceRequest] as $request) {
        $rules = $request->rules();
        $relevantRules = array_intersect_key($rules, array_flip([
            'fundingReferences',
            'fundingReferences.*.funderName',
            'fundingReferences.*.funderIdentifier',
            'fundingReferences.*.funderIdentifierType',
            'fundingReferences.*.awardNumber',
            'fundingReferences.*.awardUri',
            'fundingReferences.*.awardTitle',
            'instruments',
            'instruments.*.pid',
            'instruments.*.pidType',
            'instruments.*.name',
        ]));
        $payload = [
            'fundingReferences' => array_map(
                fn (int $index): array => ['funderName' => "Legacy Funder {$index}"],
                range(1, 150),
            ),
            'instruments' => array_map(
                fn (int $index): array => [
                    'pid' => "https://example.test/instruments/{$index}",
                    'pidType' => 'URL',
                    'name' => "Legacy Instrument {$index}",
                ],
                range(1, 150),
            ),
        ];

        $validator = Validator::make($payload, $relevantRules);

        expect($rules['fundingReferences'])->toBe(['nullable', 'array', 'max:'.StoreResourceRequest::MAX_REPEATABLE_METADATA_ITEMS])
            ->and($rules['instruments'])->toBe(['nullable', 'array', 'max:'.StoreResourceRequest::MAX_REPEATABLE_METADATA_ITEMS])
            ->and($validator->errors()->toArray())->toBe([]);
    }
});

it('applies a high technical ceiling to funding references and instruments', function (): void {
    $limit = StoreResourceRequest::MAX_REPEATABLE_METADATA_ITEMS;
    $atLimit = array_fill(0, $limit, null);
    $overLimit = [...$atLimit, null];

    expect($limit)->toBeGreaterThan(3_711)
        ->and(StoreDraftResourceRequest::MAX_REPEATABLE_METADATA_ITEMS)->toBe($limit);

    foreach ([new StoreDraftResourceRequest, new StoreResourceRequest] as $request) {
        $rules = [
            'fundingReferences' => $request->rules()['fundingReferences'],
            'instruments' => $request->rules()['instruments'],
        ];

        expect(Validator::make([
            'fundingReferences' => $atLimit,
            'instruments' => $atLimit,
        ], $rules)->errors()->toArray())->toBe([])
            ->and(Validator::make([
                'fundingReferences' => $overLimit,
                'instruments' => $overLimit,
            ], $rules)->errors()->keys())->toEqualCanonicalizing(['fundingReferences', 'instruments']);
    }
});

it('validates and normalizes title language tags for draft and final resource requests', function (): void {
    $draftRequest = StoreDraftResourceRequest::create('/editor/resources/draft', 'POST', [
        'titles' => [
            ['title' => 'Draft Resource', 'titleType' => 'main-title', 'language' => ' EN_us '],
        ],
    ]);
    invokeDraftRequestMethod($draftRequest, 'prepareForValidation');

    $storeRequest = StoreResourceRequest::create('/editor/resources', 'POST', [
        'titles' => [
            ['title' => 'Resource', 'titleType' => 'main-title', 'language' => ' DE '],
        ],
    ]);
    $reflection = new ReflectionMethod($storeRequest, 'prepareForValidation');
    $reflection->setAccessible(true);
    $reflection->invoke($storeRequest);

    expect($draftRequest->input('titles.0.language'))->toBe('en-us')
        ->and($storeRequest->input('titles.0.language'))->toBe('de');

    $draftRules = array_intersect_key($draftRequest->rules(), array_flip([
        'titles',
        'titles.*.title',
        'titles.*.titleType',
        'titles.*.language',
    ]));

    $invalidRequest = StoreDraftResourceRequest::create('/editor/resources/draft', 'POST', [
        'titles' => [
            ['title' => 'Broken Resource', 'titleType' => 'main-title', 'language' => 'not valid'],
        ],
    ]);
    invokeDraftRequestMethod($invalidRequest, 'prepareForValidation');

    $validator = Validator::make($invalidRequest->all(), $draftRules);

    expect($validator->fails())->toBeTrue()
        ->and($invalidRequest->input('titles.0.language'))->toBe('not valid')
        ->and($validator->errors()->has('titles.0.language'))->toBeTrue();
});

/**
 * @param  list<array<string, mixed>>  $dates
 * @param  class-string<StoreDraftResourceRequest|StoreResourceRequest>  $requestClass
 */
function validateDraftDatePayload(array $dates, string $requestClass = StoreDraftResourceRequest::class): Illuminate\Validation\Validator
{
    $uri = $requestClass === StoreDraftResourceRequest::class ? '/editor/resources/draft' : '/editor/resources';
    $request = $requestClass::create($uri, 'POST', [
        'titles' => [
            ['title' => 'Draft Resource', 'titleType' => 'main-title'],
        ],
        'dates' => $dates,
    ]);

    invokeDraftRequestMethod($request, 'prepareForValidation');

    $rules = array_intersect_key($request->rules(), array_flip([
        'dates',
        'dates.*.dateType',
        'dates.*.dateMode',
        'dates.*.startDate',
        'dates.*.endDate',
    ]));

    $validator = Validator::make($request->all(), $rules, $request->messages());

    foreach ($request->after() as $callback) {
        $validator->after($callback);
    }

    $validator->passes();

    return $validator;
}

it('allows closed draft periods for collected, valid, and other dates', function (string $dateType): void {
    $validator = validateDraftDatePayload([
        ['dateType' => $dateType, 'dateMode' => 'range', 'startDate' => '2024-01-01', 'endDate' => '2024-01-31'],
    ]);

    expect($validator->errors()->has('dates.0.endDate'))->toBeFalse()
        ->and($validator->errors()->has('dates.0.startDate'))->toBeFalse();
})->with(['collected', 'valid', 'other']);

it('accepts ISO year and year-month dates in draft and final requests', function (string $requestClass): void {
    $single = validateDraftDatePayload([
        ['dateType' => 'created', 'dateMode' => 'single', 'startDate' => '2020', 'endDate' => null],
    ], $requestClass);
    $period = validateDraftDatePayload([
        ['dateType' => 'collected', 'dateMode' => 'range', 'startDate' => '2020-06', 'endDate' => '2020-06-01'],
    ], $requestClass);

    expect($single->errors()->has('dates.0.startDate'))->toBeFalse()
        ->and($period->errors()->has('dates.0.startDate'))->toBeFalse()
        ->and($period->errors()->has('dates.0.endDate'))->toBeFalse();
})->with([StoreDraftResourceRequest::class, StoreResourceRequest::class]);

it('compares mixed-precision periods using calendar bounds in draft and final requests', function (string $requestClass): void {
    foreach ([
        ['2020', '2020-01-01T00:00:00Z', false],
        ['2020-12-31T23:00:00Z', '2020', false],
        ['2020-06', '2020-06-01T01:00:00+02:00', false],
        ['2020-07', '2020-06-30T23:59:59Z', true],
        ['2020-07-01T00:00:00Z', '2020-06', true],
    ] as [$startDate, $endDate, $expectedReversed]) {
        $validator = validateDraftDatePayload([
            ['dateType' => 'collected', 'dateMode' => 'range', 'startDate' => $startDate, 'endDate' => $endDate],
        ], $requestClass);

        expect($validator->errors()->has('dates.0.endDate'))->toBe($expectedReversed)
            ->and($validator->errors()->has('dates.0.startDate'))->toBeFalse();
    }
})->with([StoreDraftResourceRequest::class, StoreResourceRequest::class]);

it('compares two date-times as instants in draft and final requests', function (string $requestClass): void {
    $validator = validateDraftDatePayload([
        ['dateType' => 'collected', 'dateMode' => 'range', 'startDate' => '2020-01-01T01:00:00Z', 'endDate' => '2020-01-01T01:30:00+02:00'],
    ], $requestClass);

    expect($validator->errors()->has('dates.0.endDate'))->toBeTrue();
})->with([StoreDraftResourceRequest::class, StoreResourceRequest::class]);

it('rejects reversed partial periods and non-ISO editor dates', function (string $requestClass): void {
    $reversed = validateDraftDatePayload([
        ['dateType' => 'collected', 'dateMode' => 'range', 'startDate' => '2020-07', 'endDate' => '2020-06-30'],
    ], $requestClass);
    $localized = validateDraftDatePayload([
        ['dateType' => 'created', 'dateMode' => 'single', 'startDate' => '24.09.2020', 'endDate' => null],
    ], $requestClass);
    $impossible = validateDraftDatePayload([
        ['dateType' => 'created', 'dateMode' => 'single', 'startDate' => '2020-02-30', 'endDate' => null],
    ], $requestClass);
    $invalidTime = validateDraftDatePayload([
        ['dateType' => 'created', 'dateMode' => 'single', 'startDate' => '2020-09-24T99:99:00Z', 'endDate' => null],
    ], $requestClass);

    expect($reversed->errors()->has('dates.0.endDate'))->toBeTrue()
        ->and($localized->errors()->has('dates.0.startDate'))->toBeTrue()
        ->and($impossible->errors()->has('dates.0.startDate'))->toBeTrue()
        ->and($invalidTime->errors()->has('dates.0.startDate'))->toBeTrue();
})->with([StoreDraftResourceRequest::class, StoreResourceRequest::class]);

it('rejects unsupported draft date periods', function (): void {
    $validator = validateDraftDatePayload([
        ['dateType' => 'available', 'dateMode' => 'range', 'startDate' => '2024-01-01', 'endDate' => '2024-01-31'],
    ]);

    expect($validator->errors()->has('dates.0.endDate'))->toBeTrue();
});

it('rejects draft end dates without start dates', function (): void {
    $validator = validateDraftDatePayload([
        ['dateType' => 'collected', 'dateMode' => 'range', 'startDate' => null, 'endDate' => '2024-01-31'],
    ]);

    expect($validator->errors()->has('dates.0.startDate'))->toBeTrue();
});

it('rejects draft periods whose end date is before the start date', function (): void {
    $validator = validateDraftDatePayload([
        ['dateType' => 'other', 'dateMode' => 'range', 'startDate' => '2024-02-01', 'endDate' => '2024-01-31'],
    ]);

    expect($validator->errors()->has('dates.0.endDate'))->toBeTrue();
});
it('rejects draft range date mode without an end date', function (): void {
    $validator = validateDraftDatePayload([
        ['dateType' => 'collected', 'dateMode' => 'range', 'startDate' => '2024-01-01', 'endDate' => null],
    ]);

    expect($validator->errors()->has('dates.0.endDate'))->toBeTrue();
});

it('rejects unknown draft date modes', function (): void {
    $validator = validateDraftDatePayload([
        ['dateType' => 'collected', 'dateMode' => 'period', 'startDate' => '2024-01-01', 'endDate' => '2024-01-31'],
    ]);

    expect($validator->errors()->has('dates.0.dateMode'))->toBeTrue();
});

it('rejects draft single date mode with an end date', function (): void {
    $validator = validateDraftDatePayload([
        ['dateType' => 'valid', 'dateMode' => 'single', 'startDate' => '2024-01-01', 'endDate' => '2024-01-31'],
    ]);

    expect($validator->errors()->has('dates.0.endDate'))->toBeTrue();
});

it('keeps date mode validation aligned between draft and final resource requests', function (): void {
    $draftRequest = new StoreDraftResourceRequest;
    $storeRequest = new StoreResourceRequest;

    expect($draftRequest->rules())->toHaveKey('dates.*.dateMode')
        ->and($storeRequest->rules())->toHaveKey('dates.*.dateMode');
});

/** @param array<string, mixed> $coverage */
function validateTemporalCoverageRequest(string $requestClass, array $coverage): Illuminate\Contracts\Validation\Validator
{
    /** @var StoreDraftResourceRequest|StoreResourceRequest $request */
    $request = $requestClass::create('/editor/resources', 'POST', [
        'spatialTemporalCoverages' => [$coverage],
    ]);
    invokeDraftRequestMethod($request, 'prepareForValidation');
    $rules = array_filter(
        $request->rules(),
        static fn (string $key): bool => str_starts_with($key, 'spatialTemporalCoverages'),
        ARRAY_FILTER_USE_KEY,
    );
    $validator = Validator::make($request->all(), $rules);

    foreach ($request->after() as $callback) {
        $validator->after($callback);
    }
    $validator->passes();

    return $validator;
}

it('validates temporal coverage cross-field dependencies for draft and final saves', function (string $requestClass): void {
    $base = [
        'type' => 'point',
        'latMin' => '',
        'latMax' => '',
        'lonMin' => '',
        'lonMax' => '',
    ];

    $timeWithoutDate = validateTemporalCoverageRequest($requestClass, [
        ...$base,
        'startTime' => '12:30',
    ]);
    $timezoneOnly = validateTemporalCoverageRequest($requestClass, [
        ...$base,
        'timezone' => 'UTC',
    ]);

    expect($timeWithoutDate->errors()->has('spatialTemporalCoverages.0.startTime'))->toBeTrue()
        ->and($timezoneOnly->errors()->has('spatialTemporalCoverages.0.timezone'))->toBeTrue();
})->with([
    'draft save' => StoreDraftResourceRequest::class,
    'final save' => StoreResourceRequest::class,
]);

it('accepts valid reduced-precision coverage dates and rejects ambiguous non-ISO input', function (string $requestClass): void {
    $base = [
        'type' => 'point',
        'latMin' => '',
        'latMax' => '',
        'lonMin' => '',
        'lonMax' => '',
        'temporalMode' => 'interval',
    ];

    $reducedPrecision = validateTemporalCoverageRequest($requestClass, [
        ...$base,
        'startDate' => '2025',
        'endDate' => '2026-08',
    ]);
    $nonIso = validateTemporalCoverageRequest($requestClass, [
        ...$base,
        'startDate' => '12/31/2025',
        'endDate' => '01/01/2026',
    ]);

    expect($reducedPrecision->errors()->has('spatialTemporalCoverages.0.startDate'))->toBeFalse()
        ->and($reducedPrecision->errors()->has('spatialTemporalCoverages.0.endDate'))->toBeFalse()
        ->and($nonIso->errors()->has('spatialTemporalCoverages.0.startDate'))->toBeTrue()
        ->and($nonIso->errors()->has('spatialTemporalCoverages.0.endDate'))->toBeTrue();
})->with([
    'draft save' => StoreDraftResourceRequest::class,
    'final save' => StoreResourceRequest::class,
]);

it('compares reduced-precision temporal ranges by their possible bounds', function (string $requestClass): void {
    $validator = validateTemporalCoverageRequest($requestClass, [
        'type' => 'point',
        'latMin' => '',
        'latMax' => '',
        'lonMin' => '',
        'lonMax' => '',
        'startDate' => '2027',
        'endDate' => '2026-12',
        'temporalMode' => 'interval',
    ]);

    expect($validator->errors()->has('spatialTemporalCoverages.0.endDate'))->toBeTrue();
})->with([
    'draft save' => StoreDraftResourceRequest::class,
    'final save' => StoreResourceRequest::class,
]);

it('treats HH:MM and equivalent HH:MM:SS coverage times as equal', function (string $requestClass): void {
    $validator = validateTemporalCoverageRequest($requestClass, [
        'type' => 'point',
        'latMin' => '',
        'latMax' => '',
        'lonMin' => '',
        'lonMax' => '',
        'startDate' => '2026-08-27',
        'endDate' => '2026-08-27',
        'startTime' => '12:30:00',
        'endTime' => '12:30',
        'temporalMode' => 'interval',
    ]);

    expect($validator->errors()->has('spatialTemporalCoverages.0.endDate'))->toBeFalse();
})->with([
    'draft save' => StoreDraftResourceRequest::class,
    'final save' => StoreResourceRequest::class,
]);

it('reports reversed same-day temporal times on the end time field', function (string $requestClass): void {
    $validator = validateTemporalCoverageRequest($requestClass, [
        'type' => 'point',
        'latMin' => '',
        'latMax' => '',
        'lonMin' => '',
        'lonMax' => '',
        'startDate' => '2026-08-27',
        'endDate' => '2026-08-27',
        'startTime' => '17:37',
        'endTime' => '14:37',
        'temporalMode' => 'interval',
    ]);

    expect($validator->errors()->has('spatialTemporalCoverages.0.endTime'))->toBeTrue()
        ->and($validator->errors()->has('spatialTemporalCoverages.0.endDate'))->toBeFalse();
})->with([
    'draft save' => StoreDraftResourceRequest::class,
    'final save' => StoreResourceRequest::class,
]);

it('uses position-aware safe URL messages for draft custom license URLs', function (): void {
    $request = new StoreDraftResourceRequest;

    $validator = Validator::make(
        [
            'titles' => [
                ['title' => 'Draft Resource', 'titleType' => 'main-title'],
            ],
            'customLicenses' => [
                [
                    'name' => 'Unsafe License',
                    'uri' => 'javascript:alert(1)',
                ],
            ],
        ],
        $request->rules(),
        $request->messages(),
        $request->attributes(),
    );

    expect($validator->fails())->toBeTrue();

    $message = $validator->errors()->first('customLicenses.0.uri');

    expect($message)->toBe('[Licenses & Rights] The Custom license #1 license text URL must use http or https protocol.')
        ->and($message)->not->toContain('customLicenses.0.uri');
});

it('normalizes custom licenses for draft saves', function (): void {
    $request = StoreDraftResourceRequest::create('/editor/resources/draft', 'POST', [
        'titles' => [
            ['title' => 'Draft Resource', 'titleType' => 'main-title'],
        ],
        'customLicenses' => [
            [
                'rights_text' => ' Community Data License ',
                'rightsURI' => ' https://example.test/licenses/community-data ',
                'source_resource_right_id' => '42',
            ],
            [
                'name' => 'Started custom license',
                'uri' => null,
            ],
            [
                'name' => '   ',
                'uri' => ' https://example.test/licenses/missing-name ',
            ],
            [
                'sourceResourceRightId' => '43',
            ],
        ],
    ]);

    invokeDraftRequestMethod($request, 'prepareForValidation');

    expect($request->input('customLicenses'))->toBe([
        [
            'name' => 'Community Data License',
            'uri' => 'https://example.test/licenses/community-data',
            'sourceResourceRightId' => 42,
        ],
    ]);
});

<?php

declare(strict_types=1);

use App\Services\DataCiteSubjectMergeService;

covers(DataCiteSubjectMergeService::class);

beforeEach(function (): void {
    $this->service = new DataCiteSubjectMergeService;
});

it('adds all three Issue 1091 legacy subjects when DataCite has none', function (): void {
    $legacySubjects = [
        ['subject' => 'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY', 'subjectScheme' => 'Science Keywords'],
        ['subject' => 'EARTH SCIENCE > SOLID EARTH > TECTONICS', 'subjectScheme' => 'Science Keywords'],
        ['subject' => 'EARTH SCIENCE > SOLID EARTH > CRUSTAL DYNAMICS', 'subjectScheme' => 'Science Keywords'],
    ];

    expect($this->service->merge([], $legacySubjects))->toBe($legacySubjects);
});

it('preserves DataCite subjects and appends missing legacy subjects in source order', function (): void {
    $dataCiteSubjects = [
        ['subject' => 'DataCite free keyword', 'lang' => 'de'],
        [
            'subject' => 'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY',
            'subjectScheme' => 'Science Keywords',
            'valueUri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/02acb399-a247-5e23-ab4f-63d001267e22',
        ],
    ];
    $legacySubjects = [
        ['subject' => 'Legacy free keyword'],
        [
            'subject' => 'Platforms > Aircraft',
            'subjectScheme' => 'Platforms',
            'valueUri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/6848f19a-1a5f-5f80-b2e1-6178362f50b7',
        ],
    ];

    expect($this->service->merge($dataCiteSubjects, $legacySubjects))->toBe([
        ...$dataCiteSubjects,
        ...$legacySubjects,
    ]);
});

it('deduplicates old and current GCMD value URI formats', function (): void {
    $uuid = '02acb399-a247-5e23-ab4f-63d001267e22';
    $dataCiteSubject = [
        'subject' => 'SEISMOLOGY',
        'subjectScheme' => 'GCMD Science Keywords',
        'valueUri' => "http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/{$uuid}",
    ];
    $legacySubject = [
        'subject' => 'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY',
        'subjectScheme' => 'Science Keywords',
        'valueUri' => "https://gcmd.earthdata.nasa.gov/kms/concept/{$uuid}",
    ];

    expect($this->service->merge([$dataCiteSubject], [$legacySubject]))->toBe([$dataCiteSubject]);
});

it('deduplicates controlled paths across scheme aliases, encoded separators, and a synthetic scheme root', function (): void {
    $dataCiteSubject = [
        'subject' => 'EARTH SCIENCE > SOLID EARTH > TECTONICS',
        'subjectScheme' => 'NASA/GCMD Earth Science Keywords',
    ];
    $legacySubject = [
        'subject' => ' Science Keywords &gt; earth science  > solid earth > tectonics ',
        'subjectScheme' => 'Science Keywords',
    ];

    expect($this->service->merge([$dataCiteSubject], [$legacySubject]))->toBe([$dataCiteSubject]);
});

it('enriches an equivalent Issue 1115 GEMET subject with missing legacy metadata', function (): void {
    $dataCiteSubject = [
        'subject' => 'geodesy',
        'subjectScheme' => 'GEMET - INSPIRE themes, version 1.0',
    ];
    $legacySubject = [
        'subject' => 'geodesy',
        'subjectScheme' => 'GEMET - GEneral Multilingual Environmental Thesaurus',
        'schemeUri' => 'http://www.eionet.europa.eu/gemet/concept/',
        'valueUri' => 'http://www.eionet.europa.eu/gemet/concept/3638',
        'lang' => 'en',
    ];

    expect($this->service->merge([$dataCiteSubject], [$legacySubject]))->toBe([[
        ...$dataCiteSubject,
        'schemeUri' => 'http://www.eionet.europa.eu/gemet/concept/',
        'valueUri' => 'http://www.eionet.europa.eu/gemet/concept/3638',
        'lang' => 'en',
    ]]);
});

it('never overwrites existing metadata while enriching an equivalent subject', function (): void {
    $dataCiteSubject = [
        'subject' => 'geodesy',
        'subjectScheme' => 'GEMET',
        'scheme_uri' => 'https://example.test/original-scheme',
        'valueURI' => 'http://www.eionet.europa.eu/gemet/concept/3638',
        'language' => 'de',
    ];
    $legacySubject = [
        'subject' => 'geodesy',
        'subjectScheme' => 'GEMET - GEneral Multilingual Environmental Thesaurus',
        'schemeUri' => 'http://www.eionet.europa.eu/gemet/concept/',
        'valueUri' => 'http://www.eionet.europa.eu/gemet/concept/3638',
        'lang' => 'en',
    ];

    expect($this->service->merge([$dataCiteSubject], [$legacySubject]))->toBe([$dataCiteSubject]);
});

it('deduplicates controlled subjects by classification code and normalized scheme', function (): void {
    $dataCiteSubject = [
        'subject' => 'A DataCite label',
        'subjectScheme' => 'GCMD Platforms',
        'classificationCode' => 'PLATFORM-42',
    ];
    $legacySubject = [
        'subject' => 'A different legacy path',
        'subjectScheme' => 'Platforms',
        'classification_code' => ' platform-42 ',
    ];

    expect($this->service->merge([$dataCiteSubject], [$legacySubject]))->toBe([$dataCiteSubject]);
});

it('normalizes Unicode, whitespace, and casing when deduplicating free subjects', function (): void {
    $dataCiteSubject = ['subject' => "Caf\u{00E9}   au lait"];
    $legacySubject = ['subject' => " cafe\u{0301} au LAIT "];

    expect($this->service->merge([$dataCiteSubject], [$legacySubject]))->toBe([$dataCiteSubject]);
});

it('keeps controlled and free subjects with identical visible text separate', function (): void {
    $freeSubject = ['subject' => 'SEISMOLOGY'];
    $controlledSubject = [
        'subject' => 'SEISMOLOGY',
        'subjectScheme' => 'Science Keywords',
        'valueUri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/02acb399-a247-5e23-ab4f-63d001267e22',
    ];

    expect($this->service->merge([$freeSubject], [$controlledSubject]))->toBe([
        $freeSubject,
        $controlledSubject,
    ]);
});

it('does not deduplicate distinct URI-only controlled subjects by their label', function (): void {
    $dataCiteSubject = [
        'subject' => 'Shared label',
        'valueUri' => 'https://vocabulary.example.test/concept/datacite',
    ];
    $legacySubject = [
        'subject' => 'Shared label',
        'valueUri' => 'https://vocabulary.example.test/concept/legacy',
    ];

    expect($this->service->merge([$dataCiteSubject], [$legacySubject]))->toBe([
        $dataCiteSubject,
        $legacySubject,
    ]);
});

it('deduplicates repeated legacy subjects while preserving the first representation', function (): void {
    $firstLegacySubject = ['subject' => '  Arctic   research '];
    $secondLegacySubject = ['subject' => 'arctic research'];

    expect($this->service->merge([], [$firstLegacySubject, $secondLegacySubject]))->toBe([
        $firstLegacySubject,
    ]);
});

it('ignores malformed legacy subjects without changing malformed DataCite input', function (): void {
    $dataCiteSubjects = ['unchanged', ['unexpected' => 'DataCite value']];

    expect($this->service->merge($dataCiteSubjects, [
        'not-an-array',
        [],
        ['subject' => '   '],
        [
            'subject' => 'Controlled value without a usable identity',
            'schemeUri' => 'https://vocabulary.example.test/unknown-scheme',
        ],
    ]))->toBe($dataCiteSubjects);
});

it('merges subjects into wrapped and flat DOI records', function (): void {
    $legacySubject = ['subject' => 'Legacy keyword'];
    $wrapped = [
        'id' => '10.5880/wrapped',
        'attributes' => [
            'doi' => '10.5880/wrapped',
            'subjects' => [['subject' => 'DataCite keyword']],
        ],
    ];
    $flat = [
        'doi' => '10.5880/flat',
        'subjects' => [['subject' => 'DataCite keyword']],
    ];

    expect($this->service->mergeIntoDoiRecord($wrapped, [$legacySubject]))->toBe([
        'id' => '10.5880/wrapped',
        'attributes' => [
            'doi' => '10.5880/wrapped',
            'subjects' => [
                ['subject' => 'DataCite keyword'],
                $legacySubject,
            ],
        ],
    ])->and($this->service->mergeIntoDoiRecord($flat, [$legacySubject]))->toBe([
        'doi' => '10.5880/flat',
        'subjects' => [
            ['subject' => 'DataCite keyword'],
            $legacySubject,
        ],
    ]);
});

it('replaces malformed subject containers only when legacy enrichment is available', function (): void {
    $legacySubject = ['subject' => 'Legacy keyword'];

    expect($this->service->mergeIntoDoiRecord([
        'attributes' => [
            'doi' => '10.5880/wrapped-malformed',
            'subjects' => 'not-an-array',
        ],
    ], [$legacySubject]))->toBe([
        'attributes' => [
            'doi' => '10.5880/wrapped-malformed',
            'subjects' => [$legacySubject],
        ],
    ])->and($this->service->mergeIntoDoiRecord([
        'doi' => '10.5880/flat-malformed',
        'subjects' => 'not-an-array',
    ], [$legacySubject]))->toBe([
        'doi' => '10.5880/flat-malformed',
        'subjects' => [$legacySubject],
    ]);
});

it('returns the exact DOI record when no legacy subjects are available', function (): void {
    $record = [
        'id' => '10.5880/unchanged',
        'attributes' => [
            'doi' => '10.5880/unchanged',
            'subjects' => 'malformed-but-unchanged',
        ],
    ];

    expect($this->service->mergeIntoDoiRecord($record, []))->toBe($record);
});

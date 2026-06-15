<?php

declare(strict_types=1);

use App\Services\SubjectBreadcrumbPathResolverService;
use App\Support\GemetVocabularyParser;
use Illuminate\Support\Facades\Storage;

covers(SubjectBreadcrumbPathResolverService::class);

beforeEach(function (): void {
    Storage::fake('local');
});

it('resolves a GCMD breadcrumb path by value_uri and drops the synthetic scheme root', function (): void {
    Storage::disk('local')->put('gcmd-science-keywords.json', json_encode([
        'data' => [[
            'id' => 'science-root',
            'text' => 'Science Keywords',
            'scheme' => 'NASA/GCMD Earth Science Keywords',
            'children' => [[
                'id' => 'earth-science',
                'text' => 'EARTH SCIENCE',
                'scheme' => 'NASA/GCMD Earth Science Keywords',
                'children' => [[
                    'id' => 'solid-earth',
                    'text' => 'SOLID EARTH',
                    'scheme' => 'NASA/GCMD Earth Science Keywords',
                    'children' => [[
                        'id' => 'science-seismology',
                        'text' => 'SEISMOLOGY',
                        'scheme' => 'NASA/GCMD Earth Science Keywords',
                        'children' => [],
                    ]],
                ]],
            ]],
        ]],
    ], JSON_THROW_ON_ERROR));

    $resolver = new SubjectBreadcrumbPathResolverService;

    expect($resolver->resolve(
        subjectScheme: 'NASA/GCMD Earth Science Keywords',
        valueUri: 'science-seismology',
        classificationCode: null,
        subjectValue: 'SEISMOLOGY',
    ))->toBe('EARTH SCIENCE > SOLID EARTH > SEISMOLOGY');
});

it('resolves a breadcrumb path from classification codes when the vocabulary provides notation', function (): void {
    Storage::disk('local')->put('chronostrat-timescale.json', json_encode([
        'data' => [[
            'id' => 'chrono-root',
            'text' => 'Cenozoic',
            'scheme' => 'International Chronostratigraphic Chart',
            'notation' => 'CZ',
            'children' => [[
                'id' => 'chrono-quaternary',
                'text' => 'Quaternary',
                'scheme' => 'International Chronostratigraphic Chart',
                'notation' => 'Q',
                'children' => [],
            ]],
        ]],
    ], JSON_THROW_ON_ERROR));

    $resolver = new SubjectBreadcrumbPathResolverService;

    expect($resolver->resolve(
        subjectScheme: 'International Chronostratigraphic Chart',
        valueUri: null,
        classificationCode: 'Q',
        subjectValue: 'Quaternary',
    ))->toBe('Cenozoic > Quaternary');
});

it('keeps an embedded hierarchical subject value as the breadcrumb path', function (): void {
    $resolver = new SubjectBreadcrumbPathResolverService;

    expect($resolver->resolve(
        subjectScheme: 'Science Keywords',
        valueUri: null,
        classificationCode: null,
        subjectValue: 'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY',
    ))->toBe('EARTH SCIENCE > SOLID EARTH > SEISMOLOGY');
});

it('resolves a controlled keyword from a legacy full path without value_uri', function (): void {
    Storage::disk('local')->put('gcmd-science-keywords.json', json_encode([
        'data' => [[
            'id' => 'earth-science',
            'text' => 'EARTH SCIENCE',
            'scheme' => 'NASA/GCMD Earth Science Keywords',
            'children' => [[
                'id' => 'biosphere',
                'text' => 'BIOSPHERE',
                'scheme' => 'NASA/GCMD Earth Science Keywords',
                'children' => [[
                    'id' => 'terrestrial-ecosystems',
                    'text' => 'TERRESTRIAL ECOSYSTEMS',
                    'scheme' => 'NASA/GCMD Earth Science Keywords',
                    'children' => [[
                        'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/forest-uuid',
                        'text' => 'FORESTS',
                        'scheme' => 'NASA/GCMD Earth Science Keywords',
                        'children' => [],
                    ]],
                ]],
            ]],
        ]],
    ], JSON_THROW_ON_ERROR));

    $resolver = new SubjectBreadcrumbPathResolverService;

    $result = $resolver->resolveKeywordFromPath(
        subjectScheme: 'NASA/GCMD Earth Science Keywords',
        subjectValue: 'EARTH SCIENCE &gt; BIOSPHERE &gt; TERRESTRIAL ECOSYSTEMS &gt; FORESTS',
    );

    expect($result)->toBe([
        'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/forest-uuid',
        'text' => 'FORESTS',
        'path' => 'EARTH SCIENCE > BIOSPHERE > TERRESTRIAL ECOSYSTEMS > FORESTS',
        'scheme' => 'Science Keywords',
        'schemeURI' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
    ]);
});

it('resolves a controlled keyword from a legacy path that omits a unique intermediate node', function (): void {
    Storage::disk('local')->put('gcmd-science-keywords.json', json_encode([
        'data' => [[
            'id' => 'science-root',
            'text' => 'Science Keywords',
            'scheme' => 'NASA/GCMD Earth Science Keywords',
            'children' => [[
                'id' => 'earth-science',
                'text' => 'EARTH SCIENCE',
                'scheme' => 'NASA/GCMD Earth Science Keywords',
                'children' => [[
                    'id' => 'biosphere',
                    'text' => 'BIOSPHERE',
                    'scheme' => 'NASA/GCMD Earth Science Keywords',
                    'children' => [[
                        'id' => 'ecosystems',
                        'text' => 'ECOSYSTEMS',
                        'scheme' => 'NASA/GCMD Earth Science Keywords',
                        'children' => [[
                            'id' => 'terrestrial-ecosystems',
                            'text' => 'TERRESTRIAL ECOSYSTEMS',
                            'scheme' => 'NASA/GCMD Earth Science Keywords',
                            'children' => [[
                                'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/forest-uuid',
                                'text' => 'FORESTS',
                                'scheme' => 'NASA/GCMD Earth Science Keywords',
                                'children' => [],
                            ]],
                        ]],
                    ]],
                ]],
            ]],
        ]],
    ], JSON_THROW_ON_ERROR));

    $resolver = new SubjectBreadcrumbPathResolverService;

    $result = $resolver->resolveKeywordFromPath(
        subjectScheme: 'NASA/GCMD Earth Science Keywords',
        subjectValue: 'EARTH SCIENCE &gt; BIOSPHERE &gt; TERRESTRIAL ECOSYSTEMS &gt; FORESTS',
    );

    expect($result)->toBe([
        'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/forest-uuid',
        'text' => 'FORESTS',
        'path' => 'EARTH SCIENCE > BIOSPHERE > ECOSYSTEMS > TERRESTRIAL ECOSYSTEMS > FORESTS',
        'scheme' => 'Science Keywords',
        'schemeURI' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
    ]);
});

it('does not resolve a value_uri from ambiguous legacy full paths', function (): void {
    Storage::disk('local')->put('gcmd-science-keywords.json', json_encode([
        'data' => [[
            'id' => 'earth-science-a',
            'text' => 'EARTH SCIENCE',
            'scheme' => 'NASA/GCMD Earth Science Keywords',
            'children' => [[
                'id' => 'shared-a',
                'text' => 'SHARED',
                'scheme' => 'NASA/GCMD Earth Science Keywords',
                'children' => [],
            ]],
        ], [
            'id' => 'earth-science-b',
            'text' => 'EARTH SCIENCE',
            'scheme' => 'NASA/GCMD Earth Science Keywords',
            'children' => [[
                'id' => 'shared-b',
                'text' => 'SHARED',
                'scheme' => 'NASA/GCMD Earth Science Keywords',
                'children' => [],
            ]],
        ]],
    ], JSON_THROW_ON_ERROR));

    $resolver = new SubjectBreadcrumbPathResolverService;

    expect($resolver->resolveKeywordFromPath(
        subjectScheme: 'NASA/GCMD Earth Science Keywords',
        subjectValue: 'EARTH SCIENCE > SHARED',
    ))->toBeNull();
});

it('infers known scheme URIs from legacy subject scheme names', function (): void {
    config(['euroscivoc.concept_scheme_uri' => 'http://example.test/euroscivoc/scheme']);

    $resolver = new SubjectBreadcrumbPathResolverService;

    expect($resolver->resolveSchemeUri('NASA/GCMD Earth Science Keywords'))
        ->toBe('https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords')
        ->and($resolver->resolveSchemeUri('NASA/GCMD Instruments'))
        ->toBe('https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/instruments')
        ->and($resolver->resolveSchemeUri('European Science Vocabulary (EuroSciVoc)'))
        ->toBe('http://example.test/euroscivoc/scheme')
        ->and($resolver->resolveSchemeUri('Invented Scheme'))
        ->toBeNull();
});

it('returns null when no embedded hierarchy or normalized scheme is available', function (): void {
    $resolver = new SubjectBreadcrumbPathResolverService;

    expect($resolver->resolve(
        subjectScheme: '   ',
        valueUri: 'science-seismology',
        classificationCode: 'Q',
        subjectValue: 'Seismology',
    ))->toBeNull();
});

it('resolves a unique leaf label from a list-based vocabulary without stable identifiers', function (): void {
    Storage::disk('local')->put('analytical-methods.json', json_encode([
        'skip-this-root',
        [
            'id' => 'analytical-root',
            'text' => 'Analytical Methods for Geochemistry and Cosmochemistry',
            'children' => [
                'ignore-this-child',
                [
                    'id' => '',
                    'text' => 'Microscopy',
                    'children' => 'not-an-array',
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $resolver = new SubjectBreadcrumbPathResolverService;

    expect($resolver->resolve(
        subjectScheme: 'Analytical Methods for Geochemistry and Cosmochemistry',
        valueUri: null,
        classificationCode: null,
        subjectValue: 'Microscopy',
    ))->toBe('Microscopy');
});

it('returns null when a mapped vocabulary file contains invalid JSON', function (): void {
    Storage::disk('local')->put('gcmd-platforms.json', '{invalid-json');

    $resolver = new SubjectBreadcrumbPathResolverService;

    expect($resolver->resolve(
        subjectScheme: 'Platforms',
        valueUri: 'platform-1',
        classificationCode: null,
        subjectValue: 'Satellite',
    ))->toBeNull();
});

it('returns null when a mapped vocabulary payload does not contain an array of roots', function (): void {
    Storage::disk('local')->put('euroscivoc.json', json_encode([
        'data' => 'not-an-array',
    ], JSON_THROW_ON_ERROR));

    $resolver = new SubjectBreadcrumbPathResolverService;

    expect($resolver->resolve(
        subjectScheme: 'European Science Vocabulary (EuroSciVoc)',
        valueUri: 'euroscivoc-1',
        classificationCode: null,
        subjectValue: 'Earth sciences',
    ))->toBeNull();
});

it('reuses cached indexes on subsequent lookups', function (): void {
    Storage::disk('local')->put('gcmd-science-keywords.json', json_encode([
        'data' => [[
            'id' => 'science-root',
            'text' => 'Science Keywords',
            'scheme' => 'NASA/GCMD Earth Science Keywords',
            'children' => [[
                'id' => 'earth-science',
                'text' => 'EARTH SCIENCE',
                'scheme' => 'NASA/GCMD Earth Science Keywords',
                'children' => [[
                    'id' => 'solid-earth',
                    'text' => 'SOLID EARTH',
                    'scheme' => 'NASA/GCMD Earth Science Keywords',
                    'children' => [[
                        'id' => 'science-seismology',
                        'text' => 'SEISMOLOGY',
                        'scheme' => 'NASA/GCMD Earth Science Keywords',
                        'children' => [],
                    ]],
                ]],
            ]],
        ]],
    ], JSON_THROW_ON_ERROR));

    $resolver = new SubjectBreadcrumbPathResolverService;

    expect($resolver->resolve(
        subjectScheme: 'NASA/GCMD Earth Science Keywords',
        valueUri: 'science-seismology',
        classificationCode: null,
        subjectValue: 'SEISMOLOGY',
    ))->toBe('EARTH SCIENCE > SOLID EARTH > SEISMOLOGY');

    Storage::disk('local')->put('gcmd-science-keywords.json', json_encode([
        'data' => [],
    ], JSON_THROW_ON_ERROR));

    expect($resolver->resolve(
        subjectScheme: 'NASA/GCMD Earth Science Keywords',
        valueUri: 'science-seismology',
        classificationCode: null,
        subjectValue: 'SEISMOLOGY',
    ))->toBe('EARTH SCIENCE > SOLID EARTH > SEISMOLOGY');
});

it('loads only the vocabulary file for the requested scheme', function (): void {
    $disk = new class
    {
        /** @var array<int, string> */
        public array $requests = [];

        public function exists(string $fileName): bool
        {
            $this->requests[] = "exists:{$fileName}";

            return $fileName === 'gcmd-science-keywords.json';
        }

        public function get(string $fileName): string
        {
            $this->requests[] = "get:{$fileName}";

            return json_encode([
                'data' => [[
                    'id' => 'science-root',
                    'text' => 'Science Keywords',
                    'scheme' => 'NASA/GCMD Earth Science Keywords',
                    'children' => [[
                        'id' => 'earth-science',
                        'text' => 'EARTH SCIENCE',
                        'scheme' => 'NASA/GCMD Earth Science Keywords',
                        'children' => [[
                            'id' => 'solid-earth',
                            'text' => 'SOLID EARTH',
                            'scheme' => 'NASA/GCMD Earth Science Keywords',
                            'children' => [[
                                'id' => 'science-seismology',
                                'text' => 'SEISMOLOGY',
                                'scheme' => 'NASA/GCMD Earth Science Keywords',
                                'children' => [],
                            ]],
                        ]],
                    ]],
                ]],
            ], JSON_THROW_ON_ERROR);
        }
    };

    Storage::shouldReceive('disk')
        ->with('local')
        ->andReturn($disk);

    $resolver = new SubjectBreadcrumbPathResolverService;

    expect($resolver->resolve(
        subjectScheme: 'NASA/GCMD Earth Science Keywords',
        valueUri: 'science-seismology',
        classificationCode: null,
        subjectValue: 'SEISMOLOGY',
    ))->toBe('EARTH SCIENCE > SOLID EARTH > SEISMOLOGY')
        ->and($disk->requests)->toBe([
            'exists:gcmd-science-keywords.json',
            'get:gcmd-science-keywords.json',
        ]);
});

it('returns null when a node cannot produce any breadcrumb segments', function (): void {
    Storage::disk('local')->put('gcmd-platforms.json', json_encode([
        'data' => [[
            'id' => 'platform-root',
            'text' => '   ',
            'scheme' => 'Platforms',
            'children' => [],
        ]],
    ], JSON_THROW_ON_ERROR));

    $resolver = new SubjectBreadcrumbPathResolverService;

    expect($resolver->resolve(
        subjectScheme: 'Platforms',
        valueUri: 'platform-root',
        classificationCode: null,
        subjectValue: 'Satellite',
    ))->toBeNull();
});

it('returns null when no identifier, notation, or leaf value is available', function (): void {
    $resolver = new SubjectBreadcrumbPathResolverService;

    expect($resolver->resolve(
        subjectScheme: 'Platforms',
        valueUri: null,
        classificationCode: null,
        subjectValue: null,
    ))->toBeNull();
});

it('returns null when the storage layer yields a non-string payload', function (): void {
    $disk = new class
    {
        public function exists(string $fileName): bool
        {
            return $fileName === 'gcmd-platforms.json';
        }

        public function get(string $fileName): array
        {
            return [$fileName];
        }
    };

    Storage::shouldReceive('disk')
        ->with('local')
        ->andReturn($disk);

    $resolver = new SubjectBreadcrumbPathResolverService;

    expect($resolver->resolve(
        subjectScheme: 'Platforms',
        valueUri: 'platform-1',
        classificationCode: null,
        subjectValue: 'Satellite',
    ))->toBeNull();
});

it('ignores malformed child entries while indexing nodes', function (): void {
    $resolver = new SubjectBreadcrumbPathResolverService;
    $indexNode = new ReflectionMethod(SubjectBreadcrumbPathResolverService::class, 'indexNode');
    $pathsById = new ReflectionProperty(SubjectBreadcrumbPathResolverService::class, 'pathsById');

    $indexNode->setAccessible(true);
    $pathsById->setAccessible(true);

    $indexNode->invoke($resolver, [
        'id' => 'root',
        'text' => 'Platforms',
        'children' => [
            'skip-this-child',
            [
                'id' => 'platform-1',
                'text' => 'Satellite',
                'children' => [],
            ],
        ],
    ], 'Platforms', []);

    $paths = $pathsById->getValue($resolver);

    expect($paths['Platforms']['platform-1'])->toBe('Satellite');
});

it('does not guess a breadcrumb path from ambiguous leaf labels without a stable identifier', function (): void {
    Storage::disk('local')->put('gemet-thesaurus.json', json_encode([
        'data' => [[
            'id' => 'gemet-root-a',
            'text' => 'Environment',
            'scheme' => GemetVocabularyParser::SCHEME_TITLE,
            'children' => [[
                'id' => 'gemet-shared-a',
                'text' => 'Shared',
                'scheme' => GemetVocabularyParser::SCHEME_TITLE,
                'children' => [],
            ]],
        ], [
            'id' => 'gemet-root-b',
            'text' => 'Science',
            'scheme' => GemetVocabularyParser::SCHEME_TITLE,
            'children' => [[
                'id' => 'gemet-shared-b',
                'text' => 'Shared',
                'scheme' => GemetVocabularyParser::SCHEME_TITLE,
                'children' => [],
            ]],
        ]],
    ], JSON_THROW_ON_ERROR));

    $resolver = new SubjectBreadcrumbPathResolverService;

    expect($resolver->resolve(
        subjectScheme: GemetVocabularyParser::SCHEME_TITLE,
        valueUri: null,
        classificationCode: null,
        subjectValue: 'Shared',
    ))->toBeNull();
});

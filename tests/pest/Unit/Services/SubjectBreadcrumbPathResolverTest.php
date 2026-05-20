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
<?php

declare(strict_types=1);

use App\Services\Imports\Subjects\ImportedSubjectData;
use App\Services\Imports\Subjects\SubjectImportNormalizer;
use App\Support\GemetVocabularyParser;
use App\Support\PortalSubjectNormalizer;

covers(ImportedSubjectData::class, SubjectImportNormalizer::class);

beforeEach(function (): void {
    $this->normalizer = app(SubjectImportNormalizer::class);
});

it('classifies every supported subject scheme exactly once', function (): void {
    $gemet = new ImportedSubjectData(
        value: 'HUMAN HEALTH > pollution > water pollution',
        subjectScheme: GemetVocabularyParser::SCHEME_TITLE,
        schemeUri: 'http://www.eionet.europa.eu/gemet/concept/',
        valueUri: 'http://www.eionet.europa.eu/gemet/concept/8493',
    );
    $msl = new ImportedSubjectData(
        value: 'Material > sedimentary rock > coal',
        subjectScheme: 'EPOS MSL vocabulary',
        schemeUri: 'https://epos-msl.uu.nl/voc',
        valueUri: 'https://epos-msl.uu.nl/voc/material/coal',
    );

    $result = $this->normalizer->normalize([
        new ImportedSubjectData(value: ' Seismology '),
        new ImportedSubjectData(value: 'Seismology'),
        new ImportedSubjectData(
            value: 'Science Keywords > EARTH SCIENCE > SOLID EARTH > SEISMOLOGY',
            subjectScheme: 'NASA/GCMD Earth Science Keywords',
            valueUri: 'https://gcmd.earthdata.nasa.gov/kms/concept/11111111-1111-4111-8111-111111111111',
        ),
        new ImportedSubjectData(
            value: 'Platforms > LAND-BASED > FIELD SITE',
            subjectScheme: 'GCMD Platforms',
            valueUri: 'https://gcmd.earthdata.nasa.gov/kms/concept/22222222-2222-4222-8222-222222222222',
        ),
        new ImportedSubjectData(
            value: 'Instruments > SEISMOMETERS > BROADBAND',
            subjectScheme: 'GCMD Instruments',
            valueUri: 'https://gcmd.earthdata.nasa.gov/kms/concept/33333333-3333-4333-8333-333333333333',
        ),
        $msl,
        $msl,
        $gemet,
        $gemet,
        new ImportedSubjectData(
            value: 'Phanerozoic > Cenozoic',
            subjectScheme: 'Chronostratigraphy',
            valueUri: 'http://resource.geosciml.org/classifier/ics/ischart/Cenozoic',
        ),
        new ImportedSubjectData(
            value: 'Mass spectrometry',
            subjectScheme: 'Analytical methods vocabulary',
            valueUri: 'https://w3id.org/geochem/1.0/analyticalmethod/mass-spectrometry',
        ),
        new ImportedSubjectData(
            value: 'Geosciences',
            subjectScheme: 'EuroSciVoc',
            valueUri: 'http://data.europa.eu/8mn/euroscivoc/example',
        ),
        new ImportedSubjectData(
            value: 'Material > Historical rock',
            subjectScheme: 'CGI Simple Lithology',
        ),
        new ImportedSubjectData(
            value: 'Physics > Geophysics',
            subjectScheme: 'Custom Vocabulary',
            schemeUri: 'https://example.org/custom',
            classificationCode: 550,
            language: 'de',
        ),
    ]);

    expect($result->freeKeywords)->toBe(['Seismology'])
        ->and($result->controlledKeywords)->toHaveCount(10)
        ->and(collect($result->controlledKeywords)->pluck('scheme')->all())->toBe([
            'Science Keywords',
            'Platforms',
            'Instruments',
            'EPOS MSL vocabulary',
            GemetVocabularyParser::SCHEME_TITLE,
            PortalSubjectNormalizer::SCHEME_ICS_CHRONOSTRAT,
            PortalSubjectNormalizer::SCHEME_ANALYTICAL_METHODS,
            'European Science Vocabulary (EuroSciVoc)',
            PortalSubjectNormalizer::SCHEME_SIMPLE_LITHOLOGY,
            'Custom Vocabulary',
        ])
        ->and($result->controlledKeywords[0]['path'])->toBe('EARTH SCIENCE > SOLID EARTH > SEISMOLOGY')
        ->and($result->controlledKeywords[0]['text'])->toBe('SEISMOLOGY')
        ->and($result->controlledKeywords[3]['text'])->toBe('coal')
        ->and($result->controlledKeywords[4]['text'])->toBe('water pollution')
        ->and($result->controlledKeywords[9]['classificationCode'])->toBe('550')
        ->and($result->controlledKeywords[9]['language'])->toBe('de');
});

it('preserves controlled subjects that differ in persisted metadata', function (): void {
    $result = $this->normalizer->normalize([
        new ImportedSubjectData(
            value: 'Environment > Air pollution',
            subjectScheme: GemetVocabularyParser::SCHEME_TITLE,
            valueUri: 'http://www.eionet.europa.eu/gemet/concept/197',
            language: 'en',
        ),
        new ImportedSubjectData(
            value: 'Environment > Air pollution',
            subjectScheme: GemetVocabularyParser::SCHEME_TITLE,
            valueUri: 'http://www.eionet.europa.eu/gemet/concept/197',
            language: 'de',
        ),
    ]);

    expect($result->controlledKeywords)->toHaveCount(2)
        ->and(array_column($result->controlledKeywords, 'language'))->toBe(['en', 'de']);
});

it('rejects incomplete specialized and invalid GCMD controlled subjects', function (): void {
    $result = $this->normalizer->normalize([
        new ImportedSubjectData(value: 'water pollution', subjectScheme: GemetVocabularyParser::SCHEME_TITLE),
        new ImportedSubjectData(value: 'Rock', subjectScheme: 'EPOS MSL vocabulary'),
        new ImportedSubjectData(
            value: 'EARTH SCIENCE > OCEANS',
            subjectScheme: 'Science Keywords',
            valueUri: 'https://example.org/not-a-gcmd-uuid',
        ),
        new ImportedSubjectData(value: 'Unidentified', subjectScheme: 'Custom Vocabulary'),
    ]);

    expect($result->controlledKeywords)->toBeEmpty();
});

it('keeps GEMET out of the general compatibility view without changing the canonical list', function (): void {
    $result = $this->normalizer->normalize([
        new ImportedSubjectData(
            value: 'Material > Rock',
            subjectScheme: 'EPOS MSL vocabulary',
            valueUri: 'https://epos-msl.uu.nl/voc/rock',
        ),
        new ImportedSubjectData(
            value: 'Environment > Air pollution',
            subjectScheme: GemetVocabularyParser::SCHEME_TITLE,
            valueUri: 'http://www.eionet.europa.eu/gemet/concept/197',
        ),
    ]);

    $legacy = $result->legacyKeywordPayload();

    expect($result->controlledKeywords)->toHaveCount(2)
        ->and($legacy['gcmdKeywords'])->toHaveCount(1)
        ->and($legacy['mslKeywords'])->toHaveCount(1)
        ->and($legacy['gemetKeywords'])->toHaveCount(1);
});

<?php

declare(strict_types=1);

use App\Services\SubjectEnrichment\SubjectEnrichmentMatcher;
use App\Services\SubjectEnrichment\SubjectEnrichmentMatchInput;
use App\Services\SubjectEnrichment\SubjectVocabularyLookupService;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * @param  array<string, mixed>  $data
 */
function matcherMissingPutVocabulary(string $fileName, array $data): void
{
    Storage::disk('local')->put($fileName, json_encode($data, JSON_THROW_ON_ERROR));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function matcherMissingInput(array $overrides = []): SubjectEnrichmentMatchInput
{
    return new SubjectEnrichmentMatchInput(...array_replace([
        'resourceId' => 1,
        'targetType' => 'subject',
        'targetId' => 11,
        'value' => 'keyword',
        'subjectScheme' => null,
        'normalizedSubjectScheme' => null,
        'schemeUri' => null,
        'valueUri' => null,
        'classificationCode' => null,
        'breadcrumbPath' => null,
        'language' => 'en',
        'isControlled' => false,
    ], $overrides));
}

function matcherMissingService(): SubjectEnrichmentMatcher
{
    return new SubjectEnrichmentMatcher(new SubjectVocabularyLookupService);
}

it('suppresses free-text inputs that normalize to an empty value', function (): void {
    $result = matcherMissingService()->match(matcherMissingInput([
        'value' => '   ',
    ]));

    expect($result->status)->toBe('suppressed')
        ->and($result->suppressionReasons)->toBe(['empty_subject_value']);
});

it('matches controlled subjects when only the value URI update is missing', function (): void {
    matcherMissingPutVocabulary('gcmd-science-keywords.json', [
        'data' => [
            [
                'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/0e916c3b-d9ac-4fe1-bc7c-18772784f7fb',
                'text' => 'PARTICULATE MATTER',
                'scheme' => 'NASA/GCMD Earth Science Keywords',
                'schemeURI' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
                'children' => [],
            ],
        ],
    ]);

    $result = matcherMissingService()->match(matcherMissingInput([
        'value' => 'PARTICULATE MATTER',
        'subjectScheme' => 'Science Keywords',
        'normalizedSubjectScheme' => 'Science Keywords',
        'schemeUri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
        'breadcrumbPath' => 'PARTICULATE MATTER',
        'isControlled' => true,
    ]));

    expect($result->status)->toBe('matched')
        ->and($result->concept?->valueUri())->toBe('https://gcmd.earthdata.nasa.gov/kms/concept/0e916c3b-d9ac-4fe1-bc7c-18772784f7fb');
});

it('matches controlled subjects when only the classification code update is missing', function (): void {
    matcherMissingPutVocabulary('analytical-methods.json', [
        'data' => [
            [
                'id' => 'icp-ms-local-id',
                'text' => 'ICP-MS',
                'notation' => 'ICP-MS',
                'scheme' => 'Analytical Methods for Geochemistry and Cosmochemistry',
                'schemeURI' => 'https://w3id.org/geochem/1.0/analyticalmethod/method',
                'children' => [],
            ],
        ],
    ]);

    $result = matcherMissingService()->match(matcherMissingInput([
        'value' => 'ICP-MS',
        'subjectScheme' => 'Analytical Methods for Geochemistry and Cosmochemistry',
        'normalizedSubjectScheme' => 'Analytical Methods for Geochemistry and Cosmochemistry',
        'schemeUri' => 'https://w3id.org/geochem/1.0/analyticalmethod/method',
        'breadcrumbPath' => 'ICP-MS',
        'isControlled' => true,
    ]));

    expect($result->status)->toBe('matched')
        ->and($result->concept?->classificationCode)->toBe('ICP-MS');
});

it('matches controlled subjects when only the breadcrumb path update is missing', function (): void {
    matcherMissingPutVocabulary('msl-vocabulary.json', [
        'data' => [
            [
                'id' => 'rock-local-id',
                'text' => 'Rock',
                'scheme' => 'EPOS MSL vocabulary',
                'schemeURI' => 'https://epos-msl.uu.nl/voc',
                'children' => [],
            ],
        ],
    ]);

    $result = matcherMissingService()->match(matcherMissingInput([
        'value' => 'Rock',
        'subjectScheme' => 'EPOS MSL vocabulary',
        'normalizedSubjectScheme' => 'EPOS MSL vocabulary',
        'schemeUri' => 'https://epos-msl.uu.nl/voc',
        'isControlled' => true,
    ]));

    expect($result->status)->toBe('matched')
        ->and($result->concept?->path)->toBe('Rock');
});

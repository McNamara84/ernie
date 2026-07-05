<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\Subject;
use App\Services\SubjectEnrichment\SubjectEnrichmentDiscoveryService;
use App\Services\SubjectEnrichment\SubjectEnrichmentMatcher;
use App\Services\SubjectEnrichment\SubjectEnrichmentMatchInputProvider;
use App\Services\SubjectEnrichment\SubjectVocabularyLookupService;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * @param  array<string, mixed>  $data
 */
function discoveryEdgePutVocabulary(string $fileName, array $data): void
{
    Storage::disk('local')->put($fileName, json_encode($data, JSON_THROW_ON_ERROR));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function discoveryEdgeCreateSubject(Resource $resource, array $attributes): Subject
{
    return Subject::forceCreate(array_replace([
        'resource_id' => $resource->id,
        'value' => 'keyword',
        'language' => 'en',
        'subject_scheme' => null,
        'scheme_uri' => null,
        'value_uri' => null,
        'classification_code' => null,
        'breadcrumb_path' => null,
    ], $attributes));
}

function discoveryEdgeService(): SubjectEnrichmentDiscoveryService
{
    $lookup = new SubjectVocabularyLookupService;

    return new SubjectEnrichmentDiscoveryService(
        inputProvider: new SubjectEnrichmentMatchInputProvider,
        matcher: new SubjectEnrichmentMatcher($lookup),
        lookup: $lookup,
    );
}

/**
 * @param  Closure(int, string, int, string, string, float|null, array<string, mixed>|null): bool|null  $storeSuggestion
 * @return array{0: int, 1: list<array<string, mixed>>, 2: list<string>}
 */
function discoveryEdgeRun(?Closure $storeSuggestion = null): array
{
    $storedSuggestions = [];
    $progressMessages = [];

    $count = discoveryEdgeService()->discover(
        storeSuggestion: $storeSuggestion ?? function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$storedSuggestions): bool {
            $storedSuggestions[] = compact(
                'resourceId',
                'targetType',
                'targetId',
                'suggestedValue',
                'suggestedLabel',
                'similarityScore',
                'metadata',
            );

            return true;
        },
        onProgress: function (string $message) use (&$progressMessages): void {
            $progressMessages[] = $message;
        },
    );

    return [$count, $storedSuggestions, $progressMessages];
}

it('returns early when no eligible subject inputs exist', function (): void {
    [$count, $storedSuggestions, $progressMessages] = discoveryEdgeRun();

    expect($count)->toBe(0)
        ->and($storedSuggestions)->toBeEmpty()
        ->and(implode("\n", $progressMessages))->toContain('No eligible subject keywords found.');
});

it('does not count suggestions rejected by storage callback', function (): void {
    discoveryEdgePutVocabulary('msl-vocabulary.json', [
        'data' => [
            [
                'id' => 'https://epos-msl.uu.nl/voc/multi-scale-laboratories',
                'text' => 'multi-scale laboratories',
                'scheme' => 'EPOS MSL vocabulary',
                'schemeURI' => 'https://epos-msl.uu.nl/voc',
                'children' => [],
            ],
        ],
    ]);
    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81320')->create();
    discoveryEdgeCreateSubject($resource, ['value' => 'multi-scale laboratories']);

    [$count, $storedSuggestions, $progressMessages] = discoveryEdgeRun(fn (): bool => false);

    expect($count)->toBe(0)
        ->and($storedSuggestions)->toBeEmpty()
        ->and(implode("\n", $progressMessages))->toContain('Stored 0 subject enrichment suggestion(s); suppressed 0 subject(s).');
});

it('suppresses matched concepts when no stable suggested value can be built', function (): void {
    config(['euroscivoc.concept_scheme_uri' => null]);
    discoveryEdgePutVocabulary('euroscivoc.json', [
        'data' => [
            [
                'id' => 'euroscivoc-local-id',
                'text' => 'rare field',
                'scheme' => 'European Science Vocabulary (EuroSciVoc)',
                'children' => [],
            ],
        ],
    ]);
    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81321')->create();
    discoveryEdgeCreateSubject($resource, ['value' => 'rare field']);

    [$count, $storedSuggestions, $progressMessages] = discoveryEdgeRun();

    expect($count)->toBe(0)
        ->and($storedSuggestions)->toBeEmpty()
        ->and(implode("\n", $progressMessages))->toContain('no stable suggested value');
});

it('uses classification code as suggested value when the matched concept has no URL identifier', function (): void {
    discoveryEdgePutVocabulary('analytical-methods.json', [
        'lastUpdated' => '2026-07-04T00:00:00Z',
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
    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81322')->create();
    discoveryEdgeCreateSubject($resource, [
        'value' => 'Inductively coupled plasma mass spectrometry',
        'subject_scheme' => 'Analytical Methods for Geochemistry and Cosmochemistry',
        'classification_code' => 'ICP-MS',
    ]);

    [$count, $storedSuggestions] = discoveryEdgeRun();

    expect($count)->toBe(1)
        ->and($storedSuggestions[0]['suggestedValue'])->toBe('ICP-MS')
        ->and($storedSuggestions[0]['metadata']['match']['strategy'])->toBe('exact_notation')
        ->and($storedSuggestions[0]['metadata']['match']['input'])->toBe('ICP-MS')
        ->and($storedSuggestions[0]['metadata']['proposed']['value_uri'])->toBeNull()
        ->and($storedSuggestions[0]['metadata']['proposed']['classification_code'])->toBe('ICP-MS');
});

it('records value URI match input when controlled discovery matches by URI', function (): void {
    $schemeUri = 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords';
    discoveryEdgePutVocabulary('gcmd-science-keywords.json', [
        'data' => [
            [
                'id' => 'https://cmr.earthdata.nasa.gov/kms/concept/0e916c3b-d9ac-4fe1-bc7c-18772784f7fb',
                'text' => 'PARTICULATE MATTER',
                'scheme' => 'NASA/GCMD Earth Science Keywords',
                'schemeURI' => $schemeUri,
                'children' => [],
            ],
        ],
    ]);
    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.81323')->create();
    discoveryEdgeCreateSubject($resource, [
        'value' => 'not a path',
        'subject_scheme' => 'NASA/GCMD Earth Science Keywords',
        'value_uri' => 'https://cmr.earthdata.nasa.gov/kms/concept/0e916c3b-d9ac-4fe1-bc7c-18772784f7fb',
    ]);

    [$count, $storedSuggestions] = discoveryEdgeRun();

    expect($count)->toBe(1)
        ->and($storedSuggestions[0]['metadata']['match']['strategy'])->toBe('exact_value_uri')
        ->and($storedSuggestions[0]['metadata']['match']['input'])->toBe('https://cmr.earthdata.nasa.gov/kms/concept/0e916c3b-d9ac-4fe1-bc7c-18772784f7fb');
});

<?php

declare(strict_types=1);

use App\Services\SubjectEnrichment\SubjectVocabularyLookupService;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * @param  array<mixed>  $data
 */
function lookupEdgePutJson(string $fileName, array $data): void
{
    Storage::disk('local')->put($fileName, json_encode($data, JSON_THROW_ON_ERROR));
}

it('reports source metadata for missing supported caches and rejects unsupported schemes', function (): void {
    $lookup = new SubjectVocabularyLookupService;

    expect($lookup->normalizeSupportedScheme('NASA/GCMD Earth Science Keywords'))->toBe('Science Keywords')
        ->and($lookup->normalizeSupportedScheme('unknown scheme'))->toBeNull()
        ->and($lookup->canonicalSubjectScheme('Science Keywords'))->toBe('GCMD Science Keywords')
        ->and($lookup->canonicalSubjectScheme('Platforms'))->toBe('GCMD Platforms')
        ->and($lookup->canonicalSubjectScheme('Instruments'))->toBe('GCMD Instruments')
        ->and($lookup->canonicalSubjectScheme('EPOS MSL vocabulary'))->toBe('EPOS MSL vocabulary')
        ->and($lookup->canonicalSubjectScheme('unknown scheme'))->toBeNull()
        ->and($lookup->canonicalSchemeUri('unknown scheme'))->toBeNull()
        ->and($lookup->isSchemeAvailable('unknown scheme'))->toBeFalse()
        ->and($lookup->isSchemeAvailable('Science Keywords'))->toBeFalse();

    $source = $lookup->sourceForScheme('Science Keywords');

    expect($source)->not->toBeNull()
        ->and($source?->localCacheFile)->toBe('gcmd-science-keywords.json')
        ->and($source?->localCacheUpdatedAt)->toBeNull();
});

it('treats invalid JSON as unavailable and empty vocabularies as available without candidates', function (): void {
    Storage::disk('local')->put('gcmd-science-keywords.json', '{ invalid json');
    $invalid = new SubjectVocabularyLookupService;

    lookupEdgePutJson('msl-vocabulary.json', ['lastUpdated' => '2026-07-04T00:00:00Z', 'data' => []]);
    $empty = new SubjectVocabularyLookupService;

    expect($invalid->isSchemeAvailable('Science Keywords'))->toBeFalse()
        ->and($empty->isSchemeAvailable('EPOS MSL vocabulary'))->toBeTrue()
        ->and($empty->findGlobalExactLabel('anything')->isEmpty())->toBeTrue();
});

it('parses list-root vocabularies, alternate node fields, synonyms, numeric labels, and invalid child containers', function (): void {
    lookupEdgePutJson('msl-vocabulary.json', [
        [
            'uri' => 'https://epos-msl.uu.nl/voc/preferred',
            'label' => 'Preferred Label',
            'scheme' => 'EPOS MSL vocabulary',
            'schemeUri' => 'https://epos-msl.uu.nl/voc',
            'altLabels' => [
                'Alternate String',
                ['label' => 'Alternate Array Label'],
                ['ignored' => 'missing label'],
                123,
            ],
            'children' => 'not-an-array',
        ],
    ]);

    $lookup = new SubjectVocabularyLookupService;
    $preferred = $lookup->findGlobalExactLabel('Preferred Label');
    $alternate = $lookup->findGlobalExactLabel('Alternate Array Label');
    $numeric = $lookup->findGlobalExactLabel('123');

    expect($preferred->isUnique())->toBeTrue()
        ->and($preferred->sole()?->id)->toBe('https://epos-msl.uu.nl/voc/preferred')
        ->and($alternate->isUnique())->toBeTrue()
        ->and($numeric->isUnique())->toBeTrue();
});

it('skips non-array roots and reads node names from fallback keys', function (): void {
    lookupEdgePutJson('msl-vocabulary.json', [
        'data' => [
            'not-a-node',
            [
                '@id' => 'https://epos-msl.uu.nl/voc/fallback-name',
                'name' => 'Fallback Name',
                'scheme' => 'EPOS MSL vocabulary',
                'schemeURI' => 'https://epos-msl.uu.nl/voc',
                'children' => [],
            ],
        ],
    ]);

    $match = (new SubjectVocabularyLookupService)->findGlobalExactLabel('Fallback Name');

    expect($match->isUnique())->toBeTrue()
        ->and($match->sole()?->valueUri())->toBe('https://epos-msl.uu.nl/voc/fallback-name');
});

it('uses configured EuroSciVoc scheme and registry URIs when source nodes omit scheme URIs', function (): void {
    config([
        'euroscivoc.concept_scheme_uri' => 'http://data.europa.eu/8mn/euroscivoc/test-scheme',
        'euroscivoc.download_url' => 'https://example.test/euroscivoc.rdf',
    ]);
    lookupEdgePutJson('euroscivoc.json', [
        'version' => '2026-test',
        'last_updated' => '2026-07-04T00:00:00Z',
        'data' => [
            [
                'id' => 'http://data.europa.eu/8mn/euroscivoc/concept/test-field',
                'prefLabel' => 'Test Field',
                'scheme' => 'European Science Vocabulary (EuroSciVoc)',
                'children' => [],
            ],
        ],
    ]);

    $lookup = new SubjectVocabularyLookupService;
    $source = $lookup->sourceForScheme('European Science Vocabulary (EuroSciVoc)');
    $match = $lookup->findGlobalExactLabel('Test Field');

    expect($source?->schemeUri)->toBe('http://data.europa.eu/8mn/euroscivoc/test-scheme')
        ->and($source?->sourceRegistryUrl)->toBe('https://example.test/euroscivoc.rdf')
        ->and($source?->version)->toBe('2026-test')
        ->and($match->sole()?->schemeUri)->toBe('http://data.europa.eu/8mn/euroscivoc/test-scheme');
});

it('indexes GCMD platform and instrument concept URI variants', function (): void {
    $platformUuid = '11111111-1111-4111-8111-111111111111';
    $instrumentUuid = '22222222-2222-4222-8222-222222222222';

    lookupEdgePutJson('gcmd-platforms.json', [
        'data' => [
            [
                'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/'.$platformUuid,
                'text' => 'Satellite',
                'scheme' => 'Platforms',
                'schemeURI' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/platforms',
                'children' => [],
            ],
        ],
    ]);
    lookupEdgePutJson('gcmd-instruments.json', [
        'data' => [
            [
                'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/'.$instrumentUuid,
                'text' => 'Spectrometer',
                'scheme' => 'Instruments',
                'schemeURI' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/instruments',
                'children' => [],
            ],
        ],
    ]);

    $lookup = new SubjectVocabularyLookupService;
    $platform = $lookup->findById(
        'Platforms',
        'https://gcmd.earthdata.nasa.gov/kms/concept/'.$platformUuid,
    );
    $instrument = $lookup->findById(
        'Instruments',
        'https://gcmd.earthdata.nasa.gov/kms/concept/'.$instrumentUuid,
    );

    expect($platform->isUnique())->toBeTrue()
        ->and($instrument->isUnique())->toBeTrue();
});

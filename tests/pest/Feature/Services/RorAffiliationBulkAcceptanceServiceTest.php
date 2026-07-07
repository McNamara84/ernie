<?php

declare(strict_types=1);

use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\SuggestedRor;
use App\Services\DataCiteServiceInterface;
use App\Services\DataCiteSyncResult;
use App\Services\DataCiteSyncService;
use App\Services\RorAffiliationBulkAcceptanceService;
use App\Services\RorDiscoveryService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

covers(RorAffiliationBulkAcceptanceService::class, RorDiscoveryService::class);

beforeEach(function (): void {
    Cache::flush();
    app()->instance(DataCiteSyncService::class, new class(app(DataCiteServiceInterface::class)) extends DataCiteSyncService
    {
        public function syncIfRegistered(Resource $resource): DataCiteSyncResult
        {
            if ($resource->doi === null || $resource->doi === '') {
                return DataCiteSyncResult::notRequired();
            }

            return DataCiteSyncResult::succeeded((string) $resource->doi);
        }
    });
});

function createRorCreatorAffiliationSuggestion(
    string $familyName,
    string $givenName,
    string $affiliationName,
    string $rorId = 'https://ror.org/04z8jg394',
): array {
    $resource = Resource::factory()->create();
    $person = Person::factory()->create([
        'family_name' => $familyName,
        'given_name' => $givenName,
    ]);
    $creator = ResourceCreator::create([
        'resource_id' => $resource->id,
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'position' => 1,
    ]);
    $affiliation = $creator->affiliations()->create([
        'name' => $affiliationName,
        'identifier' => null,
        'identifier_scheme' => null,
        'scheme_uri' => null,
    ]);
    $suggestion = SuggestedRor::create([
        'resource_id' => $resource->id,
        'entity_type' => 'affiliation',
        'entity_id' => $affiliation->id,
        'entity_name' => $affiliationName,
        'suggested_ror_id' => $rorId,
        'suggested_name' => 'GFZ German Research Centre for Geosciences',
        'similarity_score' => 0.98,
        'ror_aliases' => [],
        'existing_identifier' => null,
        'existing_identifier_type' => null,
        'discovered_at' => now(),
    ]);

    return compact('resource', 'person', 'creator', 'affiliation', 'suggestion');
}

function createRorInstitutionCreatorAffiliationSuggestion(
    string $institutionName,
    string $affiliationName,
    string $rorId = 'https://ror.org/04z8jg394',
): array {
    $resource = Resource::factory()->create();
    $institution = Institution::factory()->create([
        'name' => $institutionName,
        'name_identifier' => null,
        'name_identifier_scheme' => null,
        'scheme_uri' => null,
    ]);
    $creator = ResourceCreator::create([
        'resource_id' => $resource->id,
        'creatorable_type' => Institution::class,
        'creatorable_id' => $institution->id,
        'position' => 1,
    ]);
    $affiliation = $creator->affiliations()->create([
        'name' => $affiliationName,
        'identifier' => null,
        'identifier_scheme' => null,
        'scheme_uri' => null,
    ]);
    $suggestion = SuggestedRor::create([
        'resource_id' => $resource->id,
        'entity_type' => 'affiliation',
        'entity_id' => $affiliation->id,
        'entity_name' => $affiliationName,
        'suggested_ror_id' => $rorId,
        'suggested_name' => 'GFZ German Research Centre for Geosciences',
        'similarity_score' => 0.98,
        'ror_aliases' => [],
        'existing_identifier' => null,
        'existing_identifier_type' => null,
        'discovered_at' => now(),
    ]);

    return compact('resource', 'institution', 'creator', 'affiliation', 'suggestion');
}

function createRorContributorAffiliationSuggestion(
    string $familyName,
    string $givenName,
    string $affiliationName,
    string $rorId = 'https://ror.org/04z8jg394',
): array {
    $resource = Resource::factory()->create();
    $person = Person::factory()->create([
        'family_name' => $familyName,
        'given_name' => $givenName,
    ]);
    $contributor = ResourceContributor::create([
        'resource_id' => $resource->id,
        'contributorable_type' => Person::class,
        'contributorable_id' => $person->id,
        'position' => 1,
    ]);
    $affiliation = $contributor->affiliations()->create([
        'name' => $affiliationName,
        'identifier' => null,
        'identifier_scheme' => null,
        'scheme_uri' => null,
    ]);
    $suggestion = SuggestedRor::create([
        'resource_id' => $resource->id,
        'entity_type' => 'affiliation',
        'entity_id' => $affiliation->id,
        'entity_name' => $affiliationName,
        'suggested_ror_id' => $rorId,
        'suggested_name' => 'GFZ German Research Centre for Geosciences',
        'similarity_score' => 0.98,
        'ror_aliases' => [],
        'existing_identifier' => null,
        'existing_identifier_type' => null,
        'discovered_at' => now(),
    ]);

    return compact('resource', 'person', 'contributor', 'affiliation', 'suggestion');
}

it('returns a bulk preview only for exact creator name and affiliation matches', function (): void {
    $source = createRorCreatorAffiliationSuggestion('Doe', 'Jane', 'GFZ Potsdam');
    createRorCreatorAffiliationSuggestion('Doe', 'Jane', 'GFZ Potsdam');
    createRorCreatorAffiliationSuggestion('Doe', 'Janet', 'GFZ Potsdam');
    createRorCreatorAffiliationSuggestion('Doe', 'Jane', 'GFZ Potsdam ');
    createRorCreatorAffiliationSuggestion('Doe', 'Jane', 'GFZ Potsdam', 'https://ror.org/03yrm5c26');
    createRorContributorAffiliationSuggestion('Doe', 'Jane', 'GFZ Potsdam');

    $result = app(RorDiscoveryService::class)->acceptRor($source['suggestion']);

    expect($result['success'])->toBeTrue()
        ->and($result['bulk_affiliation_match'])->toMatchArray([
            'available' => true,
            'count' => 1,
            'creator_name' => 'Doe, Jane',
            'affiliation' => 'GFZ Potsdam',
            'suggested_ror_id' => 'https://ror.org/04z8jg394',
        ])
        ->and($result['bulk_affiliation_match']['bulk_token'])->toBeString()->not->toBe('');

    expect($source['affiliation']->refresh())
        ->identifier->toBe('https://ror.org/04z8jg394')
        ->identifier_scheme->toBe('ROR')
        ->name->toBe('GFZ Potsdam')
        ->and($source['person']->refresh()->name_identifier)->toBeNull();
});

it('builds bulk previews with batched candidate context queries', function (): void {
    $source = createRorCreatorAffiliationSuggestion('Franklin', 'Rosalind', 'Batch Institute');

    for ($i = 0; $i < 8; $i++) {
        createRorCreatorAffiliationSuggestion('Franklin', 'Rosalind', 'Batch Institute');
        createRorCreatorAffiliationSuggestion('Franklin', 'Rose', 'Batch Institute');
        createRorCreatorAffiliationSuggestion('Franklin', 'Rosalind', 'Other Institute');
        createRorContributorAffiliationSuggestion('Franklin', 'Rosalind', 'Batch Institute');
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $preview = app(RorAffiliationBulkAcceptanceService::class)->createPreviewForAcceptedSuggestion($source['suggestion']);

    $selectQueryCount = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select'))
        ->count();

    DB::disableQueryLog();
    DB::flushQueryLog();

    expect($preview)->toMatchArray([
        'available' => true,
        'count' => 8,
    ])
        ->and($selectQueryCount)->toBeLessThanOrEqual(10);
});
it('uses exact exported institution creator names without changing creator identifiers', function (): void {
    $source = createRorInstitutionCreatorAffiliationSuggestion('GFZ Data Services', 'Shared Hosting Unit');
    $match = createRorInstitutionCreatorAffiliationSuggestion('GFZ Data Services', 'Shared Hosting Unit');
    createRorInstitutionCreatorAffiliationSuggestion('GFZ Data Service', 'Shared Hosting Unit');

    $singleResult = app(RorDiscoveryService::class)->acceptRor($source['suggestion']);

    expect($singleResult['bulk_affiliation_match'])->toMatchArray([
        'count' => 1,
        'creator_name' => 'GFZ Data Services',
        'affiliation' => 'Shared Hosting Unit',
    ]);

    $bulkResult = app(RorDiscoveryService::class)->acceptMatchingAffiliationRors($singleResult['bulk_affiliation_match']['bulk_token']);

    expect($bulkResult['accepted_count'])->toBe(1)
        ->and($match['affiliation']->refresh()->identifier)->toBe('https://ror.org/04z8jg394')
        ->and($source['institution']->refresh()->name_identifier)->toBeNull()
        ->and($match['institution']->refresh()->name_identifier)->toBeNull();
});

it('accepts all still-valid bulk matches and removes their suggestions', function (): void {
    $source = createRorCreatorAffiliationSuggestion('Curie', 'Marie', 'Exact Institute');
    $matchA = createRorCreatorAffiliationSuggestion('Curie', 'Marie', 'Exact Institute');
    $matchB = createRorCreatorAffiliationSuggestion('Curie', 'Marie', 'Exact Institute');

    $singleResult = app(RorDiscoveryService::class)->acceptRor($source['suggestion']);
    $bulkToken = $singleResult['bulk_affiliation_match']['bulk_token'];

    $bulkResult = app(RorDiscoveryService::class)->acceptMatchingAffiliationRors($bulkToken);

    expect($bulkResult)->toMatchArray([
        'success' => true,
        'accepted_count' => 2,
        'skipped_count' => 0,
    ]);

    foreach ([$matchA['affiliation'], $matchB['affiliation']] as $affiliation) {
        expect($affiliation->refresh())
            ->identifier->toBe('https://ror.org/04z8jg394')
            ->identifier_scheme->toBe('ROR')
            ->scheme_uri->toBe('https://ror.org/')
            ->name->toBe('Exact Institute');
    }

    expect(SuggestedRor::whereIn('id', [
        $source['suggestion']->id,
        $matchA['suggestion']->id,
        $matchB['suggestion']->id,
    ])->count())->toBe(0);
});

it('removes all suggestions for a bulk match whose creator morph target is missing', function (): void {
    $source = createRorCreatorAffiliationSuggestion('Lovelace', 'Ada', 'Analytical Engine Lab');
    $match = createRorCreatorAffiliationSuggestion('Lovelace', 'Ada', 'Analytical Engine Lab');
    $staleAlternative = SuggestedRor::create([
        'resource_id' => $match['resource']->id,
        'entity_type' => 'affiliation',
        'entity_id' => $match['affiliation']->id,
        'entity_name' => 'Analytical Engine Lab',
        'suggested_ror_id' => 'https://ror.org/03yrm5c26',
        'suggested_name' => 'Alternative Research Centre',
        'similarity_score' => 0.74,
        'ror_aliases' => [],
        'existing_identifier' => null,
        'existing_identifier_type' => null,
        'discovered_at' => now(),
    ]);

    $singleResult = app(RorDiscoveryService::class)->acceptRor($source['suggestion']);
    $match['person']->delete();

    $bulkResult = app(RorDiscoveryService::class)->acceptMatchingAffiliationRors($singleResult['bulk_affiliation_match']['bulk_token']);

    expect($bulkResult)->toMatchArray([
        'success' => false,
        'accepted_count' => 0,
        'skipped_count' => 1,
    ])
        ->and(SuggestedRor::find($match['suggestion']->id))->toBeNull()
        ->and(SuggestedRor::find($staleAlternative->id))->toBeNull()
        ->and(SuggestedRor::where('entity_type', 'affiliation')->where('entity_id', $match['affiliation']->id)->count())->toBe(0);
});

it('removes all suggestions for a bulk match whose creator morph target is unexpected', function (): void {
    $source = createRorCreatorAffiliationSuggestion('Johnson', 'Katherine', 'Orbital Mechanics Lab');
    $match = createRorCreatorAffiliationSuggestion('Johnson', 'Katherine', 'Orbital Mechanics Lab');
    $staleAlternative = SuggestedRor::create([
        'resource_id' => $match['resource']->id,
        'entity_type' => 'affiliation',
        'entity_id' => $match['affiliation']->id,
        'entity_name' => 'Orbital Mechanics Lab',
        'suggested_ror_id' => 'https://ror.org/03yrm5c26',
        'suggested_name' => 'Alternative Research Centre',
        'similarity_score' => 0.74,
        'ror_aliases' => [],
        'existing_identifier' => null,
        'existing_identifier_type' => null,
        'discovered_at' => now(),
    ]);

    $singleResult = app(RorDiscoveryService::class)->acceptRor($source['suggestion']);
    $match['creator']->update([
        'creatorable_type' => Resource::class,
        'creatorable_id' => $match['resource']->id,
    ]);

    $bulkResult = app(RorDiscoveryService::class)->acceptMatchingAffiliationRors($singleResult['bulk_affiliation_match']['bulk_token']);

    expect($bulkResult)->toMatchArray([
        'success' => false,
        'accepted_count' => 0,
        'skipped_count' => 1,
    ])
        ->and(SuggestedRor::find($match['suggestion']->id))->toBeNull()
        ->and(SuggestedRor::find($staleAlternative->id))->toBeNull()
        ->and(SuggestedRor::where('entity_type', 'affiliation')->where('entity_id', $match['affiliation']->id)->count())->toBe(0);
});
it('removes all suggestions for a bulk match whose affiliation already has a ROR', function (): void {
    $source = createRorCreatorAffiliationSuggestion('Hopper', 'Grace', 'Compiler Institute');
    $match = createRorCreatorAffiliationSuggestion('Hopper', 'Grace', 'Compiler Institute');
    $staleAlternative = SuggestedRor::create([
        'resource_id' => $match['resource']->id,
        'entity_type' => 'affiliation',
        'entity_id' => $match['affiliation']->id,
        'entity_name' => 'Compiler Institute',
        'suggested_ror_id' => 'https://ror.org/03yrm5c26',
        'suggested_name' => 'Alternative Research Centre',
        'similarity_score' => 0.74,
        'ror_aliases' => [],
        'existing_identifier' => null,
        'existing_identifier_type' => null,
        'discovered_at' => now(),
    ]);

    $singleResult = app(RorDiscoveryService::class)->acceptRor($source['suggestion']);
    $match['affiliation']->update([
        'identifier' => 'https://ror.org/03yrm5c26',
        'identifier_scheme' => 'ROR',
        'scheme_uri' => 'https://ror.org/',
    ]);

    $bulkResult = app(RorDiscoveryService::class)->acceptMatchingAffiliationRors($singleResult['bulk_affiliation_match']['bulk_token']);

    expect($bulkResult)->toMatchArray([
        'success' => false,
        'accepted_count' => 0,
        'skipped_count' => 1,
    ])
        ->and($match['affiliation']->refresh()->identifier)->toBe('https://ror.org/03yrm5c26')
        ->and(SuggestedRor::find($match['suggestion']->id))->toBeNull()
        ->and(SuggestedRor::find($staleAlternative->id))->toBeNull()
        ->and(SuggestedRor::where('entity_type', 'affiliation')->where('entity_id', $match['affiliation']->id)->count())->toBe(0);
});
it('keeps a valid bulk token and retries sync when DataCite returns a failed result', function (): void {
    $source = createRorCreatorAffiliationSuggestion('Meitner', 'Lise', 'Retryable Sync Institute');
    $match = createRorCreatorAffiliationSuggestion('Meitner', 'Lise', 'Retryable Sync Institute');

    $singleResult = app(RorDiscoveryService::class)->acceptRor($source['suggestion']);
    $bulkToken = $singleResult['bulk_affiliation_match']['bulk_token'];

    $failingSyncService = new class(app(DataCiteServiceInterface::class)) extends DataCiteSyncService
    {
        /** @var array<int, int> */
        public array $syncedResourceIds = [];

        public function syncIfRegistered(Resource $resource): DataCiteSyncResult
        {
            $this->syncedResourceIds[] = (int) $resource->id;

            return DataCiteSyncResult::failed((string) $resource->doi, 'DataCite unavailable');
        }
    };

    app()->instance(DataCiteSyncService::class, $failingSyncService);

    $result = app(RorDiscoveryService::class)->acceptMatchingAffiliationRors($bulkToken);

    expect($result)->toMatchArray([
        'success' => false,
        'accepted_count' => 1,
        'skipped_count' => 0,
        'synced_dois' => [],
        'retryable' => true,
    ])
        ->and($result['message'])->toContain('DataCite unavailable')
        ->and($failingSyncService->syncedResourceIds)->toBe([$match['resource']->id])
        ->and(Cache::has('ror_affiliation_bulk_accept:'.$bulkToken))->toBeTrue()
        ->and($match['affiliation']->refresh()->identifier)->toBe('https://ror.org/04z8jg394')
        ->and(SuggestedRor::find($match['suggestion']->id))->toBeNull();

    $retrySyncService = new class(app(DataCiteServiceInterface::class)) extends DataCiteSyncService
    {
        /** @var array<int, int> */
        public array $syncedResourceIds = [];

        public function syncIfRegistered(Resource $resource): DataCiteSyncResult
        {
            $this->syncedResourceIds[] = (int) $resource->id;

            return DataCiteSyncResult::succeeded((string) $resource->doi);
        }
    };

    app()->instance(DataCiteSyncService::class, $retrySyncService);

    $retryResult = app(RorDiscoveryService::class)->acceptMatchingAffiliationRors($bulkToken);

    expect($retryResult)->toMatchArray([
        'success' => true,
        'accepted_count' => 0,
        'skipped_count' => 0,
        'synced_dois' => [$match['resource']->doi],
    ])
        ->and($retrySyncService->syncedResourceIds)->toBe([$match['resource']->id])
        ->and(Cache::has('ror_affiliation_bulk_accept:'.$bulkToken))->toBeFalse();

    app()->forgetInstance(DataCiteSyncService::class);
});
it('keeps a valid bulk token and retries sync after processing fails before completion', function (): void {
    $source = createRorCreatorAffiliationSuggestion('Noether', 'Emmy', 'Retry Institute');
    $match = createRorCreatorAffiliationSuggestion('Noether', 'Emmy', 'Retry Institute');

    $singleResult = app(RorDiscoveryService::class)->acceptRor($source['suggestion']);
    $bulkToken = $singleResult['bulk_affiliation_match']['bulk_token'];

    app()->instance(DataCiteSyncService::class, new class(app(DataCiteServiceInterface::class)) extends DataCiteSyncService
    {
        public function syncIfRegistered(Resource $resource): DataCiteSyncResult
        {
            throw new RuntimeException('sync exploded');
        }
    });

    expect(fn () => app(RorDiscoveryService::class)->acceptMatchingAffiliationRors($bulkToken))
        ->toThrow(RuntimeException::class, 'sync exploded');

    expect(Cache::has('ror_affiliation_bulk_accept:'.$bulkToken))->toBeTrue()
        ->and($match['affiliation']->refresh()->identifier)->toBe('https://ror.org/04z8jg394')
        ->and(SuggestedRor::find($match['suggestion']->id))->toBeNull();

    $retrySyncService = new class(app(DataCiteServiceInterface::class)) extends DataCiteSyncService
    {
        /** @var array<int, int> */
        public array $syncedResourceIds = [];

        public function syncIfRegistered(Resource $resource): DataCiteSyncResult
        {
            $this->syncedResourceIds[] = (int) $resource->id;

            return DataCiteSyncResult::succeeded((string) $resource->doi);
        }
    };

    app()->instance(DataCiteSyncService::class, $retrySyncService);

    $retryResult = app(RorDiscoveryService::class)->acceptMatchingAffiliationRors($bulkToken);

    expect($retryResult)->toMatchArray([
        'success' => true,
        'accepted_count' => 0,
        'skipped_count' => 0,
        'synced_dois' => [$match['resource']->doi],
    ])
        ->and($retrySyncService->syncedResourceIds)->toBe([$match['resource']->id])
        ->and(Cache::has('ror_affiliation_bulk_accept:'.$bulkToken))->toBeFalse();

    app()->forgetInstance(DataCiteSyncService::class);
});

it('rejects expired or invalid bulk tokens without changing suggestions', function (): void {
    $match = createRorCreatorAffiliationSuggestion('Planck', 'Max', 'Still Pending');

    $result = app(RorDiscoveryService::class)->acceptMatchingAffiliationRors('missing-token');

    expect($result['success'])->toBeFalse()
        ->and($result['accepted_count'])->toBe(0)
        ->and($match['affiliation']->refresh()->identifier)->toBeNull()
        ->and(SuggestedRor::find($match['suggestion']->id))->not->toBeNull();
});

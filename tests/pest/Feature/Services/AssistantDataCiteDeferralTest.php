<?php

declare(strict_types=1);

use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\SuggestedOrcid;
use App\Models\SuggestedRor;
use App\Services\Assistance\ResourceEntityImpactResolverService;
use App\Services\DataCiteSyncService;
use App\Services\OrcidDiscoveryService;
use App\Services\OrcidService;
use App\Services\RorAffiliationBulkAcceptanceService;
use App\Services\RorDiscoveryService;
use Modules\Assistants\OrcidSuggestion\Assistant as OrcidSuggestionAssistant;
use Modules\Assistants\RorSuggestion\Assistant as RorSuggestionAssistant;

afterEach(function (): void {
    Mockery::close();
});

it('defers ORCID synchronization and returns every affected resource', function (): void {
    $origin = Resource::factory()->create();
    $affected = Resource::factory()->create();
    $person = Person::factory()->create();
    ResourceCreator::create([
        'resource_id' => $origin->id,
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'position' => 1,
    ]);
    ResourceContributor::create([
        'resource_id' => $affected->id,
        'contributorable_type' => Person::class,
        'contributorable_id' => $person->id,
        'position' => 1,
    ]);
    $suggestion = SuggestedOrcid::create([
        'resource_id' => $origin->id,
        'person_id' => $person->id,
        'suggested_orcid' => '0000-0001-5109-3700',
        'similarity_score' => 0.95,
        'candidate_first_name' => 'Jane',
        'candidate_last_name' => 'Doe',
        'candidate_affiliations' => [],
        'source_context' => 'creator',
        'discovered_at' => now(),
    ]);
    $syncService = Mockery::mock(DataCiteSyncService::class);
    $syncService->shouldNotReceive('syncIfRegistered');
    $service = new OrcidDiscoveryService(
        Mockery::mock(OrcidService::class),
        $syncService,
        app(ResourceEntityImpactResolverService::class),
    );

    $result = (new OrcidSuggestionAssistant($service))->acceptSuggestion($suggestion->id, [
        'defer_datacite_sync' => true,
    ]);

    expect($result)->toMatchArray([
        'success' => true,
        'synced_dois' => [],
        'datacite_sync_deferred' => true,
        'datacite_sync_resource_ids' => [$origin->id, $affected->id],
    ])->and($person->fresh()->name_identifier)->toBe('https://orcid.org/0000-0001-5109-3700');
});

it('defers ROR synchronization and returns every affected resource', function (): void {
    $origin = Resource::factory()->create();
    $affected = Resource::factory()->create();
    $institution = Institution::factory()->create([
        'name_identifier' => null,
        'name_identifier_scheme' => null,
        'scheme_uri' => null,
    ]);
    foreach ([$origin, $affected] as $resource) {
        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Institution::class,
            'creatorable_id' => $institution->id,
            'position' => 1,
        ]);
    }
    $suggestion = SuggestedRor::create([
        'resource_id' => $origin->id,
        'entity_type' => 'institution',
        'entity_id' => $institution->id,
        'entity_name' => $institution->name,
        'suggested_ror_id' => 'https://ror.org/04z8jg394',
        'suggested_name' => 'GFZ Helmholtz Centre for Geosciences',
        'similarity_score' => 0.98,
        'ror_aliases' => [],
        'locations' => [],
        'existing_identifier' => null,
        'existing_identifier_type' => null,
        'discovered_at' => now(),
    ]);
    $syncService = Mockery::mock(DataCiteSyncService::class);
    $syncService->shouldNotReceive('syncIfRegistered');
    $bulkAcceptanceService = Mockery::mock(RorAffiliationBulkAcceptanceService::class);
    $bulkAcceptanceService->shouldReceive('createPreviewForAcceptedSuggestion')
        ->once()
        ->andReturnNull();
    $service = new RorDiscoveryService(
        $syncService,
        $bulkAcceptanceService,
        app(ResourceEntityImpactResolverService::class),
    );

    $result = (new RorSuggestionAssistant($service))->acceptSuggestion($suggestion->id, [
        'defer_datacite_sync' => true,
    ]);

    expect($result)->toMatchArray([
        'success' => true,
        'synced_dois' => [],
        'datacite_sync_deferred' => true,
        'datacite_sync_resource_ids' => [$origin->id, $affected->id],
    ])->and($institution->fresh()->name_identifier)->toBe('https://ror.org/04z8jg394');
});

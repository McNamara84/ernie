<?php

declare(strict_types=1);

use App\Models\IdentifierType;
use App\Models\RelatedIdentifier;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\SuggestedRelation;
use App\Services\Citations\CitationLookupService;
use App\Services\Citations\RelatedIdentifierCitationLabelService;
use App\Services\DataCiteEventDataService;
use App\Services\DataCiteSyncResult;
use App\Services\DataCiteSyncService;
use App\Services\RelationDiscoveryService;
use App\Services\ScholExplorerService;
use Database\Seeders\IdentifierTypeSeeder;
use Database\Seeders\RelationTypeSeeder;
use Modules\Assistants\RelationSuggestion\Assistant as RelationSuggestionAssistant;

beforeEach(function (): void {
    test()->seed(IdentifierTypeSeeder::class);
    test()->seed(RelationTypeSeeder::class);
});

afterEach(function (): void {
    Mockery::close();
});

function relationAcceptanceService(
    DataCiteSyncService $syncService,
    RelatedIdentifierCitationLabelService $citationLabelService,
): RelationDiscoveryService {
    return new RelationDiscoveryService(
        Mockery::mock(ScholExplorerService::class),
        Mockery::mock(DataCiteEventDataService::class),
        Mockery::mock(CitationLookupService::class),
        $syncService,
        $citationLabelService,
    );
}

function relationAcceptanceSuggestion(Resource $resource, RelationType $relationType, string $identifier): SuggestedRelation
{
    return SuggestedRelation::create([
        'resource_id' => $resource->id,
        'identifier' => $identifier,
        'identifier_type_id' => IdentifierType::query()->where('slug', 'DOI')->value('id'),
        'relation_type_id' => $relationType->id,
        'source' => 'scholexplorer',
        'source_title' => 'Relation override test',
        'discovered_at' => now(),
    ]);
}

it('stores an active override instead of the suggested relation type', function (): void {
    $resource = Resource::factory()->create();
    $suggestedType = RelationType::query()->where('slug', 'Cites')->firstOrFail();
    $overrideType = RelationType::query()->where('slug', 'References')->firstOrFail();
    $suggestion = relationAcceptanceSuggestion($resource, $suggestedType, '10.5880/override.2026.001');
    $syncService = Mockery::mock(DataCiteSyncService::class);
    $syncService->shouldReceive('syncIfRegistered')
        ->once()
        ->with(Mockery::on(fn (Resource $candidate): bool => $candidate->is($resource)))
        ->andReturn(DataCiteSyncResult::notRequired());
    $citationLabelService = Mockery::mock(RelatedIdentifierCitationLabelService::class);
    $citationLabelService->shouldReceive('resolveBestEffort')->once()->andReturnNull();

    $result = relationAcceptanceService($syncService, $citationLabelService)
        ->acceptRelation($suggestion, $overrideType->id);
    $stored = RelatedIdentifier::query()->where('resource_id', $resource->id)->sole();

    expect($result['success'])->toBeTrue()
        ->and($stored->relation_type_id)->toBe($overrideType->id)
        ->and($stored->relation_type_id)->not->toBe($suggestedType->id)
        ->and(SuggestedRelation::find($suggestion->id))->toBeNull();
});

it('normalizes a validated numeric-string override before accepting', function (): void {
    $resource = Resource::factory()->create();
    $suggestedType = RelationType::query()->where('slug', 'Cites')->firstOrFail();
    $overrideType = RelationType::query()->where('slug', 'References')->firstOrFail();
    $suggestion = relationAcceptanceSuggestion($resource, $suggestedType, '10.5880/override.2026.string');
    $service = Mockery::mock(RelationDiscoveryService::class);
    $service->shouldReceive('acceptRelation')
        ->once()
        ->with(
            Mockery::on(fn (SuggestedRelation $candidate): bool => $candidate->is($suggestion)),
            $overrideType->id,
        )
        ->andReturn([
            'success' => true,
            'datacite_synced' => false,
            'message' => 'Accepted with override.',
        ]);

    $result = (new RelationSuggestionAssistant($service))->acceptSuggestion(
        $suggestion->id,
        ['relation_type_id' => (string) $overrideType->id],
    );

    expect($result['success'])->toBeTrue();
});

it('still rejects a non-integer string override before calling the service', function (): void {
    $resource = Resource::factory()->create();
    $suggestedType = RelationType::query()->where('slug', 'Cites')->firstOrFail();
    $suggestion = relationAcceptanceSuggestion($resource, $suggestedType, '10.5880/override.2026.invalid-string');
    $service = Mockery::mock(RelationDiscoveryService::class);
    $service->shouldNotReceive('acceptRelation');

    $result = (new RelationSuggestionAssistant($service))->acceptSuggestion(
        $suggestion->id,
        ['relation_type_id' => '42.5'],
    );

    expect($result)->toMatchArray([
        'success' => false,
        'datacite_synced' => false,
        'message' => 'The selected relation type is invalid.',
    ])->and(SuggestedRelation::find($suggestion->id))->not->toBeNull();
});

it('rejects an inactive override without mutating the suggestion', function (): void {
    $resource = Resource::factory()->create();
    $suggestedType = RelationType::query()->where('slug', 'Cites')->firstOrFail();
    $inactiveOverride = RelationType::query()->where('slug', 'References')->firstOrFail();
    $inactiveOverride->update(['is_active' => false]);
    $suggestion = relationAcceptanceSuggestion($resource, $suggestedType, '10.5880/override.2026.002');
    $syncService = Mockery::mock(DataCiteSyncService::class);
    $syncService->shouldNotReceive('syncIfRegistered');
    $citationLabelService = Mockery::mock(RelatedIdentifierCitationLabelService::class);
    $citationLabelService->shouldNotReceive('resolveBestEffort');

    $result = relationAcceptanceService($syncService, $citationLabelService)
        ->acceptRelation($suggestion, $inactiveOverride->id);

    expect($result)->toMatchArray([
        'success' => false,
        'datacite_synced' => false,
        'message' => 'The selected relation type is not available.',
    ])->and(SuggestedRelation::find($suggestion->id))->not->toBeNull()
        ->and(RelatedIdentifier::where('resource_id', $resource->id)->exists())->toBeFalse();
});

it('still accepts an inactive original type when no override is supplied', function (): void {
    $resource = Resource::factory()->create();
    $originalType = RelationType::query()->where('slug', 'Cites')->firstOrFail();
    $suggestion = relationAcceptanceSuggestion($resource, $originalType, '10.5880/override.2026.003');
    $originalType->update(['is_active' => false]);
    $syncService = Mockery::mock(DataCiteSyncService::class);
    $syncService->shouldReceive('syncIfRegistered')->once()->andReturn(DataCiteSyncResult::notRequired());
    $citationLabelService = Mockery::mock(RelatedIdentifierCitationLabelService::class);
    $citationLabelService->shouldReceive('resolveBestEffort')->once()->andReturnNull();

    $result = relationAcceptanceService($syncService, $citationLabelService)->acceptRelation($suggestion);

    expect($result['success'])->toBeTrue()
        ->and(RelatedIdentifier::where([
            'resource_id' => $resource->id,
            'relation_type_id' => $originalType->id,
        ])->exists())->toBeTrue()
        ->and(SuggestedRelation::find($suggestion->id))->toBeNull();
});

it('uses the override for duplicate detection and preserves the curated relation', function (): void {
    $resource = Resource::factory()->create();
    $suggestedType = RelationType::query()->where('slug', 'Cites')->firstOrFail();
    $overrideType = RelationType::query()->where('slug', 'References')->firstOrFail();
    $identifierTypeId = IdentifierType::query()->where('slug', 'DOI')->value('id');
    RelatedIdentifier::create([
        'resource_id' => $resource->id,
        'identifier' => '10.5880/override.2026.004',
        'identifier_type_id' => $identifierTypeId,
        'relation_type_id' => $overrideType->id,
        'citation_label' => 'Curated label',
        'position' => 0,
    ]);
    $suggestion = relationAcceptanceSuggestion($resource, $suggestedType, '10.5880/override.2026.004');
    $syncService = Mockery::mock(DataCiteSyncService::class);
    $syncService->shouldReceive('syncIfRegistered')->once()->andReturn(DataCiteSyncResult::notRequired());
    $citationLabelService = Mockery::mock(RelatedIdentifierCitationLabelService::class);
    $citationLabelService->shouldNotReceive('resolveBestEffort');

    $result = relationAcceptanceService($syncService, $citationLabelService)
        ->acceptRelation($suggestion, $overrideType->id);
    $stored = RelatedIdentifier::where('resource_id', $resource->id)->get();

    expect($result['success'])->toBeTrue()
        ->and($stored)->toHaveCount(1)
        ->and($stored->sole()->citation_label)->toBe('Curated label')
        ->and($stored->sole()->relation_type_id)->toBe($overrideType->id)
        ->and(SuggestedRelation::find($suggestion->id))->toBeNull();
});

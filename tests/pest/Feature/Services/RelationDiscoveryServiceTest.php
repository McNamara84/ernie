<?php

declare(strict_types=1);

use App\Models\IdentifierType;
use App\Models\RelatedIdentifier;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\SuggestedRelation;
use App\Services\Citations\CitationLookupResult;
use App\Services\Citations\CitationLookupService;
use App\Services\Citations\RelatedIdentifierCitationLabelService;
use App\Services\DataCiteEventDataService;
use App\Services\DataCiteSyncResult;
use App\Services\DataCiteSyncService;
use App\Services\RelationDiscoveryService;
use App\Services\ScholExplorerService;
use Database\Seeders\IdentifierTypeSeeder;
use Database\Seeders\RelationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    test()->seed(IdentifierTypeSeeder::class);
    test()->seed(RelationTypeSeeder::class);
});

afterEach(function (): void {
    Mockery::close();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array{identifier: string, identifier_type: string, relation_type: string, source_title: string|null, source_type: mixed, source_publisher: string|null, source_publication_date: string|null}
 */
function relationDiscoveryPayload(array $overrides = []): array
{
    return array_replace([
        'identifier' => '10.5880/related.2026.100',
        'identifier_type' => 'DOI',
        'relation_type' => 'Cites',
        'source_title' => 'Discovered related work',
        'source_type' => null,
        'source_publisher' => 'GFZ',
        'source_publication_date' => '2026',
    ], $overrides);
}

/**
 * @param  array<int, array<string, mixed>>  $scholExplorerRelations
 * @param  array<int, array<string, mixed>>  $dataCiteEventRelations
 */
function relationDiscoveryServiceFor(
    array $scholExplorerRelations,
    array $dataCiteEventRelations,
    CitationLookupService $citationLookupService,
): RelationDiscoveryService {
    $scholExplorerService = Mockery::mock(ScholExplorerService::class);
    $scholExplorerService->shouldReceive('findRelationsForDoi')
        ->once()
        ->with(Mockery::type('string'))
        ->andReturn($scholExplorerRelations);

    $dataCiteEventDataService = Mockery::mock(DataCiteEventDataService::class);
    $dataCiteEventDataService->shouldReceive('findRelationsForDoi')
        ->once()
        ->with(Mockery::type('string'))
        ->andReturn($dataCiteEventRelations);

    return new RelationDiscoveryService(
        $scholExplorerService,
        $dataCiteEventDataService,
        $citationLookupService,
        Mockery::mock(DataCiteSyncService::class),
        Mockery::mock(RelatedIdentifierCitationLabelService::class),
    );
}

describe('RelationDiscoveryService', function (): void {
    it('stores a resolved citation label when accepting a suggested relation', function (): void {
        $resource = Resource::factory()->create();
        $identifierTypeId = IdentifierType::query()->where('slug', 'DOI')->value('id');
        $relationTypeId = RelationType::query()->where('slug', 'Cites')->value('id');

        expect($identifierTypeId)->toBeInt()
            ->and($relationTypeId)->toBeInt();

        $suggestion = SuggestedRelation::query()->create([
            'resource_id' => $resource->id,
            'identifier' => '10.5880/related.2026.001',
            'identifier_type_id' => $identifierTypeId,
            'relation_type_id' => $relationTypeId,
            'source' => 'scholexplorer',
            'source_title' => 'Suggested related work',
            'source_publisher' => 'GFZ',
            'source_publication_date' => '2026-05-15',
            'discovered_at' => now(),
        ]);

        $syncService = Mockery::mock(DataCiteSyncService::class);
        $syncService->shouldReceive('syncIfRegistered')
            ->once()
            ->with(Mockery::on(fn (Resource $candidate): bool => $candidate->is($resource)))
            ->andReturn(DataCiteSyncResult::notRequired());

        $citationLabelService = Mockery::mock(RelatedIdentifierCitationLabelService::class);
        $citationLabelService->shouldReceive('resolveBestEffort')
            ->once()
            ->with('10.5880/related.2026.001', 'DOI', Mockery::type('float'))
            ->andReturn('Doe, J. (2026): Suggested related work. GFZ.');

        $service = new RelationDiscoveryService(
            Mockery::mock(ScholExplorerService::class),
            Mockery::mock(DataCiteEventDataService::class),
            Mockery::mock(CitationLookupService::class),
            $syncService,
            $citationLabelService,
        );

        $result = $service->acceptRelation($suggestion);

        $relatedIdentifier = RelatedIdentifier::query()->where('resource_id', $resource->id)->first();

        expect($result['success'])->toBeTrue()
            ->and($relatedIdentifier)->not->toBeNull()
            ->and($relatedIdentifier?->citation_label)->toBe('Doe, J. (2026): Suggested related work. GFZ.')
            ->and($relatedIdentifier?->source)->toBe(RelatedIdentifier::SOURCE_RELATION_SUGGESTION_ASSISTANT)
            ->and($relatedIdentifier?->isRepositoryCuration())->toBeTrue()
            ->and(SuggestedRelation::query()->find($suggestion->id))->toBeNull();
    });

    it('falls back to suggestion metadata when citation resolution does not produce a label', function (): void {
        $resource = Resource::factory()->create();
        $identifierTypeId = IdentifierType::query()->where('slug', 'DOI')->value('id');
        $relationTypeId = RelationType::query()->where('slug', 'References')->value('id');

        expect($identifierTypeId)->toBeInt()
            ->and($relationTypeId)->toBeInt();

        $suggestion = SuggestedRelation::query()->create([
            'resource_id' => $resource->id,
            'identifier' => '10.5880/related.2024.002',
            'identifier_type_id' => $identifierTypeId,
            'relation_type_id' => $relationTypeId,
            'source' => 'scholexplorer',
            'source_title' => 'Fallback related work',
            'source_publisher' => 'GFZ Data Services',
            'source_publication_date' => '2024-03-11',
            'discovered_at' => now(),
        ]);

        $syncService = Mockery::mock(DataCiteSyncService::class);
        $syncService->shouldReceive('syncIfRegistered')
            ->once()
            ->with(Mockery::on(fn (Resource $candidate): bool => $candidate->is($resource)))
            ->andReturn(DataCiteSyncResult::notRequired());

        $citationLabelService = Mockery::mock(RelatedIdentifierCitationLabelService::class);
        $citationLabelService->shouldReceive('resolveBestEffort')
            ->once()
            ->with('10.5880/related.2024.002', 'DOI', Mockery::type('float'))
            ->andReturnNull();

        $service = new RelationDiscoveryService(
            Mockery::mock(ScholExplorerService::class),
            Mockery::mock(DataCiteEventDataService::class),
            Mockery::mock(CitationLookupService::class),
            $syncService,
            $citationLabelService,
        );

        $service->acceptRelation($suggestion);

        $relatedIdentifier = RelatedIdentifier::query()->where('resource_id', $resource->id)->first();

        expect($relatedIdentifier)->not->toBeNull()
            ->and($relatedIdentifier?->citation_label)->toBe('Fallback related work (2024). GFZ Data Services.');
    });

    it('preserves an existing curated citation label when accepting a duplicate suggestion', function (): void {
        $resource = Resource::factory()->create();
        $identifierTypeId = IdentifierType::query()->where('slug', 'DOI')->value('id');
        $relationTypeId = RelationType::query()->where('slug', 'Cites')->value('id');

        expect($identifierTypeId)->toBeInt()
            ->and($relationTypeId)->toBeInt();

        RelatedIdentifier::query()->create([
            'resource_id' => $resource->id,
            'identifier' => '10.5880/related.2026.003',
            'identifier_type_id' => $identifierTypeId,
            'relation_type_id' => $relationTypeId,
            'citation_label' => 'Manual curated citation label',
            'position' => 0,
        ]);

        $suggestion = SuggestedRelation::query()->create([
            'resource_id' => $resource->id,
            'identifier' => '10.5880/related.2026.003',
            'identifier_type_id' => $identifierTypeId,
            'relation_type_id' => $relationTypeId,
            'source' => 'scholexplorer',
            'source_title' => 'Duplicate related work',
            'source_publisher' => 'GFZ',
            'source_publication_date' => '2026-05-15',
            'discovered_at' => now(),
        ]);

        $syncService = Mockery::mock(DataCiteSyncService::class);
        $syncService->shouldReceive('syncIfRegistered')
            ->once()
            ->with(Mockery::on(fn (Resource $candidate): bool => $candidate->is($resource)))
            ->andReturn(DataCiteSyncResult::notRequired());

        $citationLabelService = Mockery::mock(RelatedIdentifierCitationLabelService::class);
        $citationLabelService->shouldNotReceive('resolveBestEffort');

        $service = new RelationDiscoveryService(
            Mockery::mock(ScholExplorerService::class),
            Mockery::mock(DataCiteEventDataService::class),
            Mockery::mock(CitationLookupService::class),
            $syncService,
            $citationLabelService,
        );

        $service->acceptRelation($suggestion);

        $relatedIdentifiers = RelatedIdentifier::query()->where('resource_id', $resource->id)->get();

        expect($relatedIdentifiers)->toHaveCount(1)
            ->and($relatedIdentifiers->first()?->citation_label)->toBe('Manual curated citation label')
            ->and(SuggestedRelation::query()->find($suggestion->id))->toBeNull();
    });

    it('stores a canonical resource type for a new DataCite Event Data DOI suggestion', function (): void {
        Resource::factory()->create(['doi' => '10.5880/source.2026.101']);
        $relatedDoi = '10.1016/j.cageo.2026.106101';
        $citationLookupService = Mockery::mock(CitationLookupService::class);
        $citationLookupService->shouldReceive('lookup')
            ->once()
            ->with($relatedDoi)
            ->andReturn(CitationLookupResult::hit('crossref', [
                'relatedItemType' => ' JournalArticle ',
            ]));

        $newCount = relationDiscoveryServiceFor(
            [],
            [relationDiscoveryPayload(['identifier' => $relatedDoi])],
            $citationLookupService,
        )->discoverAll();

        $suggestion = SuggestedRelation::query()->sole();

        expect($newCount)->toBe(1)
            ->and($suggestion->source)->toBe('datacite_event_data')
            ->and($suggestion->source_type)->toBe('JournalArticle');
    });

    it('stores a canonical resource type for a new ScholExplorer DOI suggestion without a source type', function (): void {
        Resource::factory()->create(['doi' => '10.5880/source.2026.102']);
        $relatedDoi = '10.5880/related.2026.102';
        $citationLookupService = Mockery::mock(CitationLookupService::class);
        $citationLookupService->shouldReceive('lookup')
            ->once()
            ->with($relatedDoi)
            ->andReturn(CitationLookupResult::hit('datacite', [
                'relatedItemType' => 'Dataset',
            ]));

        $newCount = relationDiscoveryServiceFor(
            [relationDiscoveryPayload(['identifier' => $relatedDoi])],
            [],
            $citationLookupService,
        )->discoverAll();

        $suggestion = SuggestedRelation::query()->sole();

        expect($newCount)->toBe(1)
            ->and($suggestion->source)->toBe('scholexplorer')
            ->and($suggestion->source_type)->toBe('Dataset');
    });

    it('keeps and trims a resource type supplied by ScholExplorer without performing a lookup', function (): void {
        Resource::factory()->create(['doi' => '10.5880/source.2026.103']);
        $citationLookupService = Mockery::mock(CitationLookupService::class);
        $citationLookupService->shouldNotReceive('lookup');

        $newCount = relationDiscoveryServiceFor(
            [relationDiscoveryPayload([
                'identifier' => '10.5880/related.2026.103',
                'source_type' => ' Dataset ',
            ])],
            [],
            $citationLookupService,
        )->discoverAll();

        expect($newCount)->toBe(1)
            ->and(SuggestedRelation::query()->sole()->source_type)->toBe('Dataset');
    });

    it('uses the citation lookup when an upstream source type is not a string', function (): void {
        Resource::factory()->create(['doi' => '10.5880/source.2026.104']);
        $relatedDoi = '10.5880/related.2026.104';
        $citationLookupService = Mockery::mock(CitationLookupService::class);
        $citationLookupService->shouldReceive('lookup')
            ->once()
            ->with($relatedDoi)
            ->andReturn(CitationLookupResult::hit('datacite', [
                'relatedItemType' => 'Report',
            ]));

        relationDiscoveryServiceFor(
            [relationDiscoveryPayload([
                'identifier' => $relatedDoi,
                'source_type' => 123,
            ])],
            [],
            $citationLookupService,
        )->discoverAll();

        expect(SuggestedRelation::query()->sole()->source_type)->toBe('Report');
    });

    it('backfills an existing pending suggestion without replacing it or changing its discovery date', function (): void {
        $resource = Resource::factory()->create(['doi' => '10.5880/source.2026.105']);
        $identifierTypeId = IdentifierType::query()->where('slug', 'DOI')->value('id');
        $relationTypeId = RelationType::query()->where('slug', 'Cites')->value('id');
        $originalDiscoveredAt = Carbon::parse('2026-02-03 04:05:06');
        $relatedDoi = '10.5880/related.2026.105';

        expect($identifierTypeId)->toBeInt()
            ->and($relationTypeId)->toBeInt();

        $existingSuggestion = SuggestedRelation::query()->create([
            'resource_id' => $resource->id,
            'identifier' => $relatedDoi,
            'identifier_type_id' => $identifierTypeId,
            'relation_type_id' => $relationTypeId,
            'source' => 'scholexplorer',
            'source_type' => null,
            'discovered_at' => $originalDiscoveredAt,
        ]);
        $citationLookupService = Mockery::mock(CitationLookupService::class);
        $citationLookupService->shouldReceive('lookup')
            ->once()
            ->with($relatedDoi)
            ->andReturn(CitationLookupResult::hit('datacite', [
                'relatedItemType' => 'Dataset',
            ]));

        $newCount = relationDiscoveryServiceFor(
            [relationDiscoveryPayload(['identifier' => $relatedDoi])],
            [],
            $citationLookupService,
        )->discoverAll();

        $refreshedSuggestion = $existingSuggestion->fresh();

        expect($newCount)->toBe(0)
            ->and(SuggestedRelation::query()->count())->toBe(1)
            ->and($refreshedSuggestion)->not->toBeNull()
            ->and($refreshedSuggestion?->id)->toBe($existingSuggestion->id)
            ->and($refreshedSuggestion?->source_type)->toBe('Dataset')
            ->and($refreshedSuggestion?->discovered_at?->equalTo($originalDiscoveredAt))->toBeTrue();
    });

    it('does not overwrite or look up the type of an existing populated suggestion', function (): void {
        $resource = Resource::factory()->create(['doi' => '10.5880/source.2026.106']);
        $relatedDoi = '10.5880/related.2026.106';
        $existingSuggestion = SuggestedRelation::query()->create([
            'resource_id' => $resource->id,
            'identifier' => $relatedDoi,
            'identifier_type_id' => IdentifierType::query()->where('slug', 'DOI')->value('id'),
            'relation_type_id' => RelationType::query()->where('slug', 'Cites')->value('id'),
            'source' => 'scholexplorer',
            'source_type' => 'Dataset',
            'discovered_at' => now(),
        ]);
        $citationLookupService = Mockery::mock(CitationLookupService::class);
        $citationLookupService->shouldNotReceive('lookup');

        $newCount = relationDiscoveryServiceFor(
            [relationDiscoveryPayload(['identifier' => $relatedDoi])],
            [],
            $citationLookupService,
        )->discoverAll();

        expect($newCount)->toBe(0)
            ->and($existingSuggestion->fresh()?->source_type)->toBe('Dataset');
    });

    it('does not look up resource types for non-DOI suggestions', function (): void {
        Resource::factory()->create(['doi' => '10.5880/source.2026.107']);
        $citationLookupService = Mockery::mock(CitationLookupService::class);
        $citationLookupService->shouldNotReceive('lookup');

        $newCount = relationDiscoveryServiceFor(
            [],
            [relationDiscoveryPayload([
                'identifier' => 'https://example.org/related-resource',
                'identifier_type' => 'URL',
                'relation_type' => 'References',
            ])],
            $citationLookupService,
        )->discoverAll();

        expect($newCount)->toBe(1)
            ->and(SuggestedRelation::query()->sole()->source_type)->toBeNull();
    });

    it('leaves the resource type empty when the citation lookup has no usable type', function (CitationLookupResult $lookupResult): void {
        Resource::factory()->create(['doi' => '10.5880/source.2026.108']);
        $relatedDoi = '10.5880/related.2026.108';
        $citationLookupService = Mockery::mock(CitationLookupService::class);
        $citationLookupService->shouldReceive('lookup')
            ->once()
            ->with($relatedDoi)
            ->andReturn($lookupResult);

        $newCount = relationDiscoveryServiceFor(
            [relationDiscoveryPayload(['identifier' => $relatedDoi])],
            [],
            $citationLookupService,
        )->discoverAll();

        expect($newCount)->toBe(1)
            ->and(SuggestedRelation::query()->sole()->source_type)->toBeNull();
    })->with([
        'not found' => fn (): CitationLookupResult => CitationLookupResult::notFound('datacite'),
        'lookup error' => fn (): CitationLookupResult => CitationLookupResult::error('crossref', 'HTTP 503'),
        'missing data' => fn (): CitationLookupResult => new CitationLookupResult('datacite', true),
        'missing related item type' => fn (): CitationLookupResult => CitationLookupResult::hit('datacite', []),
        'non-string related item type' => fn (): CitationLookupResult => CitationLookupResult::hit('datacite', ['relatedItemType' => 123]),
        'empty related item type' => fn (): CitationLookupResult => CitationLookupResult::hit('datacite', ['relatedItemType' => '   ']),
    ]);

    it('does not look up or recreate a relation that has already been accepted', function (): void {
        $resource = Resource::factory()->create(['doi' => '10.5880/source.2026.109']);
        $relatedDoi = '10.5880/related.2026.109';
        RelatedIdentifier::query()->create([
            'resource_id' => $resource->id,
            'identifier' => $relatedDoi,
            'identifier_type_id' => IdentifierType::query()->where('slug', 'DOI')->value('id'),
            'relation_type_id' => RelationType::query()->where('slug', 'Cites')->value('id'),
            'position' => 0,
        ]);
        $citationLookupService = Mockery::mock(CitationLookupService::class);
        $citationLookupService->shouldNotReceive('lookup');

        $newCount = relationDiscoveryServiceFor(
            [relationDiscoveryPayload(['identifier' => $relatedDoi])],
            [],
            $citationLookupService,
        )->discoverAll();

        expect($newCount)->toBe(0)
            ->and(SuggestedRelation::query()->count())->toBe(0);
    });

    it('returns zero when neither discovery source finds a relation', function (): void {
        Resource::factory()->create(['doi' => '10.5880/source.2026.110']);
        $citationLookupService = Mockery::mock(CitationLookupService::class);
        $citationLookupService->shouldNotReceive('lookup');

        $newCount = relationDiscoveryServiceFor([], [], $citationLookupService)->discoverAll();

        expect($newCount)->toBe(0)
            ->and(SuggestedRelation::query()->count())->toBe(0);
    });
});

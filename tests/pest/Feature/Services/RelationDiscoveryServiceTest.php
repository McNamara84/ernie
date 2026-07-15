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

    it('stores the related resource type from CitationLookupService for Crossref DOI relations discovered from DataCite Event Data', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/source.2026.001',
        ]);

        $relatedDoi = '10.1016/j.cageo.2026.106001';

        $scholExplorerService = Mockery::mock(ScholExplorerService::class);
        $scholExplorerService->shouldReceive('findRelationsForDoi')
            ->once()
            ->with($resource->doi)
            ->andReturn([]);

        $dataCiteEventDataService = Mockery::mock(DataCiteEventDataService::class);
        $dataCiteEventDataService->shouldReceive('findRelationsForDoi')
            ->once()
            ->with($resource->doi)
            ->andReturn([
                [
                    'identifier' => $relatedDoi,
                    'identifier_type' => 'DOI',
                    'relation_type' => 'Cites',
                    'source_title' => null,
                    'source_type' => null,
                    'source_publisher' => null,
                    'source_publication_date' => null,
                ],
            ]);

        $citationLookupService = Mockery::mock(CitationLookupService::class);
        $citationLookupService->shouldReceive('lookup')
            ->once()
            ->with($relatedDoi)
            ->andReturn(CitationLookupResult::hit('crossref', [
                'relatedItemType' => 'JournalArticle',
            ]));

        app()->instance(ScholExplorerService::class, $scholExplorerService);
        app()->instance(DataCiteEventDataService::class, $dataCiteEventDataService);
        app()->instance(CitationLookupService::class, $citationLookupService);
        app()->instance(DataCiteSyncService::class, Mockery::mock(DataCiteSyncService::class));
        app()->instance(RelatedIdentifierCitationLabelService::class, Mockery::mock(RelatedIdentifierCitationLabelService::class));

        app(RelationDiscoveryService::class)->discoverAll();

        $suggestion = SuggestedRelation::query()->first();

        expect(SuggestedRelation::query()->count())->toBe(1)
            ->and($suggestion)->not->toBeNull()
            ->and($suggestion?->source_type)->toBe('JournalArticle');
    });

    it('backfills the related resource type for an existing pending suggestion', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/source.2026.002',
        ]);

        $identifierTypeId = IdentifierType::query()->where('slug', 'DOI')->value('id');
        $relationTypeId = RelationType::query()->where('slug', 'Cites')->value('id');

        expect($identifierTypeId)->toBeInt()
            ->and($relationTypeId)->toBeInt();

        $relatedDoi = '10.5880/related.2026.002';
        $originalDiscoveredAt = Carbon::parse('2026-02-03 04:05:06');

        $existingSuggestion = SuggestedRelation::query()->create([
            'resource_id' => $resource->id,
            'identifier' => $relatedDoi,
            'identifier_type_id' => $identifierTypeId,
            'relation_type_id' => $relationTypeId,
            'source' => 'datacite_event_data',
            'source_title' => null,
            'source_type' => null,
            'source_publisher' => null,
            'source_publication_date' => null,
            'discovered_at' => $originalDiscoveredAt,
        ]);

        $scholExplorerService = Mockery::mock(ScholExplorerService::class);
        $scholExplorerService->shouldReceive('findRelationsForDoi')
            ->once()
            ->with($resource->doi)
            ->andReturn([]);

        $dataCiteEventDataService = Mockery::mock(DataCiteEventDataService::class);
        $dataCiteEventDataService->shouldReceive('findRelationsForDoi')
            ->once()
            ->with($resource->doi)
            ->andReturn([
                [
                    'identifier' => $relatedDoi,
                    'identifier_type' => 'DOI',
                    'relation_type' => 'Cites',
                    'source_title' => null,
                    'source_type' => null,
                    'source_publisher' => null,
                    'source_publication_date' => null,
                ],
            ]);

        $citationLookupService = Mockery::mock(CitationLookupService::class);
        $citationLookupService->shouldReceive('lookup')
            ->once()
            ->with($relatedDoi)
            ->andReturn(CitationLookupResult::hit('datacite', [
                'relatedItemType' => 'Dataset',
            ]));

        app()->instance(ScholExplorerService::class, $scholExplorerService);
        app()->instance(DataCiteEventDataService::class, $dataCiteEventDataService);
        app()->instance(CitationLookupService::class, $citationLookupService);
        app()->instance(DataCiteSyncService::class, Mockery::mock(DataCiteSyncService::class));
        app()->instance(RelatedIdentifierCitationLabelService::class, Mockery::mock(RelatedIdentifierCitationLabelService::class));

        $newCount = app(RelationDiscoveryService::class)->discoverAll();

        $refreshedSuggestion = $existingSuggestion->fresh();

        expect($newCount)->toBe(0)
            ->and(SuggestedRelation::query()->count())->toBe(1)
            ->and($refreshedSuggestion)->not->toBeNull()
            ->and($refreshedSuggestion?->id)->toBe($existingSuggestion->id)
            ->and($refreshedSuggestion?->source_type)->toBe('Dataset')
            ->and($refreshedSuggestion?->discovered_at?->equalTo($originalDiscoveredAt))->toBeTrue();
    });

    it('keeps the related resource type empty when it cannot be resolved', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/source.2026.003',
        ]);

        $relatedUrl = 'https://example.org/related-resource';
        $relatedDoi = '10.5880/related.2026.003';

        $scholExplorerService = Mockery::mock(ScholExplorerService::class);
        $scholExplorerService->shouldReceive('findRelationsForDoi')
            ->once()
            ->with($resource->doi)
            ->andReturn([]);

        $dataCiteEventDataService = Mockery::mock(DataCiteEventDataService::class);
        $dataCiteEventDataService->shouldReceive('findRelationsForDoi')
            ->once()
            ->with($resource->doi)
            ->andReturn([
                [
                    'identifier' => $relatedUrl,
                    'identifier_type' => 'URL',
                    'relation_type' => 'References',
                    'source_title' => null,
                    'source_type' => null,
                    'source_publisher' => null,
                    'source_publication_date' => null,
                ],
                [
                    'identifier' => $relatedDoi,
                    'identifier_type' => 'DOI',
                    'relation_type' => 'Cites',
                    'source_title' => null,
                    'source_type' => null,
                    'source_publisher' => null,
                    'source_publication_date' => null,
                ],
            ]);

        $citationLookupService = Mockery::mock(CitationLookupService::class);
        $citationLookupService->shouldReceive('lookup')
            ->once()
            ->with($relatedDoi)
            ->andReturn(CitationLookupResult::notFound('datacite'));

        app()->instance(ScholExplorerService::class, $scholExplorerService);
        app()->instance(DataCiteEventDataService::class, $dataCiteEventDataService);
        app()->instance(CitationLookupService::class, $citationLookupService);
        app()->instance(DataCiteSyncService::class, Mockery::mock(DataCiteSyncService::class));
        app()->instance(RelatedIdentifierCitationLabelService::class, Mockery::mock(RelatedIdentifierCitationLabelService::class));

        $newCount = app(RelationDiscoveryService::class)->discoverAll();

        $suggestions = SuggestedRelation::query()
            ->orderBy('identifier')
            ->get();

        expect($newCount)->toBe(2)
            ->and($suggestions)->toHaveCount(2)
            ->and($suggestions->pluck('source_type')->all())->toBe([null, null]);
    });

    it('keeps an existing related resource type from DataCite Event Data without calling CitationLookupService', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/source.2026.004',
        ]);

        $relatedDoi = '10.5880/related.2026.004';

        $scholExplorerService = Mockery::mock(ScholExplorerService::class);
        $scholExplorerService->shouldReceive('findRelationsForDoi')
            ->once()
            ->with($resource->doi)
            ->andReturn([]);

        $dataCiteEventDataService = Mockery::mock(DataCiteEventDataService::class);
        $dataCiteEventDataService->shouldReceive('findRelationsForDoi')
            ->once()
            ->with($resource->doi)
            ->andReturn([
                [
                    'identifier' => $relatedDoi,
                    'identifier_type' => 'DOI',
                    'relation_type' => 'Cites',
                    'source_title' => null,
                    'source_type' => 'Dataset',
                    'source_publisher' => null,
                    'source_publication_date' => null,
                ],
            ]);

        $citationLookupService = Mockery::mock(CitationLookupService::class);
        $citationLookupService->shouldNotReceive('lookup');

        app()->instance(ScholExplorerService::class, $scholExplorerService);
        app()->instance(DataCiteEventDataService::class, $dataCiteEventDataService);
        app()->instance(CitationLookupService::class, $citationLookupService);
        app()->instance(DataCiteSyncService::class, Mockery::mock(DataCiteSyncService::class));
        app()->instance(RelatedIdentifierCitationLabelService::class, Mockery::mock(RelatedIdentifierCitationLabelService::class));

        app(RelationDiscoveryService::class)->discoverAll();

        $suggestion = SuggestedRelation::query()->first();

        expect(SuggestedRelation::query()->count())->toBe(1)
            ->and($suggestion)->not->toBeNull()
            ->and($suggestion?->source_type)->toBe('Dataset');
    });

    it('keeps the related resource type empty when CitationLookupService returns an invalid related item type', function (mixed $relatedItemType): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/source.2026.005',
        ]);

        $relatedDoi = '10.5880/related.2026.005';

        $scholExplorerService = Mockery::mock(ScholExplorerService::class);
        $scholExplorerService->shouldReceive('findRelationsForDoi')
            ->once()
            ->with($resource->doi)
            ->andReturn([]);

        $dataCiteEventDataService = Mockery::mock(DataCiteEventDataService::class);
        $dataCiteEventDataService->shouldReceive('findRelationsForDoi')
            ->once()
            ->with($resource->doi)
            ->andReturn([
                [
                    'identifier' => $relatedDoi,
                    'identifier_type' => 'DOI',
                    'relation_type' => 'Cites',
                    'source_title' => null,
                    'source_type' => null,
                    'source_publisher' => null,
                    'source_publication_date' => null,
                ],
            ]);

        $citationLookupService = Mockery::mock(CitationLookupService::class);
        $citationLookupService->shouldReceive('lookup')
            ->once()
            ->with($relatedDoi)
            ->andReturn(CitationLookupResult::hit('datacite', [
                'relatedItemType' => $relatedItemType,
            ]));

        app()->instance(ScholExplorerService::class, $scholExplorerService);
        app()->instance(DataCiteEventDataService::class, $dataCiteEventDataService);
        app()->instance(CitationLookupService::class, $citationLookupService);
        app()->instance(DataCiteSyncService::class, Mockery::mock(DataCiteSyncService::class));
        app()->instance(RelatedIdentifierCitationLabelService::class, Mockery::mock(RelatedIdentifierCitationLabelService::class));

        app(RelationDiscoveryService::class)->discoverAll();

        $suggestion = SuggestedRelation::query()->first();

        expect(SuggestedRelation::query()->count())->toBe(1)
            ->and($suggestion)->not->toBeNull()
            ->and($suggestion?->source_type)->toBeNull();
    })->with([
        'non-string related item type' => [123],
        'whitespace-only related item type' => ['   '],
    ]);
});

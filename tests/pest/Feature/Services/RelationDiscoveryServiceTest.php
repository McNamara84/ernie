<?php

declare(strict_types=1);

use App\Models\IdentifierType;
use App\Models\RelatedIdentifier;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\SuggestedRelation;
use App\Services\Citations\RelatedIdentifierCitationLabelService;
use App\Services\DataCiteEventDataService;
use App\Services\DataCiteSyncResult;
use App\Services\DataCiteSyncService;
use App\Services\RelationDiscoveryService;
use App\Services\ScholExplorerService;
use Database\Seeders\IdentifierTypeSeeder;
use Database\Seeders\RelationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
            $syncService,
            $citationLabelService,
        );

        $result = $service->acceptRelation($suggestion);

        $relatedIdentifier = RelatedIdentifier::query()->where('resource_id', $resource->id)->first();

        expect($result['success'])->toBeTrue()
            ->and($relatedIdentifier)->not->toBeNull()
            ->and($relatedIdentifier?->citation_label)->toBe('Doe, J. (2026): Suggested related work. GFZ.')
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
            $syncService,
            $citationLabelService,
        );

        $service->acceptRelation($suggestion);

        $relatedIdentifier = RelatedIdentifier::query()->where('resource_id', $resource->id)->first();

        expect($relatedIdentifier)->not->toBeNull()
            ->and($relatedIdentifier?->citation_label)->toBe('Fallback related work (2024). GFZ Data Services.');
    });
});
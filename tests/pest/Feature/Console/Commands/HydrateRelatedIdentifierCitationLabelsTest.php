<?php

declare(strict_types=1);

use App\Console\Commands\HydrateRelatedIdentifierCitationLabels;
use App\Models\IdentifierType;
use App\Models\RelatedIdentifier;
use App\Models\RelationType;
use App\Models\Resource;
use Database\Seeders\IdentifierTypeSeeder;
use Database\Seeders\RelationTypeSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

covers(HydrateRelatedIdentifierCitationLabels::class);

beforeEach(function (): void {
    test()->seed(IdentifierTypeSeeder::class);
    test()->seed(RelationTypeSeeder::class);
    Cache::flush();
});

it('hydrates missing citation labels only for DOI related identifiers', function (): void {
    $resource = Resource::factory()->create();
    $doiTypeId = IdentifierType::query()->where('slug', 'DOI')->value('id');
    $urlTypeId = IdentifierType::query()->where('slug', 'URL')->value('id');
    $relationTypeId = RelationType::query()->where('slug', 'Cites')->value('id');

    expect($doiTypeId)->toBeInt()
        ->and($urlTypeId)->toBeInt()
        ->and($relationTypeId)->toBeInt();

    $missingDoi = RelatedIdentifier::query()->create([
        'resource_id' => $resource->id,
        'identifier' => '10.5880/legacy.2026.010',
        'identifier_type_id' => $doiTypeId,
        'relation_type_id' => $relationTypeId,
        'position' => 0,
    ]);

    $existingLabel = RelatedIdentifier::query()->create([
        'resource_id' => $resource->id,
        'identifier' => '10.5880/legacy.2026.011',
        'identifier_type_id' => $doiTypeId,
        'relation_type_id' => $relationTypeId,
        'citation_label' => 'Existing curated citation label',
        'position' => 1,
    ]);

    $urlRelation = RelatedIdentifier::query()->create([
        'resource_id' => $resource->id,
        'identifier' => 'https://example.com/legacy',
        'identifier_type_id' => $urlTypeId,
        'relation_type_id' => $relationTypeId,
        'position' => 2,
    ]);

    Http::fake([
        'https://doi.org/*' => Http::response([
            'DOI' => '10.5880/legacy.2026.010',
            'title' => 'Hydrated citation',
            'publisher' => 'GFZ',
            'author' => [
                [
                    'family' => 'Doe',
                    'given' => 'Jane',
                ],
            ],
            'issued' => [
                'date-parts' => [[2026]],
            ],
        ], 200),
    ]);

    test()->artisan('related-identifiers:hydrate-citation-labels')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Processed 1 missing DOI related identifier');

    expect($missingDoi->fresh()?->citation_label)->toBe('Doe, J. (2026): Hydrated citation. GFZ. https://doi.org/10.5880/legacy.2026.010')
        ->and($existingLabel->fresh()?->citation_label)->toBe('Existing curated citation label')
        ->and($urlRelation->fresh()?->citation_label)->toBeNull();
});

it('reports success when no missing DOI citation labels need hydration', function (): void {
    $resource = Resource::factory()->create();
    $doiTypeId = IdentifierType::query()->where('slug', 'DOI')->value('id');
    $relationTypeId = RelationType::query()->where('slug', 'Cites')->value('id');

    expect($doiTypeId)->toBeInt()
        ->and($relationTypeId)->toBeInt();

    RelatedIdentifier::query()->create([
        'resource_id' => $resource->id,
        'identifier' => '10.5880/already-hydrated',
        'identifier_type_id' => $doiTypeId,
        'relation_type_id' => $relationTypeId,
        'citation_label' => 'Already hydrated',
        'position' => 0,
    ]);

    test()->artisan('related-identifiers:hydrate-citation-labels')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('No missing DOI citation labels found.');
});
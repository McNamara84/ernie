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
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

covers(HydrateRelatedIdentifierCitationLabels::class);

beforeEach(function (): void {
    test()->seed(IdentifierTypeSeeder::class);
    test()->seed(RelationTypeSeeder::class);
    Cache::flush();

    Http::preventStrayRequests();
});

it('hydrates missing DOI and URL citation labels without replacing curated labels', function (): void {
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

    $existingUrlLabel = RelatedIdentifier::query()->create([
        'resource_id' => $resource->id,
        'identifier' => 'https://example.com/already-curated',
        'identifier_type_id' => $urlTypeId,
        'relation_type_id' => $relationTypeId,
        'citation_label' => 'Existing curated URL citation',
        'position' => 3,
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
        ->expectsOutputToContain('Processed 2 missing DOI or URL related identifier')
        ->expectsOutputToContain('Hydrated 1 citation label');

    expect($missingDoi->fresh()?->citation_label)->toBe('Doe, J. (2026): Hydrated citation. GFZ. https://doi.org/10.5880/legacy.2026.010')
        ->and($existingLabel->fresh()?->citation_label)->toBe('Existing curated citation label')
        ->and($urlRelation->fresh()?->citation_label)->toBeNull()
        ->and($existingUrlLabel->fresh()?->citation_label)->toBe('Existing curated URL citation');
});

it('reports success when no missing DOI or URL citation labels need hydration', function (): void {
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
        ->expectsOutputToContain('No missing DOI or URL citation labels found.');
});

it('respects the limit and remains idempotent across repeated URL backfills', function (): void {
    Http::fake([
        'https://doi.org/*' => fn (Request $request) => Http::response([
            'title' => str_contains($request->url(), '/first') ? 'First URL citation' : 'Second URL citation',
            'publisher' => 'GFZ', 'author' => [['family' => 'Doe', 'given' => 'Jane']],
            'issued' => ['date-parts' => [[2026]]],
        ]),
    ]);

    $resource = Resource::factory()->create();
    $urlTypeId = IdentifierType::query()->where('slug', 'URL')->value('id');
    $relationTypeId = RelationType::query()->where('slug', 'Cites')->value('id');

    expect($urlTypeId)->toBeInt()
        ->and($relationTypeId)->toBeInt();

    $first = RelatedIdentifier::query()->create([
        'resource_id' => $resource->id,
        'identifier' => 'https://doi.org/10.5880/first',
        'identifier_type_id' => $urlTypeId,
        'relation_type_id' => $relationTypeId,
        'position' => 0,
    ]);
    $second = RelatedIdentifier::query()->create([
        'resource_id' => $resource->id,
        'identifier' => 'https://doi.org/10.5880/second',
        'identifier_type_id' => $urlTypeId,
        'relation_type_id' => $relationTypeId,
        'position' => 1,
    ]);

    test()->artisan('related-identifiers:hydrate-citation-labels', ['--limit' => 1])
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Processed 1 missing DOI or URL related identifier');

    expect($first->fresh()?->citation_label)->toContain('First URL citation')
        ->and($second->fresh()?->citation_label)->toBeNull();

    test()->artisan('related-identifiers:hydrate-citation-labels')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Processed 1 missing DOI or URL related identifier');

    expect($first->fresh()?->citation_label)->toContain('First URL citation')
        ->and($second->fresh()?->citation_label)->toContain('Second URL citation');

    test()->artisan('related-identifiers:hydrate-citation-labels')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('No missing DOI or URL citation labels found.');
});

it('uses an explicit cursor to continue limited backfills past unresolved URLs', function (): void {
    Http::fake([
        'https://doi.org/*' => fn (Request $request) => Http::response([
            'title' => str_contains($request->url(), '/first') ? 'First URL citation' : 'Second URL citation',
            'publisher' => 'GFZ', 'author' => [['family' => 'Doe', 'given' => 'Jane']],
            'issued' => ['date-parts' => [[2026]]],
        ]),
    ]);

    $resource = Resource::factory()->create();
    $urlTypeId = IdentifierType::query()->where('slug', 'URL')->value('id');
    $relationTypeId = RelationType::query()->where('slug', 'Cites')->value('id');

    expect($urlTypeId)->toBeInt()
        ->and($relationTypeId)->toBeInt();

    $uncached = RelatedIdentifier::query()->create([
        'resource_id' => $resource->id,
        'identifier' => 'https://example.com/not-cached-first',
        'identifier_type_id' => $urlTypeId,
        'relation_type_id' => $relationTypeId,
        'position' => 0,
    ]);
    $cached = RelatedIdentifier::query()->create([
        'resource_id' => $resource->id,
        'identifier' => 'https://doi.org/10.5880/second',
        'identifier_type_id' => $urlTypeId,
        'relation_type_id' => $relationTypeId,
        'position' => 1,
    ]);

    test()->artisan('related-identifiers:hydrate-citation-labels', ['--limit' => 1])
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('1 related identifier(s) remain without a citation label.')
        ->expectsOutputToContain(
            "Continue with: php artisan related-identifiers:hydrate-citation-labels --limit=1 --after-id={$uncached->id}",
        );

    expect($uncached->fresh()?->citation_label)->toBeNull()
        ->and($cached->fresh()?->citation_label)->toBeNull();

    test()->artisan('related-identifiers:hydrate-citation-labels', [
        '--limit' => 1,
        '--after-id' => $uncached->id,
    ])
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Processed 1 missing DOI or URL related identifier')
        ->doesntExpectOutputToContain('Continue with:');

    expect($uncached->fresh()?->citation_label)->toBeNull()
        ->and($cached->fresh()?->citation_label)->toContain('Second URL citation');
});

it('leaves uncached URLs unresolved without making HTTP requests', function (): void {
    $resource = Resource::factory()->create();
    $urlTypeId = IdentifierType::query()->where('slug', 'URL')->value('id');
    $relationTypeId = RelationType::query()->where('slug', 'Cites')->value('id');

    $urlRelation = RelatedIdentifier::query()->create([
        'resource_id' => $resource->id,
        'identifier' => 'https://example.com/not-cached',
        'identifier_type_id' => $urlTypeId,
        'relation_type_id' => $relationTypeId,
        'position' => 0,
    ]);

    Http::fake();

    test()->artisan('related-identifiers:hydrate-citation-labels')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('1 related identifier(s) remain without a citation label.');

    expect($urlRelation->fresh()?->citation_label)->toBeNull();
    Http::assertNothingSent();
});

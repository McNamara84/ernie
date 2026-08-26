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
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

covers(HydrateRelatedIdentifierCitationLabels::class);

beforeEach(function (): void {
    test()->seed(IdentifierTypeSeeder::class);
    test()->seed(RelationTypeSeeder::class);
    Cache::flush();

    Config::set('database.connections.legacy_metaworks', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);
    DB::purge('legacy_metaworks');

    Schema::connection('legacy_metaworks')->create('citationcache', function (Blueprint $table): void {
        $table->string('url', 333)->primary();
        $table->text('citation');
        $table->dateTime('datetimecopied')->nullable();
    });
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

    DB::connection('legacy_metaworks')->table('citationcache')->insert([
        'url' => 'https://example.com/legacy',
        'citation' => 'Legacy URL citation',
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
        ->expectsOutputToContain('Hydrated 2 citation label');

    expect($missingDoi->fresh()?->citation_label)->toBe('Doe, J. (2026): Hydrated citation. GFZ. https://doi.org/10.5880/legacy.2026.010')
        ->and($existingLabel->fresh()?->citation_label)->toBe('Existing curated citation label')
        ->and($urlRelation->fresh()?->citation_label)->toBe('Legacy URL citation')
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
    $resource = Resource::factory()->create();
    $urlTypeId = IdentifierType::query()->where('slug', 'URL')->value('id');
    $relationTypeId = RelationType::query()->where('slug', 'Cites')->value('id');

    expect($urlTypeId)->toBeInt()
        ->and($relationTypeId)->toBeInt();

    $first = RelatedIdentifier::query()->create([
        'resource_id' => $resource->id,
        'identifier' => 'https://example.com/first',
        'identifier_type_id' => $urlTypeId,
        'relation_type_id' => $relationTypeId,
        'position' => 0,
    ]);
    $second = RelatedIdentifier::query()->create([
        'resource_id' => $resource->id,
        'identifier' => 'https://example.com/second',
        'identifier_type_id' => $urlTypeId,
        'relation_type_id' => $relationTypeId,
        'position' => 1,
    ]);

    DB::connection('legacy_metaworks')->table('citationcache')->insert([
        [
            'url' => 'https://example.com/first',
            'citation' => 'First URL citation',
        ],
        [
            'url' => 'https://example.com/second',
            'citation' => 'Second URL citation',
        ],
    ]);

    test()->artisan('related-identifiers:hydrate-citation-labels', ['--limit' => 1])
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Processed 1 missing DOI or URL related identifier');

    expect($first->fresh()?->citation_label)->toBe('First URL citation')
        ->and($second->fresh()?->citation_label)->toBeNull();

    test()->artisan('related-identifiers:hydrate-citation-labels')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Processed 1 missing DOI or URL related identifier');

    expect($first->fresh()?->citation_label)->toBe('First URL citation')
        ->and($second->fresh()?->citation_label)->toBe('Second URL citation');

    test()->artisan('related-identifiers:hydrate-citation-labels')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('No missing DOI or URL citation labels found.');
});

it('uses an explicit cursor to continue limited backfills past unresolved URLs', function (): void {
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
        'identifier' => 'https://example.com/cached-second',
        'identifier_type_id' => $urlTypeId,
        'relation_type_id' => $relationTypeId,
        'position' => 1,
    ]);

    DB::connection('legacy_metaworks')->table('citationcache')->insert([
        'url' => 'https://example.com/cached-second',
        'citation' => 'Second URL citation',
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
        ->and($cached->fresh()?->citation_label)->toBe('Second URL citation');
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

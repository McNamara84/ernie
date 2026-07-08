<?php

declare(strict_types=1);

use App\Models\AssistantSuggestion;
use App\Models\FunderIdentifierType;
use App\Models\FundingReference;
use App\Models\Resource;
use App\Services\CrossrefFunderRor\CrossrefFunderRorDiscoveryService;
use App\Services\DataCiteSyncResult;
use App\Services\DataCiteSyncService;
use Database\Seeders\FunderIdentifierTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Assistants\CrossrefFunderRorSuggestion\Assistant;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Mockery::close();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function crossrefFunderRorAcceptanceMetadata(FundingReference $fundingReference, array $overrides = []): array
{
    return array_replace_recursive([
        'contract_version' => '1.0',
        'issue' => 784,
        'current' => [
            'funding_reference_id' => $fundingReference->id,
            'resource_id' => $fundingReference->resource_id,
            'funder_name' => $fundingReference->funder_name,
            'funder_identifier' => 'https://doi.org/10.13039/501100001659',
            'funder_identifier_type' => 'Crossref Funder ID',
            'scheme_uri' => 'https://doi.org/10.13039/',
            'normalized_crossref_funder_id' => '501100001659',
            'canonical_crossref_funder_identifier' => 'https://doi.org/10.13039/501100001659',
            'award_number' => $fundingReference->award_number,
            'award_uri' => $fundingReference->award_uri,
            'award_title' => $fundingReference->award_title,
        ],
        'proposed' => [
            'funder_identifier' => 'https://ror.org/018mejw64',
            'funder_identifier_type' => 'ROR',
            'scheme_uri' => 'https://ror.org/',
            'ror_id' => 'https://ror.org/018mejw64',
            'ror_display_name' => 'Deutsche Forschungsgemeinschaft',
            'ror_status' => 'active',
            'ror_types' => ['funder', 'nonprofit'],
            'matched_external_id' => [
                'type' => 'fundref',
                'value' => '501100001659',
                'matched_in' => 'external_ids[type=fundref].all',
                'preferred' => '501100001659',
            ],
        ],
        'provenance' => [
            'source' => 'ror_fundref_index',
            'source_file' => 'ror/ror-fundref-index.json',
            'source_retrieved_at' => '2026-06-24T00:00:00Z',
            'matching_strategy' => 'exact_fundref_external_id',
        ],
        'confidence' => [
            'level' => 'high',
            'score' => 1.0,
            'evidence' => ['exact_fundref_external_id_match'],
        ],
        'ambiguity' => [
            'status' => 'none',
            'candidate_count' => 1,
            'notes' => [],
            'warnings' => [],
        ],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $metadata
 */
function crossrefFunderRorAcceptanceSuggestion(Resource $resource, FundingReference $fundingReference, array $metadata = [], array $suggestionOverrides = []): AssistantSuggestion
{
    return AssistantSuggestion::create(array_replace([
        'assistant_id' => CrossrefFunderRorDiscoveryService::ASSISTANT_ID,
        'resource_id' => $resource->id,
        'target_type' => CrossrefFunderRorDiscoveryService::TARGET_TYPE,
        'target_id' => $fundingReference->id,
        'suggested_value' => 'https://ror.org/018mejw64',
        'suggested_label' => 'Deutsche Forschungsgemeinschaft -> https://ror.org/018mejw64',
        'similarity_score' => 1.0,
        'metadata' => crossrefFunderRorAcceptanceMetadata($fundingReference, $metadata),
        'discovered_at' => now(),
    ], $suggestionOverrides));
}

/**
 * @return array{0: Resource, 1: FundingReference, 2: FunderIdentifierType}
 */
function crossrefFunderRorAcceptanceFixture(string $doi = '10.5880/GFZ.2026.086'): array
{
    (new FunderIdentifierTypeSeeder)->run();

    $resource = Resource::factory()->withDoi($doi)->create();
    $crossrefType = FunderIdentifierType::where('name', 'Crossref Funder ID')->firstOrFail();

    $fundingReference = FundingReference::create([
        'resource_id' => $resource->id,
        'funder_name' => 'Deutsche Forschungsgemeinschaft',
        'funder_identifier' => 'https://doi.org/10.13039/501100001659',
        'funder_identifier_type_id' => $crossrefType->id,
        'scheme_uri' => 'https://doi.org/10.13039/',
        'award_number' => 'DFG-EXAMPLE',
        'award_uri' => 'https://gepris.dfg.de/gepris/OCTOPUS',
        'award_title' => 'Existing award metadata must remain untouched',
    ]);

    return [$resource, $fundingReference, $crossrefType];
}
it('accepts a Crossref-to-ROR suggestion by updating only identifier fields', function (): void {
    (new FunderIdentifierTypeSeeder)->run();

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.006')->create();
    $crossrefType = FunderIdentifierType::where('name', 'Crossref Funder ID')->firstOrFail();

    $fundingReference = FundingReference::create([
        'resource_id' => $resource->id,
        'funder_name' => 'Deutsche Forschungsgemeinschaft',
        'funder_identifier' => 'https://doi.org/10.13039/501100001659',
        'funder_identifier_type_id' => $crossrefType->id,
        'scheme_uri' => 'https://doi.org/10.13039/',
        'award_number' => 'DFG-EXAMPLE',
        'award_uri' => 'https://gepris.dfg.de/gepris/OCTOPUS',
        'award_title' => 'Existing award metadata must remain untouched',
    ]);

    $suggestion = crossrefFunderRorAcceptanceSuggestion($resource, $fundingReference);

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);
    $fundingReference->refresh();

    $rorType = FunderIdentifierType::where('name', 'ROR')->firstOrFail();

    expect($result['success'])->toBeTrue()
        ->and(AssistantSuggestion::find($suggestion->id))->toBeNull()
        ->and($fundingReference->funder_identifier)->toBe('https://ror.org/018mejw64')
        ->and($fundingReference->funder_identifier_type_id)->toBe($rorType->id)
        ->and($fundingReference->scheme_uri)->toBe('https://ror.org/')
        ->and($fundingReference->funder_name)->toBe('Deutsche Forschungsgemeinschaft')
        ->and($fundingReference->award_number)->toBe('DFG-EXAMPLE')
        ->and($fundingReference->award_uri)->toBe('https://gepris.dfg.de/gepris/OCTOPUS')
        ->and($fundingReference->award_title)->toBe('Existing award metadata must remain untouched');
});

it('does not accept stale Crossref-to-ROR suggestions when the current FundRef ID changed', function (): void {
    (new FunderIdentifierTypeSeeder)->run();

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.007')->create();
    $crossrefType = FunderIdentifierType::where('name', 'Crossref Funder ID')->firstOrFail();

    $fundingReference = FundingReference::create([
        'resource_id' => $resource->id,
        'funder_name' => 'Deutsche Forschungsgemeinschaft',
        'funder_identifier' => 'https://doi.org/10.13039/501100010956',
        'funder_identifier_type_id' => $crossrefType->id,
        'scheme_uri' => 'https://doi.org/10.13039/',
    ]);

    $suggestion = crossrefFunderRorAcceptanceSuggestion($resource, $fundingReference);

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);
    $fundingReference->refresh();

    expect($result['success'])->toBeFalse()
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull()
        ->and($fundingReference->funder_identifier)->toBe('https://doi.org/10.13039/501100010956')
        ->and($fundingReference->funder_identifier_type_id)->toBe($crossrefType->id)
        ->and($fundingReference->scheme_uri)->toBe('https://doi.org/10.13039/');
});
it('rejects Crossref-to-ROR suggestions targeting unsupported entities', function (): void {
    [$resource, $fundingReference, $crossrefType] = crossrefFunderRorAcceptanceFixture('10.5880/GFZ.2026.020');
    $suggestion = crossrefFunderRorAcceptanceSuggestion(
        $resource,
        $fundingReference,
        [],
        ['target_type' => 'resource'],
    );

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);
    $fundingReference->refresh();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('unsupported entity type')
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull()
        ->and($fundingReference->funder_identifier_type_id)->toBe($crossrefType->id);
});

it('rejects Crossref-to-ROR suggestions whose funding reference no longer exists', function (): void {
    [$resource, $fundingReference] = crossrefFunderRorAcceptanceFixture('10.5880/GFZ.2026.021');
    $suggestion = crossrefFunderRorAcceptanceSuggestion($resource, $fundingReference);

    $fundingReference->delete();

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('no longer exists')
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull();
});

it('rejects Crossref-to-ROR suggestions that do not propose a ROR identifier payload', function (): void {
    [$resource, $fundingReference, $crossrefType] = crossrefFunderRorAcceptanceFixture('10.5880/GFZ.2026.022');
    $suggestion = crossrefFunderRorAcceptanceSuggestion($resource, $fundingReference, [
        'proposed' => [
            'funder_identifier_type' => 'Crossref Funder ID',
            'scheme_uri' => 'https://doi.org/10.13039/',
        ],
    ]);

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);
    $fundingReference->refresh();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('Only ROR funder identifier suggestions')
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull()
        ->and($fundingReference->funder_identifier_type_id)->toBe($crossrefType->id);
});
it('does not accept suggestions when the target is no longer a Crossref Funder ID', function (): void {
    [$resource, $fundingReference, $crossrefType] = crossrefFunderRorAcceptanceFixture('10.5880/GFZ.2026.008');
    $otherType = FunderIdentifierType::where('name', 'Other')->firstOrFail();
    $suggestion = crossrefFunderRorAcceptanceSuggestion($resource, $fundingReference);

    $fundingReference->update(['funder_identifier_type_id' => $otherType->id]);

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);
    $fundingReference->refresh();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('no longer typed')
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull()
        ->and($fundingReference->funder_identifier)->toBe('https://doi.org/10.13039/501100001659')
        ->and($fundingReference->funder_identifier_type_id)->toBe($otherType->id)
        ->and($fundingReference->funder_identifier_type_id)->not->toBe($crossrefType->id)
        ->and($fundingReference->scheme_uri)->toBe('https://doi.org/10.13039/');
});

it('does not accept suggestions when the local ROR funder identifier type is missing', function (): void {
    [$resource, $fundingReference, $crossrefType] = crossrefFunderRorAcceptanceFixture('10.5880/GFZ.2026.009');
    $suggestion = crossrefFunderRorAcceptanceSuggestion($resource, $fundingReference);

    FunderIdentifierType::where('name', 'ROR')->delete();

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);
    $fundingReference->refresh();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('local ROR funder identifier type is missing')
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull()
        ->and($fundingReference->funder_identifier)->toBe('https://doi.org/10.13039/501100001659')
        ->and($fundingReference->funder_identifier_type_id)->toBe($crossrefType->id)
        ->and($fundingReference->scheme_uri)->toBe('https://doi.org/10.13039/');
});

it('rejects Crossref-to-ROR suggestions with incomplete metadata', function (): void {
    [$resource, $fundingReference, $crossrefType] = crossrefFunderRorAcceptanceFixture('10.5880/GFZ.2026.010');
    $suggestion = crossrefFunderRorAcceptanceSuggestion($resource, $fundingReference, [
        'proposed' => [
            'ror_id' => null,
        ],
    ]);

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);
    $fundingReference->refresh();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('complete Crossref-to-ROR metadata')
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull()
        ->and($fundingReference->funder_identifier_type_id)->toBe($crossrefType->id);
});

it('rejects Crossref-to-ROR suggestions when suggested value and proposed ROR differ', function (): void {
    [$resource, $fundingReference, $crossrefType] = crossrefFunderRorAcceptanceFixture('10.5880/GFZ.2026.011');
    $suggestion = crossrefFunderRorAcceptanceSuggestion(
        $resource,
        $fundingReference,
        [],
        ['suggested_value' => 'https://ror.org/04z8jg394'],
    );

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);
    $fundingReference->refresh();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('do not match')
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull()
        ->and($fundingReference->funder_identifier_type_id)->toBe($crossrefType->id);
});

it('rejects Crossref-to-ROR suggestions when proposed ROR metadata is inconsistent', function (): void {
    [$resource, $fundingReference, $crossrefType] = crossrefFunderRorAcceptanceFixture('10.5880/GFZ.2026.012');
    $suggestion = crossrefFunderRorAcceptanceSuggestion($resource, $fundingReference, [
        'proposed' => [
            'ror_id' => 'https://ror.org/04z8jg394',
        ],
    ]);

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);
    $fundingReference->refresh();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('metadata is inconsistent')
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull()
        ->and($fundingReference->funder_identifier_type_id)->toBe($crossrefType->id);
});

it('rejects suppressed Crossref-to-ROR ambiguity payloads', function (): void {
    [$resource, $fundingReference, $crossrefType] = crossrefFunderRorAcceptanceFixture('10.5880/GFZ.2026.013');
    $suggestion = crossrefFunderRorAcceptanceSuggestion($resource, $fundingReference, [
        'ambiguity' => [
            'status' => 'suppressed',
            'candidate_count' => 2,
            'warnings' => ['multiple_active_ror_matches'],
        ],
    ]);

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);
    $fundingReference->refresh();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('cannot be accepted')
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull()
        ->and($fundingReference->funder_identifier_type_id)->toBe($crossrefType->id);
});

it('rejects non-high-confidence Crossref-to-ROR suggestions', function (): void {
    [$resource, $fundingReference, $crossrefType] = crossrefFunderRorAcceptanceFixture('10.5880/GFZ.2026.014');
    $suggestion = crossrefFunderRorAcceptanceSuggestion($resource, $fundingReference, [
        'confidence' => [
            'level' => 'suppressed',
            'score' => 0.0,
        ],
    ]);

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);
    $fundingReference->refresh();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('Only high-confidence')
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull()
        ->and($fundingReference->funder_identifier_type_id)->toBe($crossrefType->id);
});

it('removes duplicate pending Crossref-to-ROR suggestions for the same funding reference after accept', function (): void {
    [$resource, $fundingReference] = crossrefFunderRorAcceptanceFixture('10.5880/GFZ.2026.015');
    $accepted = crossrefFunderRorAcceptanceSuggestion($resource, $fundingReference);
    $duplicate = crossrefFunderRorAcceptanceSuggestion(
        $resource,
        $fundingReference,
        [
            'proposed' => [
                'funder_identifier' => 'https://ror.org/04z8jg394',
                'ror_id' => 'https://ror.org/04z8jg394',
            ],
        ],
        [
            'suggested_value' => 'https://ror.org/04z8jg394',
            'suggested_label' => 'Duplicate mapping -> https://ror.org/04z8jg394',
        ],
    );

    $result = app(Assistant::class)->acceptSuggestion($accepted->id);

    expect($result['success'])->toBeTrue()
        ->and(AssistantSuggestion::find($accepted->id))->toBeNull()
        ->and(AssistantSuggestion::find($duplicate->id))->toBeNull();
});

it('keeps local normalization when DataCite sync fails after accept', function (): void {
    [$resource, $fundingReference] = crossrefFunderRorAcceptanceFixture('10.5880/GFZ.2026.016');
    $suggestion = crossrefFunderRorAcceptanceSuggestion($resource, $fundingReference);

    $syncService = Mockery::mock(DataCiteSyncService::class);
    $syncService->shouldReceive('syncIfRegistered')
        ->once()
        ->with(Mockery::on(fn (Resource $candidate): bool => $candidate->is($resource)))
        ->andReturn(DataCiteSyncResult::failed('10.5880/GFZ.2026.016', 'DataCite API unavailable'));
    app()->instance(DataCiteSyncService::class, $syncService);

    $result = app(Assistant::class)->acceptSuggestion($suggestion->id);
    $fundingReference->refresh();

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toContain('DataCite sync was attempted but did not complete')
        ->and($fundingReference->funder_identifier)->toBe('https://ror.org/018mejw64')
        ->and(AssistantSuggestion::find($suggestion->id))->toBeNull();
});

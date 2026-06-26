<?php

declare(strict_types=1);

use App\Models\AssistantSuggestion;
use App\Models\FunderIdentifierType;
use App\Models\FundingReference;
use App\Models\Resource;
use App\Services\CrossrefFunderRor\CrossrefFunderRorDiscoveryService;
use Database\Seeders\FunderIdentifierTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Assistants\CrossrefFunderRorSuggestion\Assistant;

uses(RefreshDatabase::class);

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
function crossrefFunderRorAcceptanceSuggestion(Resource $resource, FundingReference $fundingReference, array $metadata = []): AssistantSuggestion
{
    return AssistantSuggestion::create([
        'assistant_id' => CrossrefFunderRorDiscoveryService::ASSISTANT_ID,
        'resource_id' => $resource->id,
        'target_type' => CrossrefFunderRorDiscoveryService::TARGET_TYPE,
        'target_id' => $fundingReference->id,
        'suggested_value' => 'https://ror.org/018mejw64',
        'suggested_label' => 'Deutsche Forschungsgemeinschaft -> https://ror.org/018mejw64',
        'similarity_score' => 1.0,
        'metadata' => crossrefFunderRorAcceptanceMetadata($fundingReference, $metadata),
        'discovered_at' => now(),
    ]);
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

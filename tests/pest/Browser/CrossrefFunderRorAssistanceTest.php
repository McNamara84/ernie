<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AssistantSuggestion;
use App\Models\FunderIdentifierType;
use App\Models\FundingReference;
use App\Models\Resource;
use App\Models\User;
use App\Services\CrossrefFunderRor\CrossrefFunderRorDiscoveryService;
use Database\Seeders\FunderIdentifierTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Tests\TestCase;

uses(RefreshDatabase::class)->group('assistant', 'browser', 'crossref-funder-ror');

beforeEach(function (): void {
    app(Vite::class)
        ->useHotFile(storage_path('framework/testing-vite.hot'))
        ->useBuildDirectory('build');
});

it('reviews and accepts a Crossref Funder ID to ROR suggestion from assistance', function (): void {
    /** @var TestCase $this */
    (new FunderIdentifierTypeSeeder)->run();

    $admin = User::factory()->create([
        'role' => UserRole::ADMIN,
    ]);

    $resource = Resource::factory()->withDoi('10.5880/browser.crossref-ror')->create();
    $crossrefType = FunderIdentifierType::where('name', 'Crossref Funder ID')->firstOrFail();

    $fundingReference = FundingReference::create([
        'resource_id' => $resource->id,
        'funder_name' => 'Deutsche Forschungsgemeinschaft',
        'funder_identifier' => 'https://doi.org/10.13039/501100001659',
        'funder_identifier_type_id' => $crossrefType->id,
        'scheme_uri' => 'https://doi.org/10.13039/',
        'award_number' => 'DFG-BROWSER',
        'award_uri' => 'https://gepris.dfg.de/gepris/OCTOPUS',
        'award_title' => 'Award metadata remains untouched',
    ]);

    $suggestion = AssistantSuggestion::create([
        'assistant_id' => CrossrefFunderRorDiscoveryService::ASSISTANT_ID,
        'resource_id' => $resource->id,
        'target_type' => CrossrefFunderRorDiscoveryService::TARGET_TYPE,
        'target_id' => $fundingReference->id,
        'suggested_value' => 'https://ror.org/018mejw64',
        'suggested_label' => 'Deutsche Forschungsgemeinschaft -> https://ror.org/018mejw64',
        'similarity_score' => 1.0,
        'metadata' => [
            'current' => [
                'funding_reference_id' => $fundingReference->id,
                'resource_id' => $resource->id,
                'funder_name' => 'Deutsche Forschungsgemeinschaft',
                'funder_identifier' => 'https://doi.org/10.13039/501100001659',
                'funder_identifier_type' => 'Crossref Funder ID',
                'scheme_uri' => 'https://doi.org/10.13039/',
                'normalized_crossref_funder_id' => '501100001659',
                'canonical_crossref_funder_identifier' => 'https://doi.org/10.13039/501100001659',
                'award_number' => 'DFG-BROWSER',
                'award_uri' => 'https://gepris.dfg.de/gepris/OCTOPUS',
                'award_title' => 'Award metadata remains untouched',
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
                'status' => 'warning',
                'candidate_count' => 1,
                'notes' => [],
                'warnings' => ['ror_display_name_differs_from_local_name'],
            ],
            'acceptance' => [
                'updates' => [
                    'funder_identifier' => 'https://ror.org/018mejw64',
                    'funder_identifier_type' => 'ROR',
                    'scheme_uri' => 'https://ror.org/',
                ],
                'preserve' => ['funder_name', 'award_number', 'award_uri', 'award_title'],
                'preconditions' => ['target funding reference still exists'],
            ],
        ],
        'discovered_at' => now(),
    ]);

    $this->actingAs($admin);

    visit('/assistance')
        ->assertNoSmoke()
        ->assertSee('Crossref Funder ROR Suggestions')
        ->assertSee('Current Crossref Funder ID')
        ->assertSee('Proposed ROR identifier')
        ->assertSee('https://doi.org/10.13039/501100001659')
        ->assertSee('https://ror.org/018mejw64')
        ->assertSee('ror_display_name_differs_from_local_name')
        ->click("[data-testid=\"crossref-funder-ror-accept-{$suggestion->id}\"]")
        ->assertSee('Funding reference identifier normalized to ROR');

    $rorType = FunderIdentifierType::where('name', 'ROR')->firstOrFail();
    $fundingReference->refresh();

    expect($fundingReference->funder_identifier)->toBe('https://ror.org/018mejw64')
        ->and($fundingReference->funder_identifier_type_id)->toBe($rorType->id)
        ->and($fundingReference->scheme_uri)->toBe('https://ror.org/')
        ->and($fundingReference->funder_name)->toBe('Deutsche Forschungsgemeinschaft')
        ->and($fundingReference->award_number)->toBe('DFG-BROWSER')
        ->and(AssistantSuggestion::find($suggestion->id))->toBeNull();
});

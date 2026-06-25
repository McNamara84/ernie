<?php

declare(strict_types=1);

use App\Models\FunderIdentifierType;
use App\Models\FundingReference;
use App\Models\Resource;
use App\Services\CrossrefFunderRor\CrossrefFunderRorDiscoveryService;
use App\Services\CrossrefFunderRor\CrossrefFunderRorMappingSource;
use App\Services\CrossrefFunderRor\CrossrefFunderRorMatchInputProvider;
use Database\Seeders\FunderIdentifierTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

final class CrossrefFunderRorInMemoryMappingSource implements CrossrefFunderRorMappingSource
{
    /** @var list<string> */
    public array $requestedFundrefIds = [];

    /**
     * @param  array<string, list<array<string, mixed>>>  $candidatesByFundref
     */
    public function __construct(private readonly array $candidatesByFundref) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function candidatesForCrossrefFunderId(string $normalizedFundrefId): array
    {
        return $this->lookup($normalizedFundrefId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lookup(string $normalizedFundrefId): array
    {
        $this->requestedFundrefIds[] = $normalizedFundrefId;

        return $this->candidatesByFundref[$normalizedFundrefId] ?? [];
    }
}

function crossrefFunderRorType(string $name): FunderIdentifierType
{
    return FunderIdentifierType::where('name', $name)->firstOrFail();
}

function crossrefFunderRorFundingReference(
    Resource $resource,
    string $funderName,
    ?string $identifier,
    FunderIdentifierType $type,
    ?string $schemeUri = 'https://doi.org/10.13039/',
    array $overrides = [],
): FundingReference {
    return FundingReference::create(array_replace([
        'resource_id' => $resource->id,
        'funder_name' => $funderName,
        'funder_identifier' => $identifier,
        'funder_identifier_type_id' => $type->id,
        'scheme_uri' => $schemeUri,
        'award_number' => 'EXAMPLE-1',
        'award_uri' => 'https://example.test/award/1',
        'award_title' => 'Existing award metadata must be preserved',
    ], $overrides));
}

function crossrefFunderRorCandidate(array $overrides = []): array
{
    return array_replace_recursive([
        'ror_id' => 'https://ror.org/018mejw64',
        'ror_display_name' => 'Deutsche Forschungsgemeinschaft',
        'ror_status' => 'active',
        'ror_types' => ['funder', 'nonprofit'],
        'ror_record_last_modified' => '2026-06-01',
        'external_ids' => [
            'fundref' => [
                'all' => ['501100001659'],
                'preferred' => '501100001659',
            ],
        ],
        'source' => [
            'source' => 'ror_fundref_index',
            'source_file' => 'ror/ror-fundref-index.json',
            'source_generated_by' => 'get-ror-ids',
            'source_generated_from' => 'ROR Zenodo data dump',
            'source_retrieved_at' => '2026-06-24T00:00:00Z',
            'matching_strategy' => 'exact_fundref_external_id',
        ],
    ], $overrides);
}

it('reads only eligible Crossref Funder ID funding references as normalized inputs', function (): void {
    $this->seed(FunderIdentifierTypeSeeder::class);

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.001')->create();
    $crossrefType = crossrefFunderRorType('Crossref Funder ID');
    $rorType = crossrefFunderRorType('ROR');
    $otherType = crossrefFunderRorType('Other');

    $dfg = crossrefFunderRorFundingReference(
        resource: $resource,
        funderName: 'Deutsche Forschungsgemeinschaft',
        identifier: ' https://doi.org/10.13039/501100001659 ',
        type: $crossrefType,
    );

    $gfz = crossrefFunderRorFundingReference(
        resource: $resource,
        funderName: 'GFZ Helmholtz Centre for Geosciences',
        identifier: 'doi:10.13039/501100010956',
        type: $crossrefType,
    );

    $europeanCommission = crossrefFunderRorFundingReference(
        resource: $resource,
        funderName: 'European Commission',
        identifier: '501100000780',
        type: $crossrefType,
        schemeUri: null,
    );

    crossrefFunderRorFundingReference(
        resource: $resource,
        funderName: 'Already ROR-normalized DFG',
        identifier: 'https://ror.org/018mejw64',
        type: $rorType,
        schemeUri: 'https://ror.org/',
    );

    crossrefFunderRorFundingReference(
        resource: $resource,
        funderName: 'Crossref-shaped value with unsupported local type',
        identifier: 'https://doi.org/10.13039/501100004238',
        type: $otherType,
    );

    crossrefFunderRorFundingReference(
        resource: $resource,
        funderName: 'Missing identifier',
        identifier: null,
        type: $crossrefType,
    );

    $inputs = (new CrossrefFunderRorMatchInputProvider)->pendingInputs();

    expect($inputs)->toHaveCount(3)
        ->and($inputs->pluck('targetId')->all())->toEqualCanonicalizing([
            $dfg->id,
            $gfz->id,
            $europeanCommission->id,
        ]);

    $dfgInput = $inputs->first(fn (object $input): bool => $input->targetId === $dfg->id);
    $gfzInput = $inputs->first(fn (object $input): bool => $input->targetId === $gfz->id);
    $ecInput = $inputs->first(fn (object $input): bool => $input->targetId === $europeanCommission->id);

    expect($dfgInput->targetType)->toBe('funding_reference')
        ->and($dfgInput->resourceId)->toBe($resource->id)
        ->and($dfgInput->funderName)->toBe('Deutsche Forschungsgemeinschaft')
        ->and($dfgInput->funderIdentifier)->toBe('https://doi.org/10.13039/501100001659')
        ->and($dfgInput->funderIdentifierType)->toBe('Crossref Funder ID')
        ->and($dfgInput->normalizedCrossrefFunderId)->toBe('501100001659')
        ->and($dfgInput->canonicalCrossrefFunderIdentifier)->toBe('https://doi.org/10.13039/501100001659')
        ->and($gfzInput->normalizedCrossrefFunderId)->toBe('501100010956')
        ->and($gfzInput->canonicalCrossrefFunderIdentifier)->toBe('https://doi.org/10.13039/501100010956')
        ->and($ecInput->normalizedCrossrefFunderId)->toBe('501100000780')
        ->and($ecInput->canonicalCrossrefFunderIdentifier)->toBe('https://doi.org/10.13039/501100000780');
});

it('stores high-confidence suggestions for exact active ROR fundref mappings', function (): void {
    $this->seed(FunderIdentifierTypeSeeder::class);

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.002')->create();
    $crossrefType = crossrefFunderRorType('Crossref Funder ID');

    $dfg = crossrefFunderRorFundingReference(
        resource: $resource,
        funderName: 'Deutsche Forschungsgemeinschaft',
        identifier: 'https://doi.org/10.13039/501100001659',
        type: $crossrefType,
        overrides: [
            'award_number' => 'DFG-EXAMPLE',
            'award_uri' => 'https://gepris.dfg.de/gepris/OCTOPUS',
            'award_title' => 'A real funding reference keeps its award metadata',
        ],
    );

    $gfz = crossrefFunderRorFundingReference(
        resource: $resource,
        funderName: 'GFZ Helmholtz Centre for Geosciences',
        identifier: 'https://doi.org/10.13039/501100010956',
        type: $crossrefType,
    );

    $mappingSource = new CrossrefFunderRorInMemoryMappingSource([
        '501100001659' => [
            crossrefFunderRorCandidate(),
        ],
        '501100010956' => [
            crossrefFunderRorCandidate([
                'ror_id' => 'https://ror.org/04z8jg394',
                'ror_display_name' => 'GFZ Helmholtz Centre for Geosciences',
                'ror_types' => ['facility', 'funder'],
                'external_ids' => [
                    'fundref' => [
                        'all' => ['501100010956'],
                        'preferred' => '501100010956',
                    ],
                ],
            ]),
        ],
    ]);

    $service = new CrossrefFunderRorDiscoveryService(
        inputProvider: new CrossrefFunderRorMatchInputProvider,
        mappingSource: $mappingSource,
    );

    $storedSuggestions = [];
    $progressMessages = [];

    $count = $service->discover(
        storeSuggestion: function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$storedSuggestions): bool {
            $storedSuggestions[] = compact(
                'resourceId',
                'targetType',
                'targetId',
                'suggestedValue',
                'suggestedLabel',
                'similarityScore',
                'metadata',
            );

            return true;
        },
        onProgress: function (string $message) use (&$progressMessages): void {
            $progressMessages[] = $message;
        },
    );

    expect($count)->toBe(2)
        ->and($storedSuggestions)->toHaveCount(2)
        ->and($mappingSource->requestedFundrefIds)->toEqualCanonicalizing([
            '501100001659',
            '501100010956',
        ]);

    $dfgSuggestion = collect($storedSuggestions)->first(fn (array $suggestion): bool => $suggestion['targetId'] === $dfg->id);
    $gfzSuggestion = collect($storedSuggestions)->first(fn (array $suggestion): bool => $suggestion['targetId'] === $gfz->id);

    expect($dfgSuggestion)->not->toBeNull()
        ->and($dfgSuggestion['resourceId'])->toBe($resource->id)
        ->and($dfgSuggestion['targetType'])->toBe('funding_reference')
        ->and($dfgSuggestion['suggestedValue'])->toBe('https://ror.org/018mejw64')
        ->and($dfgSuggestion['suggestedLabel'])->toContain('Deutsche Forschungsgemeinschaft')
        ->and($dfgSuggestion['similarityScore'])->toBe(1.0)
        ->and($dfgSuggestion['metadata']['contract_version'])->toBe('1.0')
        ->and($dfgSuggestion['metadata']['current']['funding_reference_id'])->toBe($dfg->id)
        ->and($dfgSuggestion['metadata']['current']['funder_name'])->toBe('Deutsche Forschungsgemeinschaft')
        ->and($dfgSuggestion['metadata']['current']['funder_identifier'])->toBe('https://doi.org/10.13039/501100001659')
        ->and($dfgSuggestion['metadata']['current']['funder_identifier_type'])->toBe('Crossref Funder ID')
        ->and($dfgSuggestion['metadata']['current']['normalized_crossref_funder_id'])->toBe('501100001659')
        ->and($dfgSuggestion['metadata']['current']['canonical_crossref_funder_identifier'])->toBe('https://doi.org/10.13039/501100001659')
        ->and($dfgSuggestion['metadata']['current']['award_number'])->toBe('DFG-EXAMPLE')
        ->and($dfgSuggestion['metadata']['proposed']['funder_identifier'])->toBe('https://ror.org/018mejw64')
        ->and($dfgSuggestion['metadata']['proposed']['funder_identifier_type'])->toBe('ROR')
        ->and($dfgSuggestion['metadata']['proposed']['scheme_uri'])->toBe('https://ror.org/')
        ->and($dfgSuggestion['metadata']['proposed']['matched_external_id'])->toBe([
            'type' => 'fundref',
            'value' => '501100001659',
            'matched_in' => 'external_ids[type=fundref].all',
            'preferred' => '501100001659',
        ])
        ->and($dfgSuggestion['metadata']['provenance']['source'])->toBe('ror_fundref_index')
        ->and($dfgSuggestion['metadata']['provenance']['matching_strategy'])->toBe('exact_fundref_external_id')
        ->and($dfgSuggestion['metadata']['confidence']['level'])->toBe('high')
        ->and($dfgSuggestion['metadata']['confidence']['score'])->toBe(1.0)
        ->and($dfgSuggestion['metadata']['confidence']['evidence'])->toContain('exact_fundref_external_id_match')
        ->and($dfgSuggestion['metadata']['ambiguity'])->toMatchArray([
            'status' => 'none',
            'candidate_count' => 1,
            'notes' => [],
            'warnings' => [],
        ])
        ->and($dfgSuggestion['metadata']['acceptance']['updates'])->toBe([
            'funder_identifier' => 'https://ror.org/018mejw64',
            'funder_identifier_type' => 'ROR',
            'scheme_uri' => 'https://ror.org/',
        ])
        ->and($dfgSuggestion['metadata']['acceptance']['preserve'])->toEqualCanonicalizing([
            'funder_name',
            'award_number',
            'award_uri',
            'award_title',
        ])
        ->and($gfzSuggestion['suggestedValue'])->toBe('https://ror.org/04z8jg394')
        ->and($gfzSuggestion['metadata']['proposed']['matched_external_id']['matched_in'])->toBe('external_ids[type=fundref].all')
        ->and($progressMessages)->not->toBeEmpty();
});

it('suppresses valid Crossref Funder IDs when no exact ROR fundref mapping exists', function (): void {
    $this->seed(FunderIdentifierTypeSeeder::class);

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.003')->create();
    $crossrefType = crossrefFunderRorType('Crossref Funder ID');
    $rorType = crossrefFunderRorType('ROR');

    crossrefFunderRorFundingReference(
        resource: $resource,
        funderName: 'University of Potsdam',
        identifier: 'https://doi.org/10.13039/501100004238',
        type: $crossrefType,
    );

    crossrefFunderRorFundingReference(
        resource: $resource,
        funderName: 'Already normalized DFG',
        identifier: 'https://ror.org/018mejw64',
        type: $rorType,
        schemeUri: 'https://ror.org/',
    );

    $mappingSource = new CrossrefFunderRorInMemoryMappingSource([]);
    $service = new CrossrefFunderRorDiscoveryService(
        inputProvider: new CrossrefFunderRorMatchInputProvider,
        mappingSource: $mappingSource,
    );

    $storedSuggestions = [];

    $count = $service->discover(
        storeSuggestion: function () use (&$storedSuggestions): bool {
            $storedSuggestions[] = true;

            return true;
        },
        onProgress: fn (string $message): null => null,
    );

    expect($count)->toBe(0)
        ->and($storedSuggestions)->toBeEmpty()
        ->and($mappingSource->requestedFundrefIds)->toBe(['501100004238']);
});

it('suppresses ambiguous mappings when the same FundRef ID has multiple active ROR candidates', function (): void {
    $this->seed(FunderIdentifierTypeSeeder::class);

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.004')->create();
    $crossrefType = crossrefFunderRorType('Crossref Funder ID');

    crossrefFunderRorFundingReference(
        resource: $resource,
        funderName: 'Deutsche Forschungsgemeinschaft',
        identifier: 'https://doi.org/10.13039/501100001659',
        type: $crossrefType,
    );

    $mappingSource = new CrossrefFunderRorInMemoryMappingSource([
        '501100001659' => [
            crossrefFunderRorCandidate(),
            crossrefFunderRorCandidate([
                'ror_id' => 'https://ror.org/04z8jg394',
                'ror_display_name' => 'GFZ Helmholtz Centre for Geosciences',
                'ror_types' => ['facility', 'funder'],
            ]),
        ],
    ]);

    $service = new CrossrefFunderRorDiscoveryService(
        inputProvider: new CrossrefFunderRorMatchInputProvider,
        mappingSource: $mappingSource,
    );

    $storedSuggestions = [];
    $progressMessages = [];

    $count = $service->discover(
        storeSuggestion: function () use (&$storedSuggestions): bool {
            $storedSuggestions[] = true;

            return true;
        },
        onProgress: function (string $message) use (&$progressMessages): void {
            $progressMessages[] = $message;
        },
    );

    expect($count)->toBe(0)
        ->and($storedSuggestions)->toBeEmpty()
        ->and($mappingSource->requestedFundrefIds)->toBe(['501100001659'])
        ->and(implode("\n", $progressMessages))->toContain('multiple_active_ror_matches');
});

it('suppresses exact mappings when all ROR candidates are inactive or not funder records', function (): void {
    $this->seed(FunderIdentifierTypeSeeder::class);

    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.005')->create();
    $crossrefType = crossrefFunderRorType('Crossref Funder ID');

    crossrefFunderRorFundingReference(
        resource: $resource,
        funderName: 'European Commission',
        identifier: 'https://doi.org/10.13039/501100000780',
        type: $crossrefType,
    );

    $mappingSource = new CrossrefFunderRorInMemoryMappingSource([
        '501100000780' => [
            crossrefFunderRorCandidate([
                'ror_id' => 'https://ror.org/00k4n6c32',
                'ror_display_name' => 'European Commission',
                'ror_status' => 'inactive',
                'ror_types' => ['funder', 'government'],
                'external_ids' => [
                    'fundref' => [
                        'all' => ['501100000780'],
                        'preferred' => '501100000780',
                    ],
                ],
            ]),
            crossrefFunderRorCandidate([
                'ror_id' => 'https://ror.org/03yrm5c26',
                'ror_display_name' => 'California Digital Library',
                'ror_status' => 'active',
                'ror_types' => ['archive'],
                'external_ids' => [
                    'fundref' => [
                        'all' => ['501100000780'],
                        'preferred' => '501100000780',
                    ],
                ],
            ]),
        ],
    ]);

    $service = new CrossrefFunderRorDiscoveryService(
        inputProvider: new CrossrefFunderRorMatchInputProvider,
        mappingSource: $mappingSource,
    );

    $storedSuggestions = [];
    $progressMessages = [];

    $count = $service->discover(
        storeSuggestion: function () use (&$storedSuggestions): bool {
            $storedSuggestions[] = true;

            return true;
        },
        onProgress: function (string $message) use (&$progressMessages): void {
            $progressMessages[] = $message;
        },
    );

    expect($count)->toBe(0)
        ->and($storedSuggestions)->toBeEmpty()
        ->and(implode("\n", $progressMessages))->toContain('only_inactive_or_withdrawn_ror_matches')
        ->and(implode("\n", $progressMessages))->toContain('ror_candidate_not_funder_type');
});

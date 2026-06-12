<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\ResourceRight;
use App\Models\Right;
use App\Services\Spdx\SpdxRightsDiscoveryService;
use App\Services\Spdx\SpdxRightsMatcher;
use App\Services\Spdx\SpdxRightsMatchInput;
use App\Services\Spdx\SpdxRightsMatchInputProvider;
use Illuminate\Support\Collection;

covers(SpdxRightsDiscoveryService::class);

function spdxRightsDiscoveryTestProvider(Collection $inputs): SpdxRightsMatchInputProvider
{
    return new class($inputs) extends SpdxRightsMatchInputProvider
    {
        /**
         * @param  Collection<int, SpdxRightsMatchInput>  $inputs
         */
        public function __construct(private readonly Collection $inputs) {}

        /**
         * @return Collection<int, SpdxRightsMatchInput>
         */
        #[Override]
        public function pendingInputs(): Collection
        {
            return $this->inputs;
        }
    };
}

it('stores suggestions for exact and alias matches while skipping non-SPDX statements', function () {
    $resource = Resource::factory()->create();
    Right::factory()->ccBy4()->create();

    $inputs = collect([
        new SpdxRightsMatchInput(
            resourceId: $resource->id,
            targetType: 'resource_right',
            targetId: 1001,
            rightsIdentifier: 'CC-BY-4.0',
            rightsIdentifierScheme: 'SPDX',
            source: 'datacite',
        ),
        new SpdxRightsMatchInput(
            resourceId: $resource->id,
            targetType: 'resource_right',
            targetId: 1002,
            rightsText: 'CC BY 4.0',
            source: 'legacy',
        ),
        new SpdxRightsMatchInput(
            resourceId: $resource->id,
            targetType: 'resource_right',
            targetId: 1003,
            rightsText: 'Commercial end user licensing agreement required.',
            source: 'legacy',
        ),
    ]);

    $service = new SpdxRightsDiscoveryService(
        inputProvider: spdxRightsDiscoveryTestProvider($inputs),
        matcher: new SpdxRightsMatcher,
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
        ->and($storedSuggestions[0]['targetId'])->toBe(1001)
        ->and($storedSuggestions[0]['suggestedValue'])->toBe('CC-BY-4.0')
        ->and($storedSuggestions[0]['metadata']['evidence']['matched_from'])->toBe('resource_rights.rights_identifier')
        ->and($storedSuggestions[1]['targetId'])->toBe(1002)
        ->and($storedSuggestions[1]['metadata']['current'])->toBe([
            'rights' => 'CC BY 4.0',
            'source' => 'legacy',
        ])
        ->and($storedSuggestions[1]['metadata']['proposed']['rights_uri'])->toBe('https://creativecommons.org/licenses/by/4.0/')
        ->and($progressMessages)->toContain('Stored 2 SPDX suggestion(s); skipped 1 unsupported and 0 insufficient statement(s).');
});

it('returns no pending inputs when no unresolved raw rights exist', function () {
    $provider = new SpdxRightsMatchInputProvider;

    expect($provider->pendingInputs())->toHaveCount(0);
});

it('reads unresolved raw resource_rights rows and ignores already linked rows', function () {
    $resource = Resource::factory()->create();
    $right = Right::factory()->ccBy4()->create();

    $pending = ResourceRight::create([
        'resource_id' => $resource->id,
        'rights_text' => 'CC BY 4.0',
        'rights_uri' => 'http://creativecommons.org/licenses/by/4.0',
        'source' => 'xml-upload',
    ]);

    ResourceRight::create([
        'resource_id' => $resource->id,
        'rights_id' => $right->id,
        'rights_text' => 'Creative Commons Attribution 4.0 International',
        'source' => 'xml-upload',
    ]);

    $inputs = (new SpdxRightsMatchInputProvider)->pendingInputs();

    expect($inputs)->toHaveCount(1)
        ->and($inputs->first()->targetId)->toBe($pending->id)
        ->and($inputs->first()->rightsText)->toBe('CC BY 4.0')
        ->and($inputs->first()->rightsUri)->toBe('http://creativecommons.org/licenses/by/4.0');
});

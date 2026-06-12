<?php

declare(strict_types=1);

use App\Models\AssistantSuggestion;
use App\Models\Resource;
use App\Models\ResourceRight;
use App\Models\Right;
use App\Services\Spdx\SpdxLicenseLookup;
use Modules\Assistants\SpdxLicenseSuggestion\Assistant;

function spdxSuggestionMetadata(array $overrides = []): array
{
    return array_replace_recursive([
        'contract_version' => '1.1',
        'action' => 'link_right',
        'current' => [
            'rights' => 'CC BY 4.0',
            'rights_uri' => 'http://creativecommons.org/licenses/by/4.0',
            'source' => 'xml-upload',
        ],
        'proposed' => [
            'rights' => 'Creative Commons Attribution 4.0 International',
            'rights_uri' => 'https://creativecommons.org/licenses/by/4.0/',
            'rights_identifier' => 'CC-BY-4.0',
            'rights_identifier_scheme' => SpdxLicenseLookup::RIGHTS_IDENTIFIER_SCHEME,
            'scheme_uri' => SpdxLicenseLookup::SCHEME_URI,
            'language' => 'en',
        ],
        'source' => 'spdx',
        'evidence' => [
            'matched_from' => 'resource_rights.rights_text_alias',
            'reason' => 'Matched alias CC BY 4.0.',
        ],
    ], $overrides);
}

function createSpdxSuggestion(Assistant $assistant, Resource $resource, ResourceRight $resourceRight, array $metadata = []): AssistantSuggestion
{
    return AssistantSuggestion::create([
        'assistant_id' => $assistant->getId(),
        'resource_id' => $resource->id,
        'target_type' => 'resource_right',
        'target_id' => $resourceRight->id,
        'suggested_value' => 'CC-BY-4.0',
        'suggested_label' => 'Creative Commons Attribution 4.0 International',
        'similarity_score' => 0.92,
        'metadata' => spdxSuggestionMetadata($metadata),
        'discovered_at' => now(),
    ]);
}

it('accepts a SPDX suggestion by linking only the targeted resource_right row', function () {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $right = Right::factory()->ccBy4()->create();
    $resourceRight = ResourceRight::create([
        'resource_id' => $resource->id,
        'rights_text' => 'CC BY 4.0',
        'rights_uri' => 'http://creativecommons.org/licenses/by/4.0',
        'source' => 'xml-upload',
    ]);
    $suggestion = createSpdxSuggestion($assistant, $resource, $resourceRight);

    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result['success'])->toBeTrue()
        ->and(AssistantSuggestion::find($suggestion->id))->toBeNull()
        ->and($resourceRight->fresh()->rights_id)->toBe($right->id)
        ->and($resourceRight->fresh()->rights_text)->toBe('CC BY 4.0')
        ->and($resourceRight->fresh()->rights_uri)->toBe('http://creativecommons.org/licenses/by/4.0')
        ->and($resourceRight->fresh()->language)->toBe('en')
        ->and(Right::where('identifier', 'CC-BY-4.0')->count())->toBe(1);
});

it('reuses an existing catalog right and fills empty SPDX fields without overwriting curator values', function () {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $existingRight = Right::create([
        'identifier' => 'CC-BY-4.0',
        'name' => 'Curator Label',
        'uri' => null,
        'scheme_uri' => null,
    ]);
    $resourceRight = ResourceRight::create([
        'resource_id' => $resource->id,
        'rights_text' => 'CC BY 4.0',
    ]);
    $suggestion = createSpdxSuggestion($assistant, $resource, $resourceRight);

    $result = $assistant->acceptSuggestion($suggestion->id);
    $existingRight->refresh();

    expect($result['success'])->toBeTrue()
        ->and($resourceRight->fresh()->rights_id)->toBe($existingRight->id)
        ->and($existingRight->name)->toBe('Curator Label')
        ->and($existingRight->uri)->toBe('https://creativecommons.org/licenses/by/4.0/')
        ->and($existingRight->scheme_uri)->toBe(SpdxLicenseLookup::SCHEME_URI)
        ->and(Right::where('identifier', 'CC-BY-4.0')->count())->toBe(1);
});

it('does not accept non-SPDX custom license metadata', function () {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $resourceRight = ResourceRight::create([
        'resource_id' => $resource->id,
        'rights_text' => 'Custom internal license',
        'source' => 'legacy-sumario',
    ]);
    $suggestion = createSpdxSuggestion($assistant, $resource, $resourceRight, [
        'source' => 'manual',
        'proposed' => [
            'rights_identifier_scheme' => 'CUSTOM',
            'scheme_uri' => 'https://example.test/licenses/',
        ],
    ]);

    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result['success'])->toBeFalse()
        ->and($resourceRight->fresh()->rights_id)->toBeNull()
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull()
        ->and(Right::where('identifier', 'CC-BY-4.0')->count())->toBe(0);
});

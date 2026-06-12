<?php

declare(strict_types=1);

use App\Models\AssistantSuggestion;
use App\Models\Resource;
use App\Models\ResourceRight;
use App\Models\Right;
use App\Services\Spdx\SpdxLicenseLookup;
use App\Services\Spdx\SpdxRightsAcceptanceService;
use Modules\Assistants\SpdxLicenseSuggestion\Assistant;

covers(SpdxRightsAcceptanceService::class);

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

it('creates the SPDX catalog right when accepting a trusted suggestion without an existing right', function () {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $resourceRight = ResourceRight::create([
        'resource_id' => $resource->id,
        'rights_text' => 'CC BY 4.0',
    ]);
    $suggestion = createSpdxSuggestion($assistant, $resource, $resourceRight);

    $result = $assistant->acceptSuggestion($suggestion->id);
    $createdRight = Right::where('identifier', 'CC-BY-4.0')->first();

    expect($result['success'])->toBeTrue()
        ->and($createdRight)->not->toBeNull()
        ->and($createdRight?->name)->toBe('Creative Commons Attribution 4.0 International')
        ->and($createdRight?->uri)->toBe('https://creativecommons.org/licenses/by/4.0/')
        ->and($createdRight?->scheme_uri)->toBe(SpdxLicenseLookup::SCHEME_URI)
        ->and($resourceRight->fresh()->rights_id)->toBe($createdRight?->id);
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

it('merges raw rights context when the resource already has the suggested SPDX license', function () {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $right = Right::factory()->ccBy4()->create();
    $linkedResourceRight = ResourceRight::create([
        'resource_id' => $resource->id,
        'rights_id' => $right->id,
    ]);
    $rawResourceRight = ResourceRight::create([
        'resource_id' => $resource->id,
        'rights_text' => 'CC BY 4.0',
        'rights_uri' => 'http://creativecommons.org/licenses/by/4.0',
        'source' => 'legacy-sumario',
    ]);
    $suggestion = createSpdxSuggestion($assistant, $resource, $rawResourceRight);

    $result = $assistant->acceptSuggestion($suggestion->id);
    $linkedResourceRight->refresh();

    expect($result['success'])->toBeTrue()
        ->and(AssistantSuggestion::find($suggestion->id))->toBeNull()
        ->and(ResourceRight::find($rawResourceRight->id))->toBeNull()
        ->and($linkedResourceRight->rights_id)->toBe($right->id)
        ->and($linkedResourceRight->rights_text)->toBe('CC BY 4.0')
        ->and($linkedResourceRight->rights_uri)->toBe('http://creativecommons.org/licenses/by/4.0')
        ->and($linkedResourceRight->source)->toBe('legacy-sumario')
        ->and($linkedResourceRight->language)->toBe('en')
        ->and(ResourceRight::where('resource_id', $resource->id)->where('rights_id', $right->id)->count())->toBe(1);
});

it('treats an already linked target row as accepted when it points to the suggested right', function () {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $right = Right::factory()->ccBy4()->create();
    $resourceRight = ResourceRight::create([
        'resource_id' => $resource->id,
        'rights_id' => $right->id,
    ]);
    $suggestion = createSpdxSuggestion($assistant, $resource, $resourceRight);

    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result['success'])->toBeTrue()
        ->and(AssistantSuggestion::find($suggestion->id))->toBeNull()
        ->and($resourceRight->fresh()->rights_id)->toBe($right->id)
        ->and($resourceRight->fresh()->language)->toBe('en');
});

it('does not accept a suggestion when the targeted rights row disappeared', function () {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $resourceRight = ResourceRight::create([
        'resource_id' => $resource->id,
        'rights_text' => 'CC BY 4.0',
    ]);
    $suggestion = createSpdxSuggestion($assistant, $resource, $resourceRight);
    $resourceRight->delete();

    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('The rights statement for this SPDX suggestion no longer exists.')
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull();
});

it('does not accept a SPDX suggestion for an unsupported target type', function () {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();

    $suggestion = AssistantSuggestion::create([
        'assistant_id' => $assistant->getId(),
        'resource_id' => $resource->id,
        'target_type' => 'resource',
        'target_id' => $resource->id,
        'suggested_value' => 'CC-BY-4.0',
        'suggested_label' => 'Creative Commons Attribution 4.0 International',
        'metadata' => spdxSuggestionMetadata(),
        'discovered_at' => now(),
    ]);

    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('This SPDX suggestion targets an unsupported entity type.')
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull();
});

it('does not accept mismatched suggestion values and proposed identifiers', function () {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $resourceRight = ResourceRight::create([
        'resource_id' => $resource->id,
        'rights_text' => 'CC BY 4.0',
    ]);
    $suggestion = createSpdxSuggestion($assistant, $resource, $resourceRight, [
        'proposed' => [
            'rights_identifier' => 'MIT',
        ],
    ]);

    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('The suggestion value and proposed SPDX identifier do not match.')
        ->and($resourceRight->fresh()->rights_id)->toBeNull()
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull();
});

it('does not accept a suggestion for a target already linked to a different catalog right', function () {
    $assistant = app(Assistant::class);
    $resource = Resource::factory()->create();
    $otherRight = Right::create([
        'identifier' => 'MIT',
        'name' => 'MIT License',
        'uri' => 'https://spdx.org/licenses/MIT.html',
        'scheme_uri' => SpdxLicenseLookup::SCHEME_URI,
    ]);
    $resourceRight = ResourceRight::create([
        'resource_id' => $resource->id,
        'rights_id' => $otherRight->id,
    ]);
    $suggestion = createSpdxSuggestion($assistant, $resource, $resourceRight);

    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('This rights statement is already linked to a different catalog right. Please refresh the assistant list.')
        ->and($resourceRight->fresh()->rights_id)->toBe($otherRight->id)
        ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull();
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

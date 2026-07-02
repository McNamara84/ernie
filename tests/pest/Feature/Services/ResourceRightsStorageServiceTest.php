<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\ResourceRight;
use App\Models\Right;
use App\Services\Rights\ResourceRightsStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

covers(ResourceRightsStorageService::class, ResourceRight::class);

beforeEach(function (): void {
    $this->service = app(ResourceRightsStorageService::class);
    $this->resource = Resource::factory()->create();
});

it('normalizes raw rights statement key variants and ignores metadata-only rows', function (): void {
    $payload = $this->service->normalizeStatement([
        'rights_text' => ' CC BY 4.0 ',
        'rightsURI' => ' http://creativecommons.org/licenses/by/4.0 ',
        'rights_identifier' => ' CC-BY-4.0 ',
        'rightsIdentifierScheme' => ' SPDX ',
        'schemeURI' => ' https://spdx.org/licenses/ ',
        'language' => ' en ',
        'source' => ' xml-upload ',
    ], 'fallback-source', 'de');

    expect($payload)->toBe([
        'rights_text' => 'CC BY 4.0',
        'rights_uri' => 'http://creativecommons.org/licenses/by/4.0',
        'rights_identifier' => 'CC-BY-4.0',
        'rights_identifier_scheme' => 'SPDX',
        'scheme_uri' => 'https://spdx.org/licenses/',
        'language' => 'en',
        'source' => 'xml-upload',
    ])
        ->and($this->service->normalizeStatement([
            'schemeUri' => 'https://spdx.org/licenses/',
            'source' => ['ignored'],
        ], 'fallback-source', 'de'))->toBeNull();
});

it('persists imported statements by resolving catalog rights from identifier name and URI', function (): void {
    $mit = Right::create([
        'identifier' => 'MIT',
        'name' => 'MIT License',
        'uri' => 'https://spdx.org/licenses/MIT.html',
        'scheme_uri' => 'https://spdx.org/licenses/',
    ]);
    $ccBy = Right::factory()->ccBy4()->create();
    $apache = Right::create([
        'identifier' => 'Apache-2.0',
        'name' => 'Apache License 2.0',
        'uri' => 'https://www.apache.org/licenses/LICENSE-2.0',
        'scheme_uri' => 'https://spdx.org/licenses/',
    ]);

    $this->service->persistImportedStatements($this->resource, [
        ['rightsIdentifier' => 'MIT', 'rights' => 'MIT License', 'source' => 'json-upload'],
        ['rights' => $ccBy->name],
        ['rightsUri' => $apache->uri],
        ['rights' => 'Use requires individual permission.', 'lang' => null],
        ['schemeUri' => 'https://spdx.org/licenses/'],
    ], 'xml-upload', 'de');

    expect(ResourceRight::where('resource_id', $this->resource->id)->count())->toBe(4)
        ->and(ResourceRight::where('resource_id', $this->resource->id)->where('rights_id', $mit->id)->sole()->rights_identifier)->toBe('MIT')
        ->and(ResourceRight::where('resource_id', $this->resource->id)->where('rights_id', $ccBy->id)->exists())->toBeTrue()
        ->and(ResourceRight::where('resource_id', $this->resource->id)->where('rights_id', $apache->id)->exists())->toBeTrue();

    $rawStatement = ResourceRight::where('resource_id', $this->resource->id)
        ->whereNull('rights_id')
        ->sole();

    expect($rawStatement->rights_text)->toBe('Use requires individual permission.')
        ->and($rawStatement->language)->toBe('de')
        ->and($rawStatement->source)->toBe('xml-upload')
        ->and($rawStatement->isResolved())->toBeFalse()
        ->and($rawStatement->resource->is($this->resource))->toBeTrue()
        ->and($rawStatement->right)->toBeNull();
});

it('reuses an existing unresolved raw rights row for identical imports', function (): void {
    $this->service->persistImportedStatements($this->resource, [
        [
            'rights' => 'Custom repository license',
            'rightsUri' => 'https://example.test/license',
            'source' => 'xml-upload',
        ],
    ], 'xml-upload', null);

    $this->service->persistImportedStatements($this->resource, [
        [
            'rights' => 'Custom repository license',
            'rightsUri' => 'https://example.test/license',
            'source' => 'xml-upload',
        ],
    ], 'xml-upload', null);

    expect(ResourceRight::where('resource_id', $this->resource->id)->whereNull('rights_id')->count())->toBe(1);
});

it('syncs editor rights without losing unresolved imports and rejects unknown selected licenses', function (): void {
    $selectedRight = Right::factory()->ccBy4()->create();
    $removedRight = Right::create([
        'identifier' => 'MIT',
        'name' => 'MIT License',
        'uri' => 'https://spdx.org/licenses/MIT.html',
        'scheme_uri' => 'https://spdx.org/licenses/',
    ]);

    ResourceRight::create([
        'resource_id' => $this->resource->id,
        'rights_id' => $removedRight->id,
    ]);
    $unresolved = ResourceRight::create([
        'resource_id' => $this->resource->id,
        'rights_text' => 'Legacy license text',
        'source' => 'legacy-sumario',
    ]);

    $this->service->syncEditorRights($this->resource, [' '.$selectedRight->identifier.' '], [
        [
            'rights' => 'Legacy license text',
            'rightsUri' => 'https://example.test/legacy-license',
            'source' => 'editor-import',
        ],
    ], 'en');

    expect(ResourceRight::where('resource_id', $this->resource->id)->where('rights_id', $removedRight->id)->exists())->toBeFalse()
        ->and(ResourceRight::where('resource_id', $this->resource->id)->where('rights_id', $selectedRight->id)->exists())->toBeTrue();

    $unresolved->refresh();
    expect($unresolved->rights_text)->toBe('Legacy license text')
        ->and($unresolved->rights_uri)->toBeNull()
        ->and($unresolved->source)->toBe('legacy-sumario');

    $this->service->syncEditorRights($this->resource, [], [], null);
    expect(ResourceRight::where('resource_id', $this->resource->id)->whereNotNull('rights_id')->exists())->toBeFalse()
        ->and(ResourceRight::find($unresolved->id))->not->toBeNull();

    expect(fn () => $this->service->syncEditorRights($this->resource, ['Unknown-License'], [], null))
        ->toThrow(ValidationException::class);
});

it('does not recreate removed linked rights from round-tripped raw import context', function (): void {
    $right = Right::factory()->ccBy4()->create();

    ResourceRight::create([
        'resource_id' => $this->resource->id,
        'rights_id' => $right->id,
        'rights_text' => 'CC BY 4.0',
        'rights_uri' => 'http://creativecommons.org/licenses/by/4.0',
        'rights_identifier' => 'CC-BY-4.0',
        'rights_identifier_scheme' => 'SPDX',
        'scheme_uri' => 'https://spdx.org/licenses/',
        'source' => 'xml-upload',
    ]);

    $this->service->syncEditorRights($this->resource, [], [
        [
            'rights' => 'CC BY 4.0',
            'rightsUri' => 'http://creativecommons.org/licenses/by/4.0',
            'rightsIdentifier' => 'CC-BY-4.0',
            'rightsIdentifierScheme' => 'SPDX',
            'schemeUri' => 'https://spdx.org/licenses/',
            'source' => 'xml-upload',
        ],
    ], 'en');

    expect(ResourceRight::where('resource_id', $this->resource->id)->where('rights_id', $right->id)->exists())->toBeFalse();

    $rawStatement = ResourceRight::where('resource_id', $this->resource->id)
        ->whereNull('rights_id')
        ->sole();

    expect($rawStatement->rights_identifier)->toBe('CC-BY-4.0')
        ->and($rawStatement->rights_identifier_scheme)->toBe('SPDX')
        ->and($rawStatement->rights_text)->toBe('CC BY 4.0');
});

it('keeps round-tripped raw import context linked when the matching right is still selected', function (): void {
    $right = Right::factory()->ccBy4()->create();

    $this->service->syncEditorRights($this->resource, [$right->identifier], [
        [
            'rights' => 'CC BY 4.0',
            'rightsUri' => 'http://creativecommons.org/licenses/by/4.0',
            'rightsIdentifier' => 'CC-BY-4.0',
            'rightsIdentifierScheme' => 'SPDX',
            'schemeUri' => 'https://spdx.org/licenses/',
            'source' => 'xml-upload',
        ],
    ], 'en');

    $linkedStatement = ResourceRight::where('resource_id', $this->resource->id)
        ->where('rights_id', $right->id)
        ->sole();

    expect($linkedStatement->rights_identifier)->toBe('CC-BY-4.0')
        ->and($linkedStatement->rights_uri)->toBe('http://creativecommons.org/licenses/by/4.0')
        ->and(ResourceRight::where('resource_id', $this->resource->id)->whereNull('rights_id')->exists())->toBeFalse();
});

it('links imported rights rows to selected custom catalog rights', function (): void {
    $customRight = Right::query()->create([
        'identifier' => 'CUSTOM-COMMUNITY-123456789ABC',
        'name' => 'Community License',
        'uri' => 'https://example.test/community-license',
        'scheme_uri' => null,
        'is_active' => true,
        'is_elmo_active' => false,
    ]);
    $sourceRow = ResourceRight::query()->create([
        'resource_id' => $this->resource->id,
        'rights_text' => 'Community License',
        'rights_uri' => 'https://example.test/community-license',
        'source' => 'xml-upload',
    ]);

    $this->service->syncEditorRights(
        $this->resource,
        [$customRight->identifier],
        [],
        null,
        [$sourceRow->id => $customRight->id],
        true,
    );

    $sourceRow->refresh();

    expect($sourceRow->rights_id)->toBe($customRight->id)
        ->and($sourceRow->rights_text)->toBe('Community License')
        ->and($sourceRow->rights_uri)->toBe('https://example.test/community-license')
        ->and(ResourceRight::query()->where('resource_id', $this->resource->id)->whereNull('rights_id')->exists())->toBeFalse();
});

it('updates retained custom source rows before removing unselected linked rights', function (): void {
    $oldCustomRight = Right::query()->create([
        'identifier' => 'CUSTOM-OLD-123456789ABC',
        'name' => 'Old Custom License',
        'uri' => 'https://example.test/old-license',
        'scheme_uri' => null,
    ]);
    $newCustomRight = Right::query()->create([
        'identifier' => 'CUSTOM-NEW-123456789ABC',
        'name' => 'New Custom License',
        'uri' => 'https://example.test/new-license',
        'scheme_uri' => null,
    ]);
    $sourceRow = ResourceRight::query()->create([
        'resource_id' => $this->resource->id,
        'rights_id' => $oldCustomRight->id,
        'rights_text' => 'Old Custom License',
        'rights_uri' => 'https://example.test/old-license',
    ]);

    $this->service->syncEditorRights(
        $this->resource,
        [$newCustomRight->identifier],
        [],
        null,
        [$sourceRow->id => $newCustomRight->id],
        true,
    );

    $sourceRow->refresh();

    expect($sourceRow->rights_id)->toBe($newCustomRight->id)
        ->and(ResourceRight::query()->where('resource_id', $this->resource->id)->count())->toBe(1);
});

it('merges imported source rows into existing linked custom rows', function (): void {
    $customRight = Right::query()->create([
        'identifier' => 'CUSTOM-MERGE-123456789ABC',
        'name' => 'Merged Custom License',
        'uri' => 'https://example.test/merged-license',
        'scheme_uri' => null,
    ]);
    $linkedRow = ResourceRight::query()->create([
        'resource_id' => $this->resource->id,
        'rights_id' => $customRight->id,
    ]);
    $sourceRow = ResourceRight::query()->create([
        'resource_id' => $this->resource->id,
        'rights_text' => 'Merged Custom License',
        'rights_uri' => 'https://example.test/merged-license',
        'source' => 'xml-upload',
    ]);

    $this->service->syncEditorRights(
        $this->resource,
        [$customRight->identifier],
        [],
        null,
        [$sourceRow->id => $customRight->id],
        true,
    );

    $linkedRow->refresh();

    expect(ResourceRight::query()->where('resource_id', $this->resource->id)->count())->toBe(1)
        ->and($linkedRow->rights_text)->toBe('Merged Custom License')
        ->and($linkedRow->rights_uri)->toBe('https://example.test/merged-license')
        ->and($linkedRow->source)->toBe('xml-upload')
        ->and(ResourceRight::query()->find($sourceRow->id))->toBeNull();
});
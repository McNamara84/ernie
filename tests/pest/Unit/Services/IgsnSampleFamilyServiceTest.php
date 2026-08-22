<?php

declare(strict_types=1);

use App\Models\AlternateIdentifier;
use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Services\IgsnSampleFamilyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

covers(IgsnSampleFamilyService::class);

/**
 * @return array{resource: Resource, metadata: IgsnMetadata, landingPage: LandingPage|null}
 */
function createIssue1127FamilyMember(
    string $handle,
    ?string $name = null,
    ?Resource $parent = null,
    bool $withLandingPage = true,
    bool $published = true,
    ?string $sampleType = null,
): array {
    $resource = Resource::factory()->create([
        'doi' => '10.60510/'.strtolower($handle),
        'identifier_type' => 'IGSN',
    ]);

    $metadata = IgsnMetadata::query()->create([
        'resource_id' => $resource->id,
        'parent_resource_id' => $parent?->id,
        'sample_type' => $sampleType,
        'upload_status' => IgsnMetadata::STATUS_REGISTERED,
    ]);

    if ($name !== null) {
        AlternateIdentifier::query()->create([
            'resource_id' => $resource->id,
            'value' => $name,
            'type' => 'Local accession number',
            'position' => 0,
        ]);
    }

    $landingPage = null;
    if ($withLandingPage) {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => $resource->doi,
            'slug' => strtolower($handle),
            'template' => 'default_gfz_igsn',
            'is_published' => $published,
            'published_at' => $published ? now() : null,
        ]);
    }

    return compact('resource', 'metadata', 'landingPage');
}

it('returns the same complete sorted hierarchy from a root, intermediate node, or leaf', function () {
    $root = createIssue1127FamilyMember('GFROOT001', 'Station Alpha', sampleType: 'Hole');
    $coreB = createIssue1127FamilyMember('GFCORE002', 'Core B', $root['resource'], sampleType: 'Core');
    $coreA = createIssue1127FamilyMember('GFCORE001', 'Core A', $root['resource'], sampleType: 'Core');
    $sample = createIssue1127FamilyMember('GFSAMPLE01', 'Sample 1', $coreB['resource'], sampleType: 'Individual Sample');

    $service = new IgsnSampleFamilyService;
    $fromRoot = $service->forResource($root['resource']);
    $fromIntermediate = $service->forResource($coreB['resource']);
    $fromLeaf = $service->forResource($sample['resource']);

    expect($fromRoot)->not->toBeNull()
        ->and($fromIntermediate)->toBe($fromRoot)
        ->and($fromLeaf)->toBe($fromRoot)
        ->and($fromRoot['member_count'])->toBe(4)
        ->and($fromRoot['root']['name'])->toBe('Station Alpha')
        ->and($fromRoot['root']['igsn'])->toBe('GFROOT001')
        ->and($fromRoot['root']['sample_type'])->toBe('Hole')
        ->and(array_column($fromRoot['root']['children'], 'name'))->toBe(['Core A', 'Core B'])
        ->and(array_column($fromRoot['root']['children'], 'sample_type'))->toBe(['Core', 'Core'])
        ->and($fromRoot['root']['children'][1]['children'][0]['name'])->toBe('Sample 1')
        ->and($fromRoot['root']['children'][1]['children'][0]['igsn'])->toBe('GFSAMPLE01')
        ->and($fromRoot['root']['children'][1]['children'][0]['sample_type'])->toBe('Individual Sample');

    expect($service->resourceIdsForResourceId($coreA['resource']->id))
        ->toEqualCanonicalizing([
            $root['resource']->id,
            $coreA['resource']->id,
            $coreB['resource']->id,
            $sample['resource']->id,
        ]);
});

it('uses the first positioned local accession number and exposes only published landing page URLs', function () {
    $root = createIssue1127FamilyMember('GFROOT002', null);

    AlternateIdentifier::query()->insert([
        [
            'resource_id' => $root['resource']->id,
            'value' => 'Later name',
            'type' => 'Local accession number',
            'position' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'resource_id' => $root['resource']->id,
            'value' => 'Preferred name',
            'type' => 'Local accession number',
            'position' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $published = createIssue1127FamilyMember('GFCHILD01', 'Published child', $root['resource'], true, true);
    $draft = createIssue1127FamilyMember('GFCHILD02', 'Draft child', $root['resource'], true, false);
    $withoutPage = createIssue1127FamilyMember('GFCHILD03', 'No page child', $root['resource'], false);

    $family = (new IgsnSampleFamilyService)->forResource($draft['resource']);

    expect($family)->not->toBeNull()
        ->and($family['root']['name'])->toBe('Preferred name')
        ->and($family['root']['landing_page']['public_url'])->toBe($root['landingPage']->public_url);

    $children = collect($family['root']['children'])->keyBy('resource_id');

    expect($children[$published['resource']->id]['landing_page']['public_url'])->toBe($published['landingPage']->public_url)
        ->and($children[$draft['resource']->id]['landing_page'])->toBeNull()
        ->and($children[$withoutPage['resource']->id]['landing_page'])->toBeNull();
});

it('returns null for standalone and non-IGSN resources', function () {
    $standalone = createIssue1127FamilyMember('GFSTAND001', 'Standalone');
    $regularResource = Resource::factory()->create(['doi' => '10.5880/regular-resource']);

    $service = new IgsnSampleFamilyService;

    expect($service->forResource($standalone['resource']))->toBeNull()
        ->and($service->forResource($regularResource))->toBeNull()
        ->and($service->resourceIdsForResourceId($standalone['resource']->id))->toBe([$standalone['resource']->id])
        ->and($service->resourceIdsForResourceId($regularResource->id))->toBe([]);
});

it('terminates cyclic metadata safely and emits every family member once', function () {
    Log::spy();

    $first = createIssue1127FamilyMember('GFCYCLE01', 'Cycle A');
    $second = createIssue1127FamilyMember('GFCYCLE02', 'Cycle B', $first['resource']);

    $first['metadata']->update(['parent_resource_id' => $second['resource']->id]);

    $family = (new IgsnSampleFamilyService)->forResource($second['resource']);

    expect($family)->not->toBeNull()
        ->and($family['member_count'])->toBe(2)
        ->and($family['root']['resource_id'])->toBe(min($first['resource']->id, $second['resource']->id))
        ->and($family['root']['children'])->toHaveCount(1)
        ->and($family['root']['children'][0]['children'])->toBe([]);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Cycle detected while resolving IGSN sample family'
            && $context['seed_resource_id'] === $second['resource']->id)
        ->atLeast()->once();
});

it('loads siblings in batches instead of issuing one query per sibling', function () {
    $root = createIssue1127FamilyMember('GFBATCH001', 'Batch root');

    foreach (range(1, 12) as $index) {
        createIssue1127FamilyMember('GFBATCH'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT), "Child {$index}", $root['resource']);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $family = (new IgsnSampleFamilyService)->forResource($root['resource']);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($family['member_count'])->toBe(13)
        ->and(count($queries))->toBeLessThan(15);
});

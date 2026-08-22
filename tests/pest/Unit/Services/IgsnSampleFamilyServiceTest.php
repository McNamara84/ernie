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
    bool $isPrivate = false,
): array {
    $resource = Resource::factory()->create([
        'doi' => '10.60510/'.strtolower($handle),
        'identifier_type' => 'IGSN',
    ]);

    $metadata = IgsnMetadata::query()->create([
        'resource_id' => $resource->id,
        'parent_resource_id' => $parent?->id,
        'sample_type' => $sampleType,
        'is_private' => $isPrivate,
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

it('redacts private family members while preserving their public descendants', function () {
    $root = createIssue1127FamilyMember('GFVISIBLE01', 'Visible root', sampleType: 'Hole');
    $private = createIssue1127FamilyMember(
        'GFPRIVATE01',
        'Z secret accession',
        $root['resource'],
        sampleType: 'Secret sample type',
        isPrivate: true,
    );
    $secondPrivate = createIssue1127FamilyMember(
        'GFPRIVATE02',
        'A secret accession',
        $root['resource'],
        sampleType: 'Another secret type',
        isPrivate: true,
    );
    $descendant = createIssue1127FamilyMember(
        'GFVISIBLE02',
        'Visible descendant',
        $private['resource'],
        sampleType: 'Individual Sample',
    );

    $family = (new IgsnSampleFamilyService)->forResource($descendant['resource']);

    expect($family)->not->toBeNull()
        ->and($family['member_count'])->toBe(4)
        ->and($family['root']['children'])->toHaveCount(2)
        ->and(array_column($family['root']['children'], 'resource_id'))->toBe([
            $private['resource']->id,
            $secondPrivate['resource']->id,
        ]);

    $privateNode = collect($family['root']['children'])->keyBy('resource_id')[$private['resource']->id];

    expect($privateNode['resource_id'])->toBe($private['resource']->id)
        ->and($privateNode['name'])->toBe('Private sample')
        ->and($privateNode['igsn'])->toBeNull()
        ->and($privateNode['sample_type'])->toBeNull()
        ->and($privateNode['landing_page'])->toBeNull()
        ->and($privateNode['children'])->toHaveCount(1)
        ->and($privateNode['children'][0]['name'])->toBe('Visible descendant')
        ->and($privateNode['children'][0]['igsn'])->toBe('GFVISIBLE02');

    $serializedFamily = json_encode($family, JSON_THROW_ON_ERROR);

    expect($serializedFamily)->not->toContain('Z secret accession')
        ->and($serializedFamily)->not->toContain('GFPRIVATE01')
        ->and($serializedFamily)->not->toContain('Secret sample type')
        ->and($serializedFamily)->not->toContain($private['landingPage']->public_url)
        ->and($serializedFamily)->not->toContain('A secret accession')
        ->and($serializedFamily)->not->toContain('GFPRIVATE02')
        ->and($serializedFamily)->not->toContain('Another secret type')
        ->and($serializedFamily)->not->toContain($secondPrivate['landingPage']->public_url);
});

it('redacts a private family root without hiding its public descendants', function () {
    $privateRoot = createIssue1127FamilyMember(
        'GFPRIVROOT',
        'Secret root accession',
        sampleType: 'Secret root type',
        isPrivate: true,
    );
    $child = createIssue1127FamilyMember('GFPUBLIC01', 'Public child', $privateRoot['resource'], sampleType: 'Core');

    $family = (new IgsnSampleFamilyService)->forResource($child['resource']);

    expect($family)->not->toBeNull()
        ->and($family['root']['name'])->toBe('Private sample')
        ->and($family['root']['igsn'])->toBeNull()
        ->and($family['root']['sample_type'])->toBeNull()
        ->and($family['root']['landing_page'])->toBeNull()
        ->and($family['root']['children'])->toHaveCount(1)
        ->and($family['root']['children'][0]['resource_id'])->toBe($child['resource']->id)
        ->and($family['root']['children'][0]['name'])->toBe('Public child');
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

it('chooses the stable root only from the cycle when a lower-ID leaf points into it', function () {
    $leaf = createIssue1127FamilyMember('GFCYCLELEAF', 'Lower-ID leaf');
    $firstCycleMember = createIssue1127FamilyMember('GFCYCLEA', 'Cycle A');
    $secondCycleMember = createIssue1127FamilyMember('GFCYCLEB', 'Cycle B', $firstCycleMember['resource']);

    $leaf['metadata']->update(['parent_resource_id' => $firstCycleMember['resource']->id]);
    $firstCycleMember['metadata']->update(['parent_resource_id' => $secondCycleMember['resource']->id]);

    $service = new IgsnSampleFamilyService;
    $fromLeaf = $service->forResource($leaf['resource']);
    $fromFirstCycleMember = $service->forResource($firstCycleMember['resource']);
    $fromSecondCycleMember = $service->forResource($secondCycleMember['resource']);

    expect($leaf['resource']->id)->toBeLessThan($firstCycleMember['resource']->id)
        ->and($fromLeaf)->not->toBeNull()
        ->and($fromFirstCycleMember)->toBe($fromLeaf)
        ->and($fromSecondCycleMember)->toBe($fromLeaf)
        ->and($fromLeaf['member_count'])->toBe(3)
        ->and($fromLeaf['root']['resource_id'])->toBe(min($firstCycleMember['resource']->id, $secondCycleMember['resource']->id))
        ->and($fromLeaf['root']['resource_id'])->not->toBe($leaf['resource']->id)
        ->and(collect($fromLeaf['root']['children'])->pluck('resource_id')->all())
        ->toEqualCanonicalizing([$leaf['resource']->id, $secondCycleMember['resource']->id]);
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
        ->and($family['root']['children'])->toHaveCount(12)
        ->and(collect($family['root']['children'])->pluck('resource_id')->unique())->toHaveCount(12)
        ->and(count($queries))->toBeLessThan(15);
});

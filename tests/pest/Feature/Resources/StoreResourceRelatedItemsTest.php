<?php

declare(strict_types=1);

use App\Models\ContributorType;
use App\Models\Datacenter;
use App\Models\DescriptionType;
use App\Models\Language;
use App\Models\RelatedItem;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedRelatedItemsBaseline(): array
{
    $dataset = ResourceType::firstOrCreate(
        ['slug' => 'Dataset'],
        ['name' => 'Dataset', 'is_active' => true, 'is_elmo_active' => true],
    );
    ResourceType::firstOrCreate(
        ['slug' => 'JournalArticle'],
        ['name' => 'JournalArticle', 'is_active' => true, 'is_elmo_active' => true],
    );

    Language::firstOrCreate(
        ['code' => 'en'],
        ['name' => 'English', 'active' => true, 'elmo_active' => true],
    );

    Right::firstOrCreate(
        ['identifier' => 'cc-by-4'],
        [
            'name' => 'Creative Commons Attribution 4.0',
            'is_active' => true,
            'is_elmo_active' => true,
            'usage_count' => 0,
        ],
    );

    TitleType::firstOrCreate(
        ['slug' => 'MainTitle'],
        ['name' => 'Main Title', 'is_active' => true, 'is_elmo_active' => true],
    );

    DescriptionType::firstOrCreate(
        ['slug' => 'Abstract'],
        ['name' => 'Abstract', 'is_active' => true],
    );

    ContributorType::firstOrCreate(
        ['slug' => 'Editor'],
        [
            'name' => 'Editor',
            'is_active' => true,
            'applicable_to' => ['person', 'institution'],
        ],
    );

    RelationType::firstOrCreate(
        ['slug' => 'IsPublishedIn'],
        ['name' => 'Is Published In', 'is_active' => true],
    );

    $datacenter = Datacenter::firstOrCreate(['name' => 'Test Datacenter']);

    return [
        'resource_type_id' => $dataset->id,
        'datacenter_id' => $datacenter->id,
    ];
}

function basePayload(int $resourceTypeId, int $datacenterId): array
{
    return [
        'resourceId' => null,
        'doi' => null,
        'year' => 2024,
        'resourceType' => $resourceTypeId,
        'version' => null,
        'language' => 'en',
        'titles' => [
            ['title' => 'Parent dataset title', 'titleType' => 'main-title'],
        ],
        'licenses' => ['cc-by-4'],
        'authors' => [
            [
                'type' => 'person',
                'position' => 0,
                'firstName' => 'Jane',
                'lastName' => 'Doe',
                'affiliations' => [],
            ],
        ],
        'descriptions' => [
            [
                'descriptionType' => 'abstract',
                'description' => 'Abstract',
            ],
        ],
        'datacenters' => [$datacenterId],
    ];
}

test('storing a resource persists relatedItems with titles, creators and contributors', function () {
    $user = User::factory()->create();
    $baseline = seedRelatedItemsBaseline();

    $payload = basePayload($baseline['resource_type_id'], $baseline['datacenter_id']);
    $payload['relatedItems'] = [
        [
            'related_item_type' => 'JournalArticle',
            'relation_type_slug' => 'IsPublishedIn',
            'titles' => [
                ['title' => 'Journal Article', 'title_type' => 'MainTitle'],
                ['title' => 'A descriptive subtitle', 'title_type' => 'Subtitle'],
            ],
            'publication_year' => 2023,
            'volume' => '42',
            'issue' => '7',
            'first_page' => '101',
            'last_page' => '120',
            'publisher' => 'Science Press',
            'identifier' => '10.1234/example',
            'identifier_type' => 'DOI',
            'creators' => [
                [
                    'name_type' => 'Personal',
                    'name' => 'Smith, John',
                    'given_name' => 'John',
                    'family_name' => 'Smith',
                    'affiliations' => [
                        ['name' => 'Example University'],
                    ],
                ],
            ],
            'contributors' => [
                [
                    'contributor_type' => 'Editor',
                    'name_type' => 'Personal',
                    'name' => 'Green, Eva',
                    'given_name' => 'Eva',
                    'family_name' => 'Green',
                ],
            ],
        ],
    ];

    $response = $this->actingAs($user)->postJson(route('editor.resources.store'), $payload);
    $response->assertStatus(201);

    /** @var Resource $resource */
    $resource = Resource::query()->latest('id')->firstOrFail();

    expect($resource->relatedItems)->toHaveCount(1);

    /** @var RelatedItem $item */
    $item = $resource->relatedItems()->with(['titles', 'creators.affiliations', 'contributors', 'relationType'])->firstOrFail();

    expect($item->related_item_type)->toBe('JournalArticle');
    expect($item->relationType->slug)->toBe('IsPublishedIn');
    expect($item->volume)->toBe('42');
    expect($item->identifier)->toBe('10.1234/example');
    expect($item->publication_year)->toBe(2023);

    expect($item->titles)->toHaveCount(2);
    expect($item->titles->firstWhere('title_type', 'MainTitle')?->title)->toBe('Journal Article');
    expect($item->titles->firstWhere('title_type', 'Subtitle')?->title)->toBe('A descriptive subtitle');

    expect($item->creators)->toHaveCount(1);
    expect($item->creators->first()?->family_name)->toBe('Smith');
    expect($item->creators->first()?->affiliations)->toHaveCount(1);
    expect($item->creators->first()?->affiliations->first()?->name)->toBe('Example University');

    expect($item->contributors)->toHaveCount(1);
    expect($item->contributors->first()?->contributor_type)->toBe('Editor');
});

test('updating a resource replaces relatedItems (delete-and-recreate)', function () {
    $user = User::factory()->create();
    $baseline = seedRelatedItemsBaseline();

    $payload = basePayload($baseline['resource_type_id'], $baseline['datacenter_id']);
    $payload['relatedItems'] = [
        [
            'related_item_type' => 'JournalArticle',
            'relation_type_slug' => 'IsPublishedIn',
            'titles' => [['title' => 'Old item', 'title_type' => 'MainTitle']],
        ],
    ];

    $this->actingAs($user)->postJson(route('editor.resources.store'), $payload)->assertStatus(201);

    /** @var Resource $resource */
    $resource = Resource::query()->latest('id')->firstOrFail();
    expect($resource->relatedItems)->toHaveCount(1);

    $updatePayload = $payload;
    $updatePayload['resourceId'] = $resource->id;
    $updatePayload['relatedItems'] = [
        [
            'related_item_type' => 'JournalArticle',
            'relation_type_slug' => 'IsPublishedIn',
            'titles' => [['title' => 'New item', 'title_type' => 'MainTitle']],
        ],
    ];

    $this->actingAs($user)->postJson(route('editor.resources.store'), $updatePayload)->assertStatus(200);

    $resource->refresh();
    expect($resource->relatedItems)->toHaveCount(1);
    expect($resource->relatedItems->first()?->titles->first()?->title)->toBe('New item');
});

test('empty relatedItems array is accepted', function () {
    $user = User::factory()->create();
    $baseline = seedRelatedItemsBaseline();

    $payload = basePayload($baseline['resource_type_id'], $baseline['datacenter_id']);
    $payload['relatedItems'] = [];

    $this->actingAs($user)->postJson(route('editor.resources.store'), $payload)->assertStatus(201);

    /** @var Resource $resource */
    $resource = Resource::query()->latest('id')->firstOrFail();
    expect($resource->relatedItems)->toHaveCount(0);
});

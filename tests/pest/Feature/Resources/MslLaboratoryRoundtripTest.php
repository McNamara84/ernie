<?php

declare(strict_types=1);

use App\Enums\ContributorCategory;
use App\Models\ContributorType;
use App\Models\Datacenter;
use App\Models\DescriptionType;
use App\Models\IdentifierType;
use App\Models\RelationType;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    $this->roundtripUser = User::factory()->create();
    $this->roundtripResourceType = ResourceType::factory()->create();
    $this->roundtripRight = Right::factory()->create();
    $this->roundtripDatacenter = Datacenter::factory()->create();

    TitleType::firstOrCreate(['slug' => 'MainTitle'], ['name' => 'MainTitle']);
    DescriptionType::firstOrCreate(['slug' => 'Abstract'], ['name' => 'Abstract']);
    IdentifierType::firstOrCreate(
        ['slug' => 'DOI'],
        ['name' => 'DOI', 'is_active' => true],
    );
    RelationType::firstOrCreate(
        ['slug' => 'Cites'],
        ['name' => 'Cites', 'is_active' => true],
    );
    ContributorType::firstOrCreate(
        ['slug' => 'ContactPerson'],
        [
            'name' => 'Contact Person',
            'category' => ContributorCategory::PERSON,
            'is_active' => true,
            'is_elmo_active' => true,
        ],
    );
});

it('keeps MSL laboratories in their editor group after a real save and reload', function (): void {
    $user = $this->roundtripUser;
    $resourceType = $this->roundtripResourceType;
    $right = $this->roundtripRight;
    $datacenter = $this->roundtripDatacenter;
    ContributorType::firstOrCreate(
        ['slug' => 'HostingInstitution'],
        [
            'name' => 'Hosting Institution',
            'category' => ContributorCategory::INSTITUTION,
            'is_active' => true,
            'is_elmo_active' => true,
        ],
    );

    $payload = [
        'year' => 2026,
        'resourceType' => $resourceType->id,
        'titles' => [
            ['title' => 'MSL laboratory roundtrip', 'titleType' => 'main-title'],
        ],
        'licenses' => [$right->identifier],
        'authors' => [
            [
                'type' => 'person',
                'position' => 0,
                'firstName' => 'Jane',
                'lastName' => 'Doe',
                'isContact' => false,
                'affiliations' => [],
            ],
        ],
        'descriptions' => [
            ['descriptionType' => 'abstract', 'description' => 'Roundtrip test abstract.'],
        ],
        'datacenters' => [$datacenter->id],
        'mslLaboratories' => [
            [
                'identifier' => 'lab-first',
                'name' => 'First Laboratory',
                'affiliation_name' => 'First University',
                'affiliation_ror' => 'https://ror.org/01aaaaaaaa',
                'position' => 0,
            ],
            [
                'identifier' => 'lab-second',
                'name' => 'Second Laboratory',
                'affiliation_name' => 'Second University',
                'affiliation_ror' => 'https://ror.org/02bbbbbbbb',
                'position' => 1,
            ],
        ],
    ];

    $saveResponse = $this->actingAs($user)
        ->postJson(route('editor.resources.store'), $payload)
        ->assertCreated();

    $resourceId = $saveResponse->json('resource.id');

    expect($resourceId)->toBeInt();

    $this->actingAs($user)
        ->get(route('editor', ['resourceId' => $resourceId]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('editor')
            ->has('contributors', 0)
            ->has('mslLaboratories', 2)
            ->where('mslLaboratories.0.identifier', 'lab-first')
            ->where('mslLaboratories.0.name', 'First Laboratory')
            ->where('mslLaboratories.0.affiliation_name', 'First University')
            ->where('mslLaboratories.0.affiliation_ror', 'https://ror.org/01aaaaaaaa')
            ->where('mslLaboratories.0.position', 0)
            ->where('mslLaboratories.1.identifier', 'lab-second')
            ->where('mslLaboratories.1.affiliation_name', 'Second University')
            ->where('mslLaboratories.1.affiliation_ror', 'https://ror.org/02bbbbbbbb')
            ->where('mslLaboratories.1.position', 1));
});

it('preserves a historical laboratory through draft save reload and update without a vocabulary match', function (): void {
    Storage::assertMissing('msl-laboratories.json');

    $payload = [
        'titles' => [
            ['title' => 'Historical laboratory draft', 'titleType' => 'main-title'],
        ],
        'mslLaboratories' => [
            [
                'identifier' => 'historical-lab-no-longer-listed',
                'name' => 'Historical Laboratory',
                'affiliation_name' => 'Former University',
                'affiliation_ror' => 'https://ror.org/03ccccccc',
                'position' => 0,
            ],
        ],
    ];

    $createResponse = $this->actingAs($this->roundtripUser)
        ->postJson(route('editor.resources.store-draft'), $payload)
        ->assertCreated();

    $resourceId = $createResponse->json('resource.id');

    expect($resourceId)->toBeInt();

    $assertHistoricalLaboratory = fn (Assert $page): Assert => $page
        ->component('editor')
        ->has('contributors', 0)
        ->has('mslLaboratories', 1)
        ->where('mslLaboratories.0.identifier', 'historical-lab-no-longer-listed')
        ->where('mslLaboratories.0.name', 'Historical Laboratory')
        ->where('mslLaboratories.0.affiliation_name', 'Former University')
        ->where('mslLaboratories.0.affiliation_ror', 'https://ror.org/03ccccccc')
        ->where('mslLaboratories.0.position', 0);

    $this->actingAs($this->roundtripUser)
        ->get(route('editor', ['resourceId' => $resourceId]))
        ->assertOk()
        ->assertInertia($assertHistoricalLaboratory);

    $payload['resourceId'] = $resourceId;

    $this->actingAs($this->roundtripUser)
        ->postJson(route('editor.resources.store-draft'), $payload)
        ->assertOk();

    $this->actingAs($this->roundtripUser)
        ->get(route('editor', ['resourceId' => $resourceId]))
        ->assertOk()
        ->assertInertia($assertHistoricalLaboratory);
});

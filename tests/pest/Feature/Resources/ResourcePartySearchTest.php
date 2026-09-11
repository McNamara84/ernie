<?php

declare(strict_types=1);

use App\Jobs\RefreshResourceListingProjectionsForDependencyJob;
use App\Models\ContributorType;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\ResourceListingProjection;
use App\Models\Title;
use App\Models\User;
use App\Services\ListingCountService;
use App\Services\ResourceCacheService;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    actingAs(User::factory()->create(['email_verified_at' => now()]));
});

/** @return array{resource:Resource, person:Person} */
function createResourcePartySearchFixture(): array
{
    $resource = Resource::factory()->create([
        'doi' => '10.5880/party-search-target',
        'publication_year' => 2024,
    ]);
    Title::factory()->create(['resource_id' => $resource->id, 'value' => 'Unrelated ocean record']);
    $person = Person::factory()->create(['given_name' => 'Peter', 'family_name' => 'Hans']);
    ResourceCreator::factory()->forPerson($person)->create([
        'resource_id' => $resource->id,
        'position' => 0,
        'is_contact' => true,
        'email' => 'Hans@Peter.Egal',
    ]);

    $researcher = ContributorType::query()->create(['name' => 'Researcher', 'slug' => 'Researcher']);
    $contactPerson = ContributorType::query()->create(['name' => 'Contact Person', 'slug' => 'ContactPerson']);
    $contributor = ResourceContributor::factory()->forPerson($person)->create([
        'resource_id' => $resource->id,
        'position' => 0,
        'email' => 'Hans@Peter.Egal',
    ]);
    $contributor->contributorTypes()->sync([$researcher->id, $contactPerson->id]);

    Resource::factory()->create(['doi' => '10.5880/unrelated-resource']);
    app(ResourceListingProjectionRefreshService::class)->flushPending();

    return compact('resource', 'person');
}

it('finds creator and contributor names in normalized partial forms', function (string $search): void {
    ['resource' => $resource] = createResourcePartySearchFixture();

    get(route('resources', ['search' => $search]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('resources', 1)
            ->where('resources.0.id', $resource->id)
            ->where('resources.0.search_matches', [[
                'display_value' => 'Hans, Peter',
                'matched_field' => 'name',
                'roles' => ['contact_person', 'author', 'contributor'],
            ]]));
})->with(['Hans', 'HansPeter', 'peter', 'PETER HANS', 'Hans, Peter']);

it('finds case-insensitive email substrings and shows the matching email', function (string $search): void {
    ['resource' => $resource] = createResourcePartySearchFixture();

    get(route('resources', ['search' => $search]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('resources', 1)
            ->where('resources.0.id', $resource->id)
            ->where('resources.0.search_matches.0.display_value', 'Hans@Peter.Egal')
            ->where('resources.0.search_matches.0.matched_field', 'email')
            ->where('resources.0.search_matches.0.roles', ['contact_person', 'author', 'contributor']));
})->with(['hans@peter', '@PETER.EGAL']);

it('returns separate stable matches for different identities and supports institutions', function (): void {
    ['resource' => $resource] = createResourcePartySearchFixture();
    $institution = Institution::factory()->create(['name' => 'Hans Peter Observatory']);
    $type = ContributorType::query()->create(['name' => 'Hosting Institution', 'slug' => 'HostingInstitution']);
    $contributor = ResourceContributor::factory()->forInstitution($institution)->create([
        'resource_id' => $resource->id,
        'position' => 2,
    ]);
    $contributor->contributorTypes()->sync([$type->id]);
    app(ResourceListingProjectionRefreshService::class)->flushPending();

    get(route('resources', ['search' => 'HansPeter']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('resources.0.search_matches', 2)
            ->where('resources.0.search_matches.0.display_value', 'Hans, Peter')
            ->where('resources.0.search_matches.0.roles', ['contact_person', 'author', 'contributor'])
            ->where('resources.0.search_matches.1.display_value', 'Hans Peter Observatory')
            ->where('resources.0.search_matches.1.roles', ['contributor']));
});

it('derives each compact role-label combination from the matching identity', function (
    bool $hasCreator,
    bool $creatorIsContact,
    array $contributorSlugs,
    array $expectedRoles,
): void {
    $resource = Resource::factory()->create();
    $person = Person::factory()->create(['given_name' => 'Person', 'family_name' => 'Rolematch']);

    if ($hasCreator) {
        ResourceCreator::factory()->forPerson($person)->create([
            'resource_id' => $resource->id,
            'is_contact' => $creatorIsContact,
        ]);
    }

    if ($contributorSlugs !== []) {
        $types = collect($contributorSlugs)->map(fn (string $slug): ContributorType => ContributorType::query()->create([
            'name' => $slug,
            'slug' => $slug,
        ]));
        $contributor = ResourceContributor::factory()->forPerson($person)->create(['resource_id' => $resource->id]);
        $contributor->contributorTypes()->sync($types->pluck('id')->all());
    }

    app(ResourceListingProjectionRefreshService::class)->flushPending();

    get(route('resources', ['search' => 'Rolematch']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('resources.0.search_matches.0.display_value', 'Rolematch, Person')
            ->where('resources.0.search_matches.0.roles', $expectedRoles));
})->with([
    'Author' => [true, false, [], ['author']],
    'Contributor' => [false, false, ['Researcher'], ['contributor']],
    'Author & Contributor' => [true, false, ['Researcher'], ['author', 'contributor']],
    'CP & Contributor' => [false, false, ['ContactPerson', 'Researcher'], ['contact_person', 'contributor']],
    'CP & Author' => [true, true, [], ['contact_person', 'author']],
]);

it('keeps title and DOI matches while returning no party annotation', function (string $search): void {
    ['resource' => $resource] = createResourcePartySearchFixture();

    get(route('resources', ['search' => $search]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('resources', 1)
            ->where('resources.0.id', $resource->id)
            ->where('resources.0.search_matches', []));
})->with(['ocean record', 'party-search-target']);

it('does not add typo tolerance and treats SQL wildcard characters literally', function (): void {
    createResourcePartySearchFixture();
    $literal = Resource::factory()->create(['doi' => '10.5880/literal-percent']);
    Title::factory()->create(['resource_id' => $literal->id, 'value' => 'Coverage 100%_done']);
    app(ResourceListingProjectionRefreshService::class)->flushPending();

    get(route('resources', ['search' => 'Hnas']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('resources', 0));

    get(route('resources', ['search' => '%_']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('resources', 1)
            ->where('resources.0.id', $literal->id));
});

it('uses the same party criteria and payload for count and load-more', function (): void {
    ['resource' => $resource] = createResourcePartySearchFixture();

    getJson(route('resources.count', ['search' => 'HansPeter']))
        ->assertOk()
        ->assertJsonPath('total', 1);

    getJson(route('resources.load-more', ['search' => 'HansPeter']))
        ->assertOk()
        ->assertJsonCount(1, 'resources')
        ->assertJsonPath('resources.0.id', $resource->id)
        ->assertJsonPath('resources.0.search_matches.0.display_value', 'Hans, Peter')
        ->assertJsonPath('resources.0.search_matches.0.roles', ['contact_person', 'author', 'contributor']);
});

it('projects creator and contributor parties without mixing them into title search text', function (): void {
    ['resource' => $resource] = createResourcePartySearchFixture();

    $projection = ResourceListingProjection::query()->findOrFail($resource->id);

    expect($projection->party_search_text)->toContain('peter hans')
        ->toContain('hanspeter')
        ->toContain('hans@peter.egal')
        ->and($projection->search_text)->not->toContain('hanspeter');
});

it('refreshes the party projection when a contributor is changed or deleted', function (): void {
    $resource = Resource::factory()->create();
    $person = Person::factory()->create(['given_name' => 'Initial', 'family_name' => 'Contributor']);
    $contributor = ResourceContributor::factory()->forPerson($person)->create([
        'resource_id' => $resource->id,
        'email' => 'initial@example.test',
    ]);
    app(ResourceListingProjectionRefreshService::class)->flushPending();

    expect(ResourceListingProjection::query()->findOrFail($resource->id)->party_search_text)
        ->toContain('initial@example.test');

    $contributor->update(['email' => 'updated@example.test']);
    app(ResourceListingProjectionRefreshService::class)->flushPending();

    expect(ResourceListingProjection::query()->findOrFail($resource->id)->party_search_text)
        ->toContain('updated@example.test')
        ->not->toContain('initial@example.test');

    $contributor->delete();
    app(ResourceListingProjectionRefreshService::class)->flushPending();

    expect(ResourceListingProjection::query()->findOrFail($resource->id)->party_search_text)->toBe('');
});

it('refreshes contributor-only projections when a party name changes', function (): void {
    $resource = Resource::factory()->create();
    $person = Person::factory()->create(['given_name' => 'Old', 'family_name' => 'Contributor']);
    ResourceContributor::factory()->forPerson($person)->create(['resource_id' => $resource->id]);
    app(ResourceListingProjectionRefreshService::class)->flushPending();

    $person->updateQuietly(['given_name' => 'Renamed']);
    (new RefreshResourceListingProjectionsForDependencyJob(
        Person::class,
        $person->id,
        RefreshResourceListingProjectionsForDependencyJob::EVENT_UPDATED,
    ))->handle(
        app(ResourceListingProjectionRefreshService::class),
        app(ResourceCacheService::class),
        app(ListingCountService::class),
    );
    app(ResourceListingProjectionRefreshService::class)->flushPending();

    expect(ResourceListingProjection::query()->findOrFail($resource->id)->party_search_text)
        ->toContain('renamed contributor')
        ->not->toContain('old contributor');
});

it('refreshes contributor-only projections when an institution name changes', function (): void {
    $resource = Resource::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Original Observatory']);
    ResourceContributor::factory()->forInstitution($institution)->create(['resource_id' => $resource->id]);
    app(ResourceListingProjectionRefreshService::class)->flushPending();

    $institution->updateQuietly(['name' => 'Renamed Observatory']);
    (new RefreshResourceListingProjectionsForDependencyJob(
        Institution::class,
        $institution->id,
        RefreshResourceListingProjectionsForDependencyJob::EVENT_UPDATED,
    ))->handle(
        app(ResourceListingProjectionRefreshService::class),
        app(ResourceCacheService::class),
        app(ListingCountService::class),
    );
    app(ResourceListingProjectionRefreshService::class)->flushPending();

    expect(ResourceListingProjection::query()->findOrFail($resource->id)->party_search_text)
        ->toContain('renamed observatory')
        ->not->toContain('original observatory');
});

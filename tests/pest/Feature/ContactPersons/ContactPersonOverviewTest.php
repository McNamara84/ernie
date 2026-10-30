<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\RefreshResourceListingProjectionsForDependencyJob;
use App\Models\ContributorType;
use App\Models\IgsnMetadata;
use App\Models\Institution;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\User;
use App\Services\ContactPersons\ContactPersonOverviewService;
use App\Services\ContactPersons\ResourceContactProjectorService;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

function contactOverviewCreator(?Person $person = null, ?Resource $resource = null, array $attributes = []): ResourceCreator
{
    $person ??= Person::factory()->create(['given_name' => 'Ada', 'family_name' => 'Contact', 'name_identifier' => null]);
    $resource ??= Resource::factory()->create();

    return ResourceCreator::factory()->forPerson($person)->create([
        'resource_id' => $resource->id, 'is_contact' => true, 'email' => 'ada@example.test', ...$attributes,
    ]);
}

function contactOverviewContributor(Person $person, Resource $resource): ResourceContributor
{
    $type = ContributorType::query()->firstOrCreate(['slug' => 'ContactPerson'], ['name' => 'Contact Person']);
    $contributor = ResourceContributor::factory()->forPerson($person)->create(['resource_id' => $resource->id, 'email' => 'ada@example.test']);
    $contributor->contributorTypes()->sync([$type->id]);

    return $contributor;
}

function contactOverviewData(string $search = '', int $perPage = 25, int $page = 1): array
{
    return app(ContactPersonOverviewService::class)->overview($search, $perPage, $page);
}

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => UserRole::CURATOR]));
});

it('authorizes the overview by role', function (UserRole $role, int $status): void {
    $this->actingAs(User::factory()->create(['role' => $role]))->get('/contact-persons')->assertStatus($status);
})->with([[UserRole::ADMIN, 200], [UserRole::GROUP_LEADER, 200], [UserRole::CURATOR, 200], [UserRole::BEGINNER, 403]]);

it('requires authentication', function (): void {
    auth()->logout();
    $this->get('/contact-persons')->assertRedirect('/login');
});

it('groups normalized ORCIDs and preserves contact variants across both target lists', function (): void {
    $first = Person::factory()->create(['given_name' => 'Ada', 'family_name' => 'Contact', 'name_identifier' => '0000-0002-1825-0097', 'name_identifier_scheme' => 'ORCID']);
    $second = Person::factory()->create(['given_name' => 'Ada', 'family_name' => 'Contact', 'name_identifier' => 'https://orcid.org/0000-0002-1825-0097', 'name_identifier_scheme' => null]);
    $creator = contactOverviewCreator($first);
    contactOverviewContributor($first, $creator->resource);
    $type = ResourceType::query()->firstOrCreate(['slug' => 'physical-object'], ['name' => 'Physical Object']);
    $sample = Resource::factory()->create(['resource_type_id' => $type->id]);
    IgsnMetadata::query()->create(['resource_id' => $sample->id, 'upload_status' => 'pending']);
    contactOverviewCreator($second, $sample);
    contactOverviewCreator($second, null, ['name_snapshot' => 'Contact, A.', 'email' => 'other@example.test']);

    $data = contactOverviewData('ada@example.test');
    expect($data['pagination']['total'])->toBe(1)
        ->and($data['groups'][0]['variants'])->toHaveCount(2);
    $variant = collect($data['groups'][0]['variants'])->firstWhere('name', 'Contact, Ada');
    expect($variant['resource_count'])->toBe(1)->and($variant['igsn_count'])->toBe(1);
    $this->get('/contact-persons')->assertInertia(fn (Assert $page) => $page
        ->component('contact-persons/index')->has('groups', 1)->has('groups.0.variants', 2)
        ->where('auth.user.can_access_contact_persons', true));
});

it('keeps person IDs separate without ORCID and includes missing emails and draft or tombstone resources', function (): void {
    $draft = contactOverviewCreator(attributes: ['email' => null]);
    $dead = contactOverviewCreator(attributes: ['email' => '']);
    LandingPage::factory()->published()->create(['resource_id' => $dead->resource_id, 'is_tombstone' => true]);
    $data = contactOverviewData();
    expect($data['groups'])->toHaveCount(2)
        ->and($data['groups'][0]['key'])->not->toBe($data['groups'][1]['key'])
        ->and($data['groups'][0]['variants'][0]['email'])->toBeNull();
    $draft->resource->delete();
    expect(contactOverviewData()['pagination']['total'])->toBe(1);
});

it('ignores institutions, non-contact roles, and unused people', function (): void {
    contactOverviewCreator(attributes: ['is_contact' => false]);
    Person::factory()->create();
    $resource = Resource::factory()->create();
    $contributor = ResourceContributor::factory()->forInstitution(Institution::factory()->create())->create(['resource_id' => $resource->id, 'email' => 'institution@example.test']);
    $type = ContributorType::query()->firstOrCreate(['slug' => 'ContactPerson'], ['name' => 'Contact Person']);
    $contributor->contributorTypes()->attach($type);
    expect(contactOverviewData()['groups'])->toBe([]);
});

it('updates contact projection after address edits, role attachment and detachment, and deletes', function (): void {
    $creator = contactOverviewCreator();
    $contributor = contactOverviewContributor($creator->creatorable, $creator->resource);
    expect(contactOverviewData()['groups'][0]['variants'])->toHaveCount(1);
    $creator->update(['email' => 'new@example.test']);
    expect(contactOverviewData()['groups'][0]['variants'])->toHaveCount(2);
    $contributor->contributorTypes()->sync([]);
    expect(contactOverviewData()['groups'][0]['variants'])->toHaveCount(1);
    $creator->update(['is_contact' => false]);
    expect(contactOverviewData()['groups'])->toBe([]);
    $type = ContributorType::query()->where('slug', 'ContactPerson')->firstOrFail();
    $contributor->contributorTypes()->attach($type);
    expect(contactOverviewData()['pagination']['total'])->toBe(1);
    $contributor->delete();
    expect(contactOverviewData()['groups'])->toBe([]);
});

it('refreshes ORCID changes through the existing dependency job', function (): void {
    $creator = contactOverviewCreator();
    contactOverviewData();
    Queue::fake();
    $person = $creator->creatorable;
    $person->update(['name_identifier' => '0000-0002-1825-0097', 'name_identifier_scheme' => 'ORCID']);
    Queue::assertPushed(RefreshResourceListingProjectionsForDependencyJob::class);
    $job = Queue::pushed(RefreshResourceListingProjectionsForDependencyJob::class)->first();
    app()->call([$job, 'handle']);
    expect(contactOverviewData()['groups'][0]['key'])->toBe('orcid:0000-0002-1825-0097');
});

it('keeps creator snapshots while refreshing contributor names, schemes, and deleted people', function (): void {
    $creator = contactOverviewCreator(attributes: ['name_snapshot' => 'Contact, A.']);
    $person = $creator->creatorable;
    contactOverviewContributor($person, $creator->resource);
    contactOverviewData();
    Queue::fake();
    $applyChange = function (Closure $change): void {
        Queue::fake();
        $change();
        Queue::assertPushed(RefreshResourceListingProjectionsForDependencyJob::class);
        foreach (Queue::pushed(RefreshResourceListingProjectionsForDependencyJob::class) as $job) {
            app()->call([$job, 'handle']);
        }
    };
    $applyChange(fn () => $person->update(['family_name' => 'Renamed', 'name_identifier' => '0000-0002-1825-0097', 'name_identifier_scheme' => 'ORCID']));
    $group = contactOverviewData('https://orcid.org/0000-0002-1825-0097')['groups'][0];
    expect(array_column($group['variants'], 'name'))->toBe(['Contact, A.', 'Renamed, Ada']);
    $applyChange(fn () => $person->update(['name_identifier_scheme' => 'ISNI']));
    expect(contactOverviewData()['groups'][0]['key'])->toBe('person:'.$person->id);
    $applyChange(fn () => $person->delete());
    expect(contactOverviewData()['groups'])->toBe([]);
});

it('rebuilds idempotently and rolls back a projection replacement with its source change', function (): void {
    $creator = contactOverviewCreator();
    contactOverviewData();
    $before = DB::table('resource_contact_points')->value('variant_key');
    DB::beginTransaction();
    $creator->update(['email' => 'temporary@example.test']);
    app(ResourceContactProjectorService::class)->refreshMany([$creator->resource_id]);
    expect(DB::table('resource_contact_points')->value('variant_key'))->not->toBe($before);
    DB::rollBack();
    expect(DB::table('resource_contact_points')->value('variant_key'))->toBe($before);
    $this->artisan('contact-persons:rebuild')->assertSuccessful();
    $this->artisan('contact-persons:rebuild')->assertSuccessful();
    expect(DB::table('resource_contact_points')->count())->toBe(1)
        ->and(DB::table('resource_contact_points')->value('variant_key'))->toBe($before);
});

it('matches literal wildcard characters and shows entire matching groups', function (): void {
    $creator = contactOverviewCreator(attributes: ['name_snapshot' => 'Contact 100%_']);
    contactOverviewCreator($creator->creatorable, attributes: ['name_snapshot' => 'Another spelling']);
    expect(contactOverviewData('%_')['groups'][0]['variants'])->toHaveCount(2)
        ->and(contactOverviewData('missing')['groups'])->toBe([]);
});

it('paginates identities without splitting their variants', function (): void {
    for ($i = 0; $i < 26; $i++) {
        contactOverviewCreator();
    }
    $first = contactOverviewData();
    $last = contactOverviewData(page: 2);
    expect($first['groups'])->toHaveCount(25)->and($last['groups'])->toHaveCount(1)
        ->and($first['pagination']['total'])->toBe(26)
        ->and(array_intersect(array_column($first['groups'], 'key'), array_column($last['groups'], 'key')))->toBe([]);
});

it('validates overview query parameters', function (string $query): void {
    $this->getJson('/contact-persons?'.$query)->assertUnprocessable();
})->with(['page=0', 'per_page=1000', 'search[]=invalid']);

it('filters resources and IGSNs exactly and keeps list counts and load-more consistent', function (): void {
    $creator = contactOverviewCreator();
    $second = contactOverviewCreator($creator->creatorable);
    contactOverviewCreator(); // Same spelling and email, but a different identity.
    contactOverviewCreator($creator->creatorable, attributes: ['name_snapshot' => 'Different name']);
    contactOverviewCreator($creator->creatorable, attributes: ['is_contact' => false]);
    $type = ResourceType::query()->firstOrCreate(['slug' => 'physical-object'], ['name' => 'Physical Object']);
    $sample = Resource::factory()->create(['resource_type_id' => $type->id]);
    IgsnMetadata::query()->create(['resource_id' => $sample->id, 'upload_status' => 'pending']);
    contactOverviewCreator($creator->creatorable, $sample);
    app(ResourceListingProjectionRefreshService::class)->flushPending();
    $key = DB::table('resource_contact_points')->where('resource_id', $creator->resource_id)->value('variant_key');

    $this->get('/resources?contact='.$key)->assertInertia(fn (Assert $page) => $page
        ->has('resources', 2)->where('filters.contact', $key)->where('contactSelection.name', 'Contact, Ada'));
    $this->getJson('/resources/count?contact='.$key)->assertJsonPath('total', 2);
    $this->getJson('/resources/load-more?contact='.$key)->assertJsonCount(2, 'resources');
    $query = '?contact='.$key.'&per_page=1&sort_key=id&sort_direction=asc';
    $firstPage = $this->getJson('/resources/load-more'.$query)->assertOk()->assertJsonPath('resources.0.id', $creator->resource_id);
    $cursor = urlencode((string) $firstPage->json('pagination.next_cursor'));
    $this->getJson('/resources/load-more'.$query.'&cursor='.$cursor)
        ->assertOk()->assertJsonCount(1, 'resources')->assertJsonPath('resources.0.id', $second->resource_id);
    $this->getJson('/resources/load-more?per_page=1&sort_key=id&sort_direction=asc&cursor='.$cursor)
        ->assertUnprocessable()->assertJsonValidationErrors(['cursor']);
    $this->get('/igsns?contact='.$key)->assertInertia(fn (Assert $page) => $page
        ->has('igsns', 1)->where('igsns.0.id', $sample->id)->where('filters.contact', $key));
    $this->getJson('/igsns/count?contact='.$key)->assertJsonPath('filtered_total', 1);
    $creator->update(['is_contact' => false]);
    $this->getJson('/resources/count?contact='.$key)->assertJsonPath('total', 1);
});

it('never widens an unknown contact filter and rejects invalid keys', function (string $path, string $collection): void {
    contactOverviewCreator();
    $key = str_repeat('a', 64);
    $this->get($path.'?contact='.$key)->assertInertia(fn (Assert $page) => $page
        ->has($collection, 0)->where('contactSelection.key', $key)->where('contactSelection.name', null));
    $this->getJson($path.'?contact=bad')->assertUnprocessable();
})->with([['/resources', 'resources'], ['/igsns', 'igsns']]);

it('denies contact filtering to beginners on every endpoint', function (string $path): void {
    $this->actingAs(User::factory()->create(['role' => UserRole::BEGINNER]))
        ->getJson($path.'?contact='.str_repeat('a', 64))->assertForbidden();
})->with(['/resources', '/resources/count', '/resources/load-more', '/igsns', '/igsns/count']);

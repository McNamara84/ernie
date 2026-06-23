<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\Right;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createNonDraftResource(): Resource
{
    $resource = Resource::factory()->create([
        'doi' => null,
    ]);

    $titleType = TitleType::firstOrCreate([
        'slug' => 'MainTitle',
    ], [
        'name' => 'Main Title',
    ]);

    $resource->titles()->create([
        'value' => 'Delete me later',
        'title_type_id' => $titleType->id,
    ]);

    $creator = Person::create([
        'family_name' => 'Example',
        'given_name' => 'Curator',
    ]);

    ResourceCreator::create([
        'resource_id' => $resource->id,
        'creatorable_type' => Person::class,
        'creatorable_id' => $creator->id,
        'position' => 0,
    ]);

    $right = Right::firstOrCreate([
        'identifier' => 'cc-by-4.0',
    ], [
        'name' => 'CC-BY 4.0',
    ]);
    $resource->rights()->attach($right->id);

    $abstractType = DescriptionType::firstOrCreate([
        'slug' => 'Abstract',
    ], [
        'name' => 'Abstract',
    ]);

    Description::create([
        'resource_id' => $resource->id,
        'value' => 'Complete enough to leave draft status.',
        'description_type_id' => $abstractType->id,
    ]);

    return $resource->fresh([
        'titles.titleType',
        'creators',
        'rights',
        'descriptions.descriptionType',
        'landingPage',
    ]);
}

it('allows admins to delete draft resources', function (): void {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $resource = Resource::factory()->create([
        'doi' => null,
    ]);

    $this->actingAs($admin)
        ->delete(route('resources.destroy', $resource))
        ->assertRedirect(route('resources'))
        ->assertSessionHas('success', 'Draft deleted successfully.');

    expect(Resource::find($resource->id))->toBeNull();
});

it('forbids admins from deleting draft resources with persistent identifiers', function (): void {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $resource = Resource::factory()->create([
        'doi' => '10.5880/test.2026.002',
    ]);

    expect($resource->publicStatus())->toBe('draft');

    $this->actingAs($admin)
        ->delete(route('resources.destroy', $resource))
        ->assertStatus(403);

    expect(Resource::find($resource->id))->not->toBeNull();
});

it('allows group leaders to delete draft resources', function (): void {
    $leader = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
    $resource = Resource::factory()->create([
        'doi' => null,
    ]);

    $this->actingAs($leader)
        ->delete(route('resources.destroy', $resource))
        ->assertRedirect(route('resources'));

    expect(Resource::find($resource->id))->toBeNull();
});

it('allows curators to delete draft resources', function (): void {
    $curator = User::factory()->create(['role' => UserRole::CURATOR]);
    $resource = Resource::factory()->create([
        'doi' => null,
    ]);

    $this->actingAs($curator)
        ->delete(route('resources.destroy', $resource))
        ->assertRedirect(route('resources'));

    expect(Resource::find($resource->id))->toBeNull();
});

it('forbids admins from deleting non-draft resources', function (): void {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $resource = createNonDraftResource();

    expect($resource->publicStatus())->not->toBe('draft');

    $this->actingAs($admin)
        ->delete(route('resources.destroy', $resource))
        ->assertStatus(403);

    expect(Resource::find($resource->id))->not->toBeNull();
});

it('forbids group leaders from deleting non-draft resources', function (): void {
    $leader = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
    $resource = createNonDraftResource();

    expect($resource->publicStatus())->not->toBe('draft');

    $this->actingAs($leader)
        ->delete(route('resources.destroy', $resource))
        ->assertStatus(403);

    expect(Resource::find($resource->id))->not->toBeNull();
});

it('forbids curators from deleting non-draft resources', function (): void {
    $curator = User::factory()->create(['role' => UserRole::CURATOR]);
    $resource = createNonDraftResource();

    expect($resource->publicStatus())->not->toBe('draft');

    $this->actingAs($curator)
        ->delete(route('resources.destroy', $resource))
        ->assertStatus(403);

    expect(Resource::find($resource->id))->not->toBeNull();
});

it('rejects guests from deleting resources', function (): void {
    $resource = Resource::factory()->create();

    $this->delete(route('resources.destroy', $resource))
        ->assertRedirect('/login');

    expect(Resource::find($resource->id))->not->toBeNull();
});
it('forbids admins from deleting draft resources with landing pages', function (): void {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $resource = Resource::factory()->create([
        'doi' => null,
    ]);
    LandingPage::factory()->withoutDoi()->draft()->create([
        'resource_id' => $resource->id,
    ]);

    expect($resource->fresh()->publicStatus())->toBe('draft');

    $this->actingAs($admin)
        ->delete(route('resources.destroy', $resource))
        ->assertStatus(403);

    expect(Resource::find($resource->id))->not->toBeNull();
});

it('allows admins to batch delete draft resources without identifiers or landing pages', function (): void {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $first = Resource::factory()->create(['doi' => null]);
    $second = Resource::factory()->create(['doi' => null]);

    $this->actingAs($admin)
        ->delete(route('resources.batch-destroy'), [
            'ids' => [$first->id, $second->id],
        ])
        ->assertRedirect(route('resources'))
        ->assertSessionHas('success', '2 drafts deleted successfully.');

    expect(Resource::find($first->id))->toBeNull()
        ->and(Resource::find($second->id))->toBeNull();
});

it('allows admins to batch delete a single draft resource from duplicate selections', function (): void {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $resource = Resource::factory()->create(['doi' => null]);

    $this->actingAs($admin)
        ->delete(route('resources.batch-destroy'), [
            'ids' => [$resource->id, $resource->id],
        ])
        ->assertRedirect(route('resources'))
        ->assertSessionHas('success', 'Draft deleted successfully.');

    expect(Resource::find($resource->id))->toBeNull();
});

it('rejects batch deletion when any selected draft has a landing page', function (): void {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $safeDraft = Resource::factory()->create(['doi' => null]);
    $draftWithLandingPage = Resource::factory()->create(['doi' => null]);
    LandingPage::factory()->withoutDoi()->draft()->create([
        'resource_id' => $draftWithLandingPage->id,
    ]);

    $this->actingAs($admin)
        ->from(route('resources'))
        ->delete(route('resources.batch-destroy'), [
            'ids' => [$safeDraft->id, $draftWithLandingPage->id],
        ])
        ->assertRedirect(route('resources'))
        ->assertSessionHasErrors('ids');

    expect(Resource::find($safeDraft->id))->not->toBeNull()
        ->and(Resource::find($draftWithLandingPage->id))->not->toBeNull();
});

it('rejects batch deletion when any selected draft has a persistent identifier', function (): void {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $safeDraft = Resource::factory()->create(['doi' => null]);
    $registeredDraft = Resource::factory()->create(['doi' => '10.5880/test.2026.batch']);

    $this->actingAs($admin)
        ->from(route('resources'))
        ->delete(route('resources.batch-destroy'), [
            'ids' => [$safeDraft->id, $registeredDraft->id],
        ])
        ->assertRedirect(route('resources'))
        ->assertSessionHasErrors('ids');

    expect(Resource::find($safeDraft->id))->not->toBeNull()
        ->and(Resource::find($registeredDraft->id))->not->toBeNull();
});

it('rejects beginners from batch deleting draft resources', function (): void {
    $beginner = User::factory()->create(['role' => UserRole::BEGINNER]);
    $resource = Resource::factory()->create(['doi' => null]);

    $this->actingAs($beginner)
        ->from(route('resources'))
        ->delete(route('resources.batch-destroy'), [
            'ids' => [$resource->id],
        ])
        ->assertRedirect(route('resources'))
        ->assertSessionHasErrors('ids');

    expect(Resource::find($resource->id))->not->toBeNull();
});

it('rejects invalid batch delete payloads', function (): void {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);

    $this->actingAs($admin)
        ->from(route('resources'))
        ->delete(route('resources.batch-destroy'), [
            'ids' => [],
        ])
        ->assertRedirect(route('resources'))
        ->assertSessionHasErrors('ids');
});

it('rejects guests from batch deleting resources', function (): void {
    $resource = Resource::factory()->create(['doi' => null]);

    $this->delete(route('resources.batch-destroy'), [
        'ids' => [$resource->id],
    ])->assertRedirect('/login');

    expect(Resource::find($resource->id))->not->toBeNull();
});

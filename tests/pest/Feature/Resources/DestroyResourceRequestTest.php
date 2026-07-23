<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Requests\Resource\DestroyResourcesRequest;
use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\LandingPage;
use App\Models\OaiPmhDeletedRecord;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\Right;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function createCompleteResourceForDeletion(array $attributes = []): Resource
{
    $resource = Resource::factory()->create(array_merge([
        'doi' => null,
        'publication_year' => 2026,
    ], $attributes));

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

function createReviewResourceForDeletion(string $doi = '10.5880/test.review.001'): Resource
{
    $resource = createCompleteResourceForDeletion(['doi' => $doi]);

    LandingPage::factory()->withDoi($doi)->draft()->create([
        'resource_id' => $resource->id,
    ]);

    return $resource->fresh([
        'titles.titleType',
        'creators',
        'rights',
        'descriptions.descriptionType',
        'landingPage',
    ]);
}

function createPublishedResourceForDeletion(string $doi = '10.5880/test.published.001'): Resource
{
    $resource = createCompleteResourceForDeletion(['doi' => $doi]);

    LandingPage::factory()->withDoi($doi)->published()->create([
        'resource_id' => $resource->id,
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
    $resource = Resource::factory()->create(['doi' => null]);

    $this->actingAs($admin)
        ->delete(route('resources.destroy', $resource))
        ->assertRedirect(route('resources'))
        ->assertSessionHas('success', 'Resource deleted successfully.');

    expect(Resource::find($resource->id))->toBeNull();
});

it('allows group leaders to delete draft resources', function (): void {
    $leader = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
    $resource = Resource::factory()->create(['doi' => null]);

    $this->actingAs($leader)
        ->delete(route('resources.destroy', $resource))
        ->assertRedirect(route('resources'));

    expect(Resource::find($resource->id))->toBeNull();
});

it('allows curators to delete draft resources', function (): void {
    $curator = User::factory()->create(['role' => UserRole::CURATOR]);
    $resource = Resource::factory()->create(['doi' => null]);

    $this->actingAs($curator)
        ->delete(route('resources.destroy', $resource))
        ->assertRedirect(route('resources'));

    expect(Resource::find($resource->id))->toBeNull();
});

it('allows admins to delete curation resources', function (): void {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $resource = createCompleteResourceForDeletion();

    expect($resource->publicStatus())->toBe('curation');

    $this->actingAs($admin)
        ->delete(route('resources.destroy', $resource))
        ->assertRedirect(route('resources'));

    expect(Resource::find($resource->id))->toBeNull();
});

it('allows admins to delete draft resources with persistent identifiers', function (): void {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $resource = Resource::factory()->create(['doi' => '10.5880/test.2026.002']);

    expect($resource->publicStatus())->toBe('draft');

    $this->actingAs($admin)
        ->delete(route('resources.destroy', $resource))
        ->assertRedirect(route('resources'));

    expect(Resource::find($resource->id))->toBeNull();
});

it('allows admins to delete review resources and cascades their landing pages', function (): void {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $resource = createReviewResourceForDeletion();
    $landingPageId = $resource->landingPage?->id;

    expect($resource->publicStatus())->toBe('review')
        ->and($landingPageId)->toBeInt();

    $this->actingAs($admin)
        ->delete(route('resources.destroy', $resource))
        ->assertRedirect(route('resources'));

    expect(Resource::find($resource->id))->toBeNull()
        ->and(LandingPage::find($landingPageId))->toBeNull();
});

it('allows admins to delete published resources and serves the branded 404 page at the former DOI URL', function (): void {
    Http::preventStrayRequests();
    Log::spy();

    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $resource = createPublishedResourceForDeletion();
    $resourceId = $resource->id;
    $landingPageId = $resource->landingPage?->id;
    $publicUrl = $resource->landingPage?->public_url;

    expect($resource->publicStatus())->toBe('published')
        ->and($landingPageId)->toBeInt()
        ->and($publicUrl)->toBeString();

    $this->actingAs($admin)
        ->delete(route('resources.destroy', $resource))
        ->assertRedirect(route('resources'))
        ->assertSessionHas('success', 'Resource deleted successfully.');

    expect(Resource::find($resourceId))->toBeNull()
        ->and(LandingPage::find($landingPageId))->toBeNull()
        ->and(OaiPmhDeletedRecord::where('doi', '10.5880/test.published.001')->exists())->toBeTrue();

    $this->get($publicUrl)
        ->assertNotFound()
        ->assertSeeText('This page is no longer available.');

    Log::shouldHaveReceived('warning')->with(
        'Published resources deleted from ERNIE',
        Mockery::on(fn (array $context): bool => $context['operation'] === 'single'
            && $context['user_id'] === $admin->id
            && $context['user_role'] === UserRole::ADMIN->value
            && $context['resources'] === [[
                'resource_id' => $resourceId,
                'doi' => '10.5880/test.published.001',
            ]])
    )->once();
});

it('allows group leaders to delete published resources', function (): void {
    $leader = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
    $resource = createPublishedResourceForDeletion('10.5880/test.published.group-leader');
    $landingPageId = $resource->landingPage?->id;

    $this->actingAs($leader)
        ->delete(route('resources.destroy', $resource))
        ->assertRedirect(route('resources'));

    expect(Resource::find($resource->id))->toBeNull()
        ->and(LandingPage::find($landingPageId))->toBeNull();
});

it('forbids curators from deleting published resources', function (): void {
    $curator = User::factory()->create(['role' => UserRole::CURATOR]);
    $resource = createPublishedResourceForDeletion('10.5880/test.published.curator');

    $this->actingAs($curator)
        ->delete(route('resources.destroy', $resource))
        ->assertForbidden();

    expect(Resource::find($resource->id))->not->toBeNull();
});

it('rejects guests from deleting resources', function (): void {
    $resource = Resource::factory()->create();

    $this->delete(route('resources.destroy', $resource))
        ->assertRedirect('/login');

    expect(Resource::find($resource->id))->not->toBeNull();
});

it('allows admins to batch delete non-published resources', function (): void {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $draft = Resource::factory()->create(['doi' => null]);
    $curation = createCompleteResourceForDeletion(['doi' => null]);
    $review = createReviewResourceForDeletion('10.5880/test.review.batch');
    $landingPageId = $review->landingPage?->id;

    $this->actingAs($admin)
        ->delete(route('resources.batch-destroy'), [
            'ids' => [$draft->id, $curation->id, $review->id],
        ])
        ->assertRedirect(route('resources'))
        ->assertSessionHas('success', '3 resources deleted successfully.');

    expect(Resource::find($draft->id))->toBeNull()
        ->and(Resource::find($curation->id))->toBeNull()
        ->and(Resource::find($review->id))->toBeNull()
        ->and(LandingPage::find($landingPageId))->toBeNull();
});

it('allows privileged roles to batch delete mixed resource statuses', function (UserRole $role): void {
    Log::spy();

    $user = User::factory()->create(['role' => $role]);
    $draft = Resource::factory()->create(['doi' => null]);
    $publishedDoi = '10.5880/test.published.batch.'.$role->value;
    $published = createPublishedResourceForDeletion($publishedDoi);
    $publishedId = $published->id;
    $landingPageId = $published->landingPage?->id;

    $this->actingAs($user)
        ->delete(route('resources.batch-destroy'), [
            'ids' => [$draft->id, $published->id],
        ])
        ->assertRedirect(route('resources'))
        ->assertSessionHas('success', '2 resources deleted successfully.');

    expect(Resource::find($draft->id))->toBeNull()
        ->and(Resource::find($published->id))->toBeNull()
        ->and(LandingPage::find($landingPageId))->toBeNull()
        ->and(OaiPmhDeletedRecord::where('doi', $publishedDoi)->exists())->toBeTrue();

    Log::shouldHaveReceived('warning')->with(
        'Published resources deleted from ERNIE',
        Mockery::on(fn (array $context): bool => $context['operation'] === 'batch'
            && $context['user_id'] === $user->id
            && $context['user_role'] === $role->value
            && $context['resources'] === [[
                'resource_id' => $publishedId,
                'doi' => $publishedDoi,
            ]])
    )->once();
})->with([
    'admin' => UserRole::ADMIN,
    'group leader' => UserRole::GROUP_LEADER,
]);

it('allows admins to batch delete a single resource from duplicate selections', function (): void {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $resource = Resource::factory()->create(['doi' => null]);

    $this->actingAs($admin)
        ->delete(route('resources.batch-destroy'), [
            'ids' => [$resource->id, $resource->id],
        ])
        ->assertRedirect(route('resources'))
        ->assertSessionHas('success', 'Resource deleted successfully.');

    expect(Resource::find($resource->id))->toBeNull();
});

it('normalizes duplicate batch delete ids before applying the maximum batch size', function (): void {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $resource = Resource::factory()->create(['doi' => null]);

    $this->actingAs($admin)
        ->delete(route('resources.batch-destroy'), [
            'ids' => array_fill(0, DestroyResourcesRequest::MAX_BATCH_SIZE + 1, (string) $resource->id),
        ])
        ->assertRedirect(route('resources'))
        ->assertSessionHas('success', 'Resource deleted successfully.');

    expect(Resource::find($resource->id))->toBeNull();
});

it('preserves malformed batch delete ids while deduplicating valid integer ids', function (): void {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $resource = Resource::factory()->create(['doi' => null]);

    $this->actingAs($admin)
        ->from(route('resources'))
        ->delete(route('resources.batch-destroy'), [
            'ids' => [$resource->id, (string) $resource->id, "{$resource->id}.0"],
        ])
        ->assertRedirect(route('resources'))
        ->assertSessionHasErrors('ids.1');

    expect(Resource::find($resource->id))->not->toBeNull();
});

it('rejects curator batch deletion when any submitted resource is published', function (): void {
    $curator = User::factory()->create(['role' => UserRole::CURATOR]);
    $safeDraft = Resource::factory()->create(['doi' => null]);
    $published = createPublishedResourceForDeletion('10.5880/test.published.batch');

    $this->actingAs($curator)
        ->from(route('resources'))
        ->delete(route('resources.batch-destroy'), [
            'ids' => [$safeDraft->id, $published->id],
        ])
        ->assertRedirect(route('resources'))
        ->assertSessionHasErrors([
            'ids' => 'Published resources can only be deleted by Admins and Group Leaders.',
        ]);

    expect(Resource::find($safeDraft->id))->not->toBeNull()
        ->and(Resource::find($published->id))->not->toBeNull();
});

it('rejects beginners from batch deleting resources', function (): void {
    $beginner = User::factory()->create(['role' => UserRole::BEGINNER]);
    $resource = Resource::factory()->create(['doi' => null]);

    $this->actingAs($beginner)
        ->from(route('resources'))
        ->delete(route('resources.batch-destroy'), [
            'ids' => [$resource->id],
        ])
        ->assertRedirect(route('resources'))
        ->assertSessionHasErrors([
            'ids' => 'You do not have permission to delete the selected resources.',
        ]);

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

it('reports missing batch delete resources from the controller without exposing individual ids', function (): void {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $missingId = ((int) Resource::query()->max('id')) + 1;

    $this->actingAs($admin)
        ->from(route('resources'))
        ->delete(route('resources.batch-destroy'), [
            'ids' => [$missingId],
        ])
        ->assertRedirect(route('resources'))
        ->assertSessionHasErrors([
            'ids' => 'Some selected resources could not be found.',
        ]);
});

it('rejects guests from batch deleting resources', function (): void {
    $resource = Resource::factory()->create(['doi' => null]);

    $this->delete(route('resources.batch-destroy'), [
        'ids' => [$resource->id],
    ])->assertRedirect('/login');

    expect(Resource::find($resource->id))->not->toBeNull();
});

<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Requests\Resource\DestroyResourcesRequest;
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

it('forbids admins from deleting published resources', function (): void {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $resource = createPublishedResourceForDeletion();

    expect($resource->publicStatus())->toBe('published');

    $this->actingAs($admin)
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

it('rejects batch deletion when any submitted resource is published', function (): void {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $safeDraft = Resource::factory()->create(['doi' => null]);
    $published = createPublishedResourceForDeletion('10.5880/test.published.batch');

    $this->actingAs($admin)
        ->from(route('resources'))
        ->delete(route('resources.batch-destroy'), [
            'ids' => [$safeDraft->id, $published->id],
        ])
        ->assertRedirect(route('resources'))
        ->assertSessionHasErrors([
            'ids' => 'Published resources cannot be deleted.',
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

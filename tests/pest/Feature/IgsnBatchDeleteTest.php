<?php

use App\Enums\UserRole;
use App\Models\IgsnMetadata;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Seed required data
    $this->artisan('db:seed', ['--class' => 'TitleTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'ResourceTypeSeeder']);
});

/**
 * Helper function to create IGSN resources.
 *
 * @return \Illuminate\Support\Collection<int, Resource>
 */
function createIgsns(int $count): \Illuminate\Support\Collection
{
    $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();
    $mainTitleType = TitleType::where('slug', 'MainTitle')->first();

    return collect(range(1, $count))->map(function ($i) use ($physicalObjectType, $mainTitleType) {
        $resource = Resource::factory()->create([
            'resource_type_id' => $physicalObjectType->id,
            'doi' => "IGSN-BATCH-TEST-{$i}",
            'publication_year' => 2026,
        ]);

        $resource->titles()->create([
            'value' => "Test IGSN Sample {$i}",
            'title_type_id' => $mainTitleType->id,
            'position' => 1,
        ]);

        $person = Person::factory()->create([
            'family_name' => 'Smith',
            'given_name' => 'Jane',
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => 1,
        ]);

        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'sample_type' => 'Rock',
            'material' => 'Granite',
            'upload_status' => 'pending',
        ]);

        return $resource;
    });
}

describe('IGSN Batch Delete', function () {
    it('allows admin to delete multiple IGSNs', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $igsns = createIgsns(3);

        $ids = $igsns->pluck('id')->toArray();

        $response = $this->actingAs($admin)
            ->delete('/igsns/batch', ['ids' => $ids]);

        $response->assertRedirect('/igsns');
        $response->assertSessionHas('success');

        // Verify all IGSNs are deleted
        expect(Resource::whereIn('id', $ids)->count())->toBe(0);
        expect(IgsnMetadata::whereIn('resource_id', $ids)->count())->toBe(0);
    });

    it('allows admin to delete a single IGSN via batch endpoint', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $igsns = createIgsns(1);

        $ids = $igsns->pluck('id')->toArray();

        $response = $this->actingAs($admin)
            ->delete('/igsns/batch', ['ids' => $ids]);

        $response->assertRedirect('/igsns');
        $response->assertSessionHas('success', 'IGSN deleted successfully.');

        expect(Resource::whereIn('id', $ids)->count())->toBe(0);
    });

    it('returns correct message for multiple deletions', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $igsns = createIgsns(3);

        $ids = $igsns->pluck('id')->toArray();

        $response = $this->actingAs($admin)
            ->delete('/igsns/batch', ['ids' => $ids]);

        $response->assertSessionHas('success', '3 IGSNs deleted successfully.');
    });

    it('prevents curator from deleting IGSNs', function () {
        $curator = User::factory()->create(['role' => UserRole::CURATOR]);
        $igsns = createIgsns(2);

        $ids = $igsns->pluck('id')->toArray();

        $response = $this->actingAs($curator)
            ->delete('/igsns/batch', ['ids' => $ids]);

        $response->assertForbidden();

        // Verify IGSNs still exist
        expect(Resource::whereIn('id', $ids)->count())->toBe(2);
    });

    it('prevents group leader from deleting IGSNs', function () {
        $groupLeader = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
        $igsns = createIgsns(2);

        $ids = $igsns->pluck('id')->toArray();

        $response = $this->actingAs($groupLeader)
            ->delete('/igsns/batch', ['ids' => $ids]);

        $response->assertForbidden();

        expect(Resource::whereIn('id', $ids)->count())->toBe(2);
    });

    it('prevents beginner from deleting IGSNs', function () {
        $beginner = User::factory()->create(['role' => UserRole::BEGINNER]);
        $igsns = createIgsns(2);

        $ids = $igsns->pluck('id')->toArray();

        $response = $this->actingAs($beginner)
            ->delete('/igsns/batch', ['ids' => $ids]);

        $response->assertForbidden();

        expect(Resource::whereIn('id', $ids)->count())->toBe(2);
    });

    it('prevents unauthenticated users from deleting IGSNs', function () {
        $igsns = createIgsns(2);

        $ids = $igsns->pluck('id')->toArray();

        $response = $this->delete('/igsns/batch', ['ids' => $ids]);

        $response->assertRedirect('/login');

        expect(Resource::whereIn('id', $ids)->count())->toBe(2);
    });

    it('validates that all IDs are valid IGSNs', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        // Create a regular resource without IGSN metadata
        $regularResource = Resource::factory()->create();

        $response = $this->actingAs($admin)
            ->delete('/igsns/batch', ['ids' => [$regularResource->id]]);

        $response->assertStatus(422);

        // Verify regular resource still exists
        expect(Resource::where('id', $regularResource->id)->exists())->toBeTrue();
    });

    it('rejects mixed IGSN and non-IGSN resources', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        $igsns = createIgsns(2);
        $regularResource = Resource::factory()->create();

        $ids = $igsns->pluck('id')->toArray();
        $ids[] = $regularResource->id;

        $response = $this->actingAs($admin)
            ->delete('/igsns/batch', ['ids' => $ids]);

        $response->assertStatus(422);

        // Verify nothing was deleted
        expect(Resource::whereIn('id', $igsns->pluck('id'))->count())->toBe(2);
        expect(Resource::where('id', $regularResource->id)->exists())->toBeTrue();
    });

    it('requires at least one ID', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        $response = $this->actingAs($admin)
            ->delete('/igsns/batch', ['ids' => []]);

        $response->assertSessionHasErrors('ids');
    });

    it('requires ids parameter', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        $response = $this->actingAs($admin)
            ->delete('/igsns/batch', []);

        $response->assertSessionHasErrors('ids');
    });

    it('validates that IDs must be integers', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        $response = $this->actingAs($admin)
            ->delete('/igsns/batch', ['ids' => ['invalid', 'ids']]);

        $response->assertSessionHasErrors('ids.0');
    });

    it('validates that IDs must exist in database', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        $response = $this->actingAs($admin)
            ->delete('/igsns/batch', ['ids' => [99999, 99998]]);

        $response->assertSessionHasErrors('ids.0');
    });
});

describe('IGSN List Page Selection', function () {
    it('shows canDelete true for admin users', function () {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        createIgsns(2);

        $response = $this->actingAs($admin)->get('/igsns');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->component('igsns/index')
                ->where('canDelete', true)
        );
    });

    it('shows canDelete false for non-admin users', function () {
        $curator = User::factory()->create(['role' => UserRole::CURATOR]);
        createIgsns(2);

        $response = $this->actingAs($curator)->get('/igsns');

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->component('igsns/index')
                ->where('canDelete', false)
        );
    });
});

<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Enums\UserRole;
use App\Models\Affiliation;
use App\Models\LandingPage;
use App\Models\OaiPmhDeletedRecord;
use App\Models\Person;
use App\Models\Publisher;
use App\Models\Resource;
use App\Models\ResourceAssessment;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

/**
 * Test: Delete All Resources (Admin Bulk Cleanup)
 *
 * Tests the admin-only endpoint that deletes all resources (datasets + IGSNs)
 * while preserving settings, lookup tables, and user accounts.
 */
it('allows admin to delete all resources', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    Resource::factory()->count(3)->create();

    actingAs($admin)
        ->delete(route('resources.destroy-all'), ['confirmation' => 'delete'])
        ->assertRedirect(route('logs.index'))
        ->assertSessionHas('success');

    expect(Resource::count())->toBe(0);
});

it('forbids non-admin users from deleting all resources', function (UserRole $role) {
    $user = User::factory()->create(['role' => $role]);
    Resource::factory()->count(2)->create();

    actingAs($user)
        ->delete(route('resources.destroy-all'), ['confirmation' => 'delete'])
        ->assertForbidden();

    expect(Resource::count())->toBe(2);
})->with([
    'group_leader' => UserRole::GROUP_LEADER,
    'curator' => UserRole::CURATOR,
    'beginner' => UserRole::BEGINNER,
]);

it('requires correct confirmation text', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    Resource::factory()->count(2)->create();

    actingAs($admin)
        ->delete(route('resources.destroy-all'), ['confirmation' => 'wrong'])
        ->assertSessionHasErrors('confirmation');

    expect(Resource::count())->toBe(2);
});

it('fails without confirmation parameter', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    Resource::factory()->create();

    actingAs($admin)
        ->delete(route('resources.destroy-all'), [])
        ->assertSessionHasErrors('confirmation');

    expect(Resource::count())->toBe(1);
});

it('preserves users after deleting all resources', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $otherUser = User::factory()->create(['role' => UserRole::CURATOR]);
    Resource::factory()->count(2)->create();

    $userCountBefore = User::count();

    actingAs($admin)
        ->delete(route('resources.destroy-all'), ['confirmation' => 'delete']);

    expect(Resource::count())->toBe(0);
    expect(User::count())->toBe($userCountBefore);
});

it('preserves resource types after deleting all resources', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    Resource::factory()->count(2)->create();

    $typeCountBefore = ResourceType::count();

    actingAs($admin)
        ->delete(route('resources.destroy-all'), ['confirmation' => 'delete']);

    expect(Resource::count())->toBe(0);
    expect(ResourceType::count())->toBe($typeCountBefore);
});

it('cleans up orphaned persons after deleting all resources', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $resource = Resource::factory()->create();

    // Create a person linked to the resource via resource_creators
    $person = Person::factory()->create();
    $resource->creators()->create([
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'position' => 0,
    ]);

    expect(Person::count())->toBeGreaterThan(0);

    actingAs($admin)
        ->delete(route('resources.destroy-all'), ['confirmation' => 'delete']);

    expect(Resource::count())->toBe(0);
    expect(Person::count())->toBe(0);
});

it('cleans up orphaned creator and contributor affiliations after deleting all resources', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $resource = Resource::factory()->create();

    $person = Person::factory()->create();
    $creator = $resource->creators()->create([
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'position' => 0,
    ]);
    $contributor = $resource->contributors()->create([
        'contributorable_type' => Person::class,
        'contributorable_id' => $person->id,
        'position' => 0,
    ]);

    Affiliation::create([
        'affiliatable_type' => ResourceCreator::class,
        'affiliatable_id' => $creator->id,
        'name' => 'Test Institute',
    ]);
    Affiliation::create([
        'affiliatable_type' => ResourceContributor::class,
        'affiliatable_id' => $contributor->id,
        'name' => 'Contributor Test Institute',
    ]);

    actingAs($admin)
        ->delete(route('resources.destroy-all'), ['confirmation' => 'delete']);

    expect(Resource::count())->toBe(0);
    expect(Affiliation::count())->toBe(0);
});

it('cleans up orphaned publishers after deleting all resources', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);

    // The factory creates a default publisher and links it to the resource
    Resource::factory()->count(2)->create();

    actingAs($admin)
        ->delete(route('resources.destroy-all'), ['confirmation' => 'delete']);

    expect(Resource::count())->toBe(0);
    // Publishers that were only linked to resources should be cleaned up
    expect(Publisher::whereDoesntHave('resources')->count())->toBe(0);
});

it('passes can_delete_all_resources flag to logs page for admin', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);

    actingAs($admin)
        ->get(route('logs.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Logs/Index')
            ->where('can_delete_all_resources', true)
        );
});

it('does not pass can_delete_all_resources flag for non-admin', function () {
    // Non-admins cannot access logs at all (access-logs gate), so we test
    // that the gate itself restricts access
    $curator = User::factory()->create(['role' => UserRole::CURATOR]);

    actingAs($curator)
        ->get(route('logs.index'))
        ->assertForbidden();
});

it('succeeds even when no resources exist', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);

    expect(Resource::count())->toBe(0);

    actingAs($admin)
        ->delete(route('resources.destroy-all'), ['confirmation' => 'delete'])
        ->assertRedirect(route('logs.index'))
        ->assertSessionHas('success');
});

it('tracks published DOI resources as OAI-PMH deleted records during bulk deletion', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $doi = '10.5880/bulk-delete-test';
    $resource = Resource::factory()
        ->withDoi($doi)
        ->withPublicationYear(2026)
        ->create();

    LandingPage::factory()
        ->published()
        ->withDoi($doi)
        ->create(['resource_id' => $resource->id]);

    actingAs($admin)
        ->delete(route('resources.destroy-all'), ['confirmation' => 'delete'])
        ->assertRedirect(route('logs.index'));

    $deletedRecord = OaiPmhDeletedRecord::query()
        ->where('doi', $doi)
        ->first();

    if ($deletedRecord === null) {
        throw new RuntimeException('Expected OAI-PMH deleted record was not created.');
    }

    expect($deletedRecord->sets)->toContain('resourcetype:dataset')
        ->and($deletedRecord->sets)->toContain('year:2026');
});

it('bumps the assessment summary cache version when deleting assessed resources in bulk', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $resource = Resource::factory()->create();

    ResourceAssessment::withoutEvents(fn (): ResourceAssessment => ResourceAssessment::query()->create([
        'resource_id' => $resource->id,
        'status' => ResourceAssessment::STATUS_COMPLETED,
        'total_score' => 6.0,
        'assessed_at' => now(),
    ]));

    Cache::forever(CacheKey::ASSESSMENT_AVERAGE_SUMMARY->key('version'), 4);

    actingAs($admin)
        ->delete(route('resources.destroy-all'), ['confirmation' => 'delete']);

    expect((int) Cache::get(CacheKey::ASSESSMENT_AVERAGE_SUMMARY->key('version')))->toBe(5);
});

it('deletes the reported resource volume within a request-safe time budget', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $prototype = Resource::withoutEvents(fn (): Resource => Resource::factory()->create(['doi' => null]));
    DB::table('resources')->delete();

    $now = now();
    $rows = [];

    for ($i = 0; $i < 8674; $i++) {
        $rows[] = [
            'doi' => null,
            'identifier_type' => 'DOI',
            'publisher_id' => $prototype->publisher_id,
            'publication_year' => 2026,
            'resource_type_id' => $prototype->resource_type_id,
            'version' => '1.0',
            'language_id' => $prototype->language_id,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    foreach (array_chunk($rows, 1000) as $chunk) {
        DB::table('resources')->insert($chunk);
    }

    $startedAt = microtime(true);

    actingAs($admin)
        ->delete(route('resources.destroy-all'), ['confirmation' => 'delete'])
        ->assertRedirect(route('logs.index'))
        ->assertSessionHas('success');

    $elapsedSeconds = microtime(true) - $startedAt;
    $requestTimeoutBudgetSeconds = 120.0;

    expect(Resource::count())->toBe(0);
    expect($elapsedSeconds)->toBeLessThan($requestTimeoutBudgetSeconds);
})->group('serial');

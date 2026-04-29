<?php

declare(strict_types=1);

use App\Http\Resources\ResourceListItemResource;
use App\Models\Person;
use App\Models\Resource;
use App\Models\Title;
use App\Models\User;
use App\Services\Resources\ResourceQueryBuilder;

covers(ResourceQueryBuilder::class, ResourceListItemResource::class);

/**
 * Resource Query Performance Tests
 *
 * These tests verify that the resource listing endpoint uses efficient
 * eager loading and does not suffer from N+1 query problems.
 */
it('loads 50 resources with minimal queries using eager loading', function () {
    // Arrange: Create test data with creators
    $resources = Resource::factory()->count(50)->create();

    foreach ($resources as $resource) {
        Title::factory()->for($resource)->create();

        // Create 3 creators per resource
        for ($i = 1; $i <= 3; $i++) {
            $person = Person::factory()->create();
            $resource->creators()->create([
                'creatorable_type' => Person::class,
                'creatorable_id' => $person->id,
                'position' => $i,
            ]);
        }
    }

    // Act: Enable query logging and fetch resources via actual route
    DB::enableQueryLog();

    $response = $this->actingAs(User::factory()->create())
        ->get('/resources');

    $queries = DB::getQueryLog();
    $queryCount = count($queries);

    // Assert: Query count should stay bounded (no N+1).
    // 50 resources with 3 creators each = 150 creator entries; we still expect a small
    // constant number of queries thanks to eager loading. The exact count fluctuates
    // by ±1 with auth/session/policy resolution; this guardrail catches unbounded growth.
    expect($queryCount)->toBeLessThanOrEqual(19, "Expected at most 19 queries, but got {$queryCount}");
    $response->assertStatus(200);
});

it('detects N+1 queries in development environment', function () {
    // Skip test if not in local/testing environment where assertions are active
    if (! app()->environment('local', 'testing')) {
        $this->markTestSkipped('N+1 detection only runs in local/testing environment');
    }

    // Arrange: Create a resource with creator
    $resource = Resource::factory()->create();
    Title::factory()->for($resource)->create();

    $person = Person::factory()->create();
    $resource->creators()->create([
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'position' => 1,
    ]);

    // Act & Assert: Loading a resource without eager loading should throw a RuntimeException.
    // `assertRelationsLoaded` lives on ResourceListItemResource (private static); we invoke it
    // via reflection to verify the N+1 guardrail still trips.
    expect(function () use ($resource) {
        $reflection = new ReflectionClass(ResourceListItemResource::class);
        $method = $reflection->getMethod('assertRelationsLoaded');
        // The guardrail is a private static method; reflection must override
        // accessibility before invoking, otherwise PHP raises a reflection
        // error and masks the RuntimeException we want to assert.
        $method->setAccessible(true);

        // Load resource without eager loading relationships
        $freshResource = Resource::find($resource->id);

        // This should throw RuntimeException about missing relations
        $method->invoke(null, $freshResource);
    })->toThrow(RuntimeException::class, 'not loaded');
});

it('serializes resources efficiently with eager loaded relations', function () {
    // Arrange: Create resources with full relationship tree
    $resources = Resource::factory()->count(10)->create();

    foreach ($resources as $resource) {
        Title::factory()->count(2)->for($resource)->create();

        for ($i = 1; $i <= 2; $i++) {
            $person = Person::factory()->create();
            $resource->creators()->create([
                'creatorable_type' => Person::class,
                'creatorable_id' => $person->id,
                'position' => $i,
            ]);
        }
    }

    // Act: Fetch resources via actual route to test real-world behavior
    $user = User::factory()->create();

    DB::enableQueryLog();
    $response = $this->actingAs($user)
        ->get('/resources?per_page=10');

    $queries = DB::getQueryLog();
    $queryCount = count($queries);

    // Assert: Should meet optimization goal (+1 for descriptions eager loading,
    // +1 environment overhead — see large-scale test above).
    expect($queryCount)->toBeLessThanOrEqual(19, "Expected at most 19 queries for eager loading, got {$queryCount}");
    $response->assertStatus(200);
});

it('handles resources without creators efficiently', function () {
    // Arrange: Create resources without creators
    $resources = Resource::factory()->count(20)->create();

    foreach ($resources as $resource) {
        Title::factory()->for($resource)->create();
    }

    // Act: Enable query logging
    DB::enableQueryLog();

    $response = $this->actingAs(User::factory()->create())
        ->get('/resources');

    $queries = DB::getQueryLog();
    $queryCount = count($queries);

    // Assert: Should still use minimal queries even without creators (+1 for descriptions eager loading,
    // +1 environment overhead).
    expect($queryCount)->toBeLessThanOrEqual(19);
    $response->assertStatus(200);
});

it('maintains performance with pagination', function () {
    // Arrange: Create 100 resources
    $resources = Resource::factory()->count(100)->create();

    foreach ($resources as $resource) {
        Title::factory()->for($resource)->create();

        $person = Person::factory()->create();
        $resource->creators()->create([
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => 1,
        ]);
    }

    // Use same authenticated user for both requests to ensure consistent query counts
    $user = User::factory()->create();

    // Act: Test first page
    DB::enableQueryLog();
    $response = $this->actingAs($user)
        ->get('/resources?page=1&per_page=50');

    $queriesPage1 = DB::getQueryLog();

    // Test second page with same user
    DB::flushQueryLog();
    $response2 = $this->actingAs($user)
        ->get('/resources?page=2&per_page=50');

    $queriesPage2 = DB::getQueryLog();

    // Assert: Both pages should meet optimization goal (+1 for descriptions eager loading,
    // +1 environment overhead).
    expect(count($queriesPage1))->toBeLessThanOrEqual(19);
    expect(count($queriesPage2))->toBeLessThanOrEqual(19);

    // Query counts should be nearly identical (allow max 2 queries difference for session overhead)
    expect(abs(count($queriesPage1) - count($queriesPage2)))->toBeLessThanOrEqual(2);

    $response->assertStatus(200);
    $response2->assertStatus(200);
});

it('does not eager-load contributors on list endpoints (Issue: PR #679 review)', function () {
    // Regression: ResourceListItemResource::toArray() does not surface any
    // contributor data, so the list query builder must not eager-load the
    // contributors relation (or its nested contributorable / contributorTypes
    // / affiliations) — doing so inflated query count and memory for every
    // listing without benefit.
    $resource = Resource::factory()->create();
    Title::factory()->for($resource)->create();

    $person = Person::factory()->create();
    $resource->contributors()->create([
        'contributorable_type' => Person::class,
        'contributorable_id' => $person->id,
        'position' => 1,
    ]);

    $loaded = app(ResourceQueryBuilder::class)->baseQuery()->find($resource->id);

    expect($loaded)->not->toBeNull();
    expect($loaded->relationLoaded('contributors'))
        ->toBeFalse('contributors must not be eager-loaded by the list query builder');
});

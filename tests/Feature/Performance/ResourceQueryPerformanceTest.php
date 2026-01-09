<?php

use App\Models\Person;
use App\Models\Resource;
use App\Models\Title;
use Illuminate\Support\Facades\DB;

/**
 * Resource Query Performance Tests
 *
 * These tests verify that the resource listing endpoint uses efficient
 * eager loading and does not suffer from N+1 query problems.
 */
beforeEach(function () {
    // Disable Vite to prevent manifest not found errors in testing
    $this->withoutVite();
});

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

    $response = $this->actingAs(\App\Models\User::factory()->create())
        ->get('/resources');

    $queries = DB::getQueryLog();
    $queryCount = count($queries);

    // Assert: Query count should meet optimization goal of 10-15 queries
    // Allow up to 20 for safety margin (session, auth, and other framework queries)
    expect($queryCount)->toBeLessThanOrEqual(20, "Expected at most 20 queries, but got {$queryCount}");
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

    // Act & Assert: Loading resource without eager loading should throw exception
    // We test via reflection since assertRelationsLoaded is private
    expect(function () use ($resource) {
        $controller = app(\App\Http\Controllers\ResourceController::class);
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('assertRelationsLoaded');

        // Load resource without eager loading relationships
        $freshResource = Resource::find($resource->id);

        // This should throw RuntimeException about missing relations
        $method->invoke($controller, $freshResource);
    })->toThrow(\RuntimeException::class, 'not loaded');
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
    $user = \App\Models\User::factory()->create();

    DB::enableQueryLog();
    $response = $this->actingAs($user)
        ->get('/resources?per_page=10');

    $queries = DB::getQueryLog();
    $queryCount = count($queries);

    // Assert: Should meet optimization goal
    expect($queryCount)->toBeLessThanOrEqual(20, "Expected at most 20 queries for eager loading, got {$queryCount}");
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

    $response = $this->actingAs(\App\Models\User::factory()->create())
        ->get('/resources');

    $queries = DB::getQueryLog();
    $queryCount = count($queries);

    // Assert: Should still use minimal queries even without creators
    expect($queryCount)->toBeLessThanOrEqual(20);
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
    $user = \App\Models\User::factory()->create();

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

    // Assert: Both pages should meet optimization goal
    expect(count($queriesPage1))->toBeLessThanOrEqual(20);
    expect(count($queriesPage2))->toBeLessThanOrEqual(20);

    // Query counts should be nearly identical (allow max 2 queries difference for session overhead)
    expect(abs(count($queriesPage1) - count($queriesPage2)))->toBeLessThanOrEqual(2);

    $response->assertStatus(200);
    $response2->assertStatus(200);
});

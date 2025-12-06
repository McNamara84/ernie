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

    // Act: Enable query logging and fetch resources
    DB::enableQueryLog();
    
    $response = $this->actingAs(\App\Models\User::factory()->create())
        ->get('/resources');
    
    $queries = DB::getQueryLog();
    $queryCount = count($queries);

    // Assert: Should use fewer than 20 queries (target: ~10-15)
    expect($queryCount)->toBeLessThan(20, "Expected fewer than 20 queries, but got {$queryCount}");
    $response->assertStatus(200);
});

it('detects N+1 queries in development environment', function () {
    // Arrange: Create a resource with creator
    $resource = Resource::factory()->create();
    Title::factory()->for($resource)->create();
    
    $person = Person::factory()->create();
    $resource->creators()->create([
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'position' => 1,
    ]);

    // Set environment to local to enable assertions
    app()->instance('env', 'local');

    // Act & Assert: Loading resource without eager loading should throw exception
    expect(function () use ($resource) {
        $controller = new \App\Http\Controllers\ResourceController();
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('serializeResource');
        $method->setAccessible(true);
        
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

    // Act: Fetch resources using the controller's baseQuery
    $controller = new \App\Http\Controllers\ResourceController();
    $reflection = new \ReflectionClass($controller);
    $baseQueryMethod = $reflection->getMethod('baseQuery');
    $baseQueryMethod->setAccessible(true);

    DB::enableQueryLog();
    
    $query = $baseQueryMethod->invoke($controller);
    $resources = $query->limit(10)->get();
    
    $queries = DB::getQueryLog();
    $queryCount = count($queries);

    // Assert: Should load all resources with relationships in minimal queries
    expect($resources)->toHaveCount(10);
    expect($queryCount)->toBeLessThan(15, "Expected fewer than 15 queries for eager loading, got {$queryCount}");
    
    // Verify all relations are loaded on first resource
    $firstResource = $resources->first();
    expect($firstResource->relationLoaded('creators'))->toBeTrue();
    expect($firstResource->relationLoaded('titles'))->toBeTrue();
    expect($firstResource->relationLoaded('rights'))->toBeTrue();
    expect($firstResource->relationLoaded('resourceType'))->toBeTrue();
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
    expect($queryCount)->toBeLessThan(15);
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

    // Act: Test first page
    DB::enableQueryLog();
    
    $response = $this->actingAs(\App\Models\User::factory()->create())
        ->get('/resources?page=1&per_page=50');
    
    $queriesPage1 = DB::getQueryLog();
    
    // Test second page
    DB::flushQueryLog();
    
    $response2 = $this->actingAs(\App\Models\User::factory()->create())
        ->get('/resources?page=2&per_page=50');
    
    $queriesPage2 = DB::getQueryLog();

    // Assert: Both pages should use similar query counts
    expect(count($queriesPage1))->toBeLessThan(20);
    expect(count($queriesPage2))->toBeLessThan(20);
    
    $response->assertStatus(200);
    $response2->assertStatus(200);
});

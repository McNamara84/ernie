<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Services\ResourceCacheService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('non-inertia web responses do not resolve lazy shared resource counts', function () {
    $resourceCache = Mockery::mock(ResourceCacheService::class);
    $resourceCache->shouldReceive('getPhysicalObjectTypeId')->never();
    $resourceCache->shouldReceive('getDataResourceCount')->never();
    $resourceCache->shouldReceive('getIgsnCount')->never();

    app()->instance(ResourceCacheService::class, $resourceCache);

    $user = User::factory()->create([
        'role' => UserRole::BEGINNER,
    ]);

    $this->actingAs($user)
        ->get('/sanctum/csrf-cookie')
        ->assertNoContent();
});
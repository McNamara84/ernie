<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Resource;
use App\Models\ResourceAssessment;
use App\Models\ResourceType;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\ResourceCacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

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

test('assessment average summary is shared for assessment users', function () {
    $physicalObjectType = ResourceType::factory()->create([
        'name' => 'Physical Object',
        'slug' => 'physical-object',
    ]);

    $dataset = Resource::factory()->create();
    $igsn = Resource::factory()->create([
        'resource_type_id' => $physicalObjectType->id,
    ]);

    ResourceAssessment::query()->create([
        'resource_id' => $dataset->id,
        'status' => ResourceAssessment::STATUS_COMPLETED,
        'total_score' => 6.85,
        'assessed_at' => now(),
    ]);

    ResourceAssessment::query()->create([
        'resource_id' => $igsn->id,
        'status' => ResourceAssessment::STATUS_COMPLETED,
        'total_score' => 3.24,
        'assessed_at' => now(),
    ]);

    $user = User::factory()->create([
        'role' => UserRole::ADMIN,
    ]);

    $request = Request::create('/dashboard');
    $request->setUserResolver(fn () => $user);

    $shared = (new HandleInertiaRequests)->share($request);
    $summary = value($shared['assessmentAverageSummary']);

    expect($summary)->toBe([
        'resources' => 6.9,
        'igsns' => 3.2,
        'formatted' => '6.9 / 3.2',
    ]);
});

test('assessment average summary is not shared for users without assessment access', function () {
    $user = User::factory()->create([
        'role' => UserRole::GROUP_LEADER,
    ]);

    $request = Request::create('/dashboard');
    $request->setUserResolver(fn () => $user);

    $shared = (new HandleInertiaRequests)->share($request);

    expect($shared['assessmentAverageSummary'])->toBeNull();
});

test('assessment average summary resolver returns an empty summary for guests', function () {
    $request = Request::create('/dashboard');

    $resolver = \Closure::bind(
        fn (Request $request): array => $this->resolveSharedAssessmentAverageSummary($request),
        new HandleInertiaRequests(),
        HandleInertiaRequests::class,
    );

    expect($resolver)->not->toBeNull();
    expect($resolver?->__invoke($request))->toBe([
        'resources' => null,
        'igsns' => null,
        'formatted' => null,
    ]);
});

test('assessment average summary resolver returns an empty summary for unauthorized users', function () {
    $user = User::factory()->create([
        'role' => UserRole::GROUP_LEADER,
    ]);

    $request = Request::create('/dashboard');
    $request->setUserResolver(fn () => $user);

    $resolver = \Closure::bind(
        fn (Request $request): array => $this->resolveSharedAssessmentAverageSummary($request),
        new HandleInertiaRequests(),
        HandleInertiaRequests::class,
    );

    expect($resolver)->not->toBeNull();
    expect($resolver?->__invoke($request))->toBe([
        'resources' => null,
        'igsns' => null,
        'formatted' => null,
    ]);
});
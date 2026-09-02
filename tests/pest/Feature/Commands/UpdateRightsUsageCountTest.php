<?php

use App\Enums\ResourceWorkflowStatus;
use App\Models\Resource;
use App\Models\Right;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('updates rights usage counts correctly', function () {
    // Create rights
    $mit = Right::create([
        'identifier' => 'MIT',
        'name' => 'MIT License',
        'usage_count' => 0,
        'is_active' => true,
    ]);
    $apache = Right::create([
        'identifier' => 'Apache-2.0',
        'name' => 'Apache License',
        'usage_count' => 0,
        'is_active' => true,
    ]);
    $gpl = Right::create([
        'identifier' => 'GPL-3.0',
        'name' => 'GPL License',
        'usage_count' => 0,
        'is_active' => true,
    ]);

    // Create resources and associate rights
    $resource1 = Resource::factory()->create();
    $resource1->rights()->attach([$mit->id, $apache->id]);

    $resource2 = Resource::factory()->create();
    $resource2->rights()->attach([$mit->id]);

    $resource3 = Resource::factory()->create();
    $resource3->rights()->attach([$mit->id, $gpl->id]);

    // Run the command
    $this->artisan('rights:update-usage-count')
        ->expectsOutput('Calculating rights usage counts...')
        ->expectsOutputToContain('Successfully calculated usage counts for 3 rights (3 used) across 3 resources in ')
        ->assertExitCode(0);

    // Verify usage counts
    expect($mit->fresh()->usage_count)->toBe(3)
        ->and($apache->fresh()->usage_count)->toBe(1)
        ->and($gpl->fresh()->usage_count)->toBe(1);
});

it('resets usage counts to zero for unused rights', function () {
    // Create rights with existing usage counts
    $mit = Right::create([
        'identifier' => 'MIT',
        'name' => 'MIT License',
        'usage_count' => 10,
        'is_active' => true,
    ]);
    $apache = Right::create([
        'identifier' => 'Apache-2.0',
        'name' => 'Apache License',
        'usage_count' => 5,
        'is_active' => true,
    ]);

    // Create resource with only MIT right
    $resource = Resource::factory()->create();
    $resource->rights()->attach([$mit->id]);

    // Run the command
    $this->artisan('rights:update-usage-count')
        ->assertExitCode(0);

    // MIT should have count 1, Apache should be reset to 0
    expect($mit->fresh()->usage_count)->toBe(1)
        ->and($apache->fresh()->usage_count)->toBe(0);
});

it('handles resources with no rights gracefully', function () {
    // Create rights
    Right::create([
        'identifier' => 'MIT',
        'name' => 'MIT License',
        'usage_count' => 0,
        'is_active' => true,
    ]);

    // Create resource without rights
    Resource::factory()->create();

    // Run the command
    $this->artisan('rights:update-usage-count')
        ->assertExitCode(0);
});

it('counts all stored resources regardless of workflow status', function () {
    $right = Right::factory()->create(['usage_count' => 0]);
    $draft = Resource::factory()->create([
        'workflow_status_override' => ResourceWorkflowStatus::DRAFT,
    ]);
    $review = Resource::factory()->create([
        'workflow_status_override' => ResourceWorkflowStatus::REVIEW,
    ]);
    $regular = Resource::factory()->create([
        'workflow_status_override' => null,
    ]);

    $draft->rights()->attach($right);
    $review->rights()->attach($right);
    $regular->rights()->attach($right);

    $this->artisan('rights:update-usage-count')
        ->expectsOutputToContain('1 rights (1 used) across 3 resources')
        ->assertSuccessful();

    expect($right->fresh()->usage_count)->toBe(3);
});

it('is idempotent when resource associations do not change', function () {
    $right = Right::factory()->create(['usage_count' => 99]);
    $resources = Resource::factory()->count(2)->create();

    foreach ($resources as $resource) {
        $resource->rights()->attach($right);
    }

    $this->artisan('rights:update-usage-count')->assertSuccessful();
    expect($right->fresh()->usage_count)->toBe(2);

    $this->artisan('rights:update-usage-count')->assertSuccessful();
    expect($right->fresh()->usage_count)->toBe(2);
});

it('rolls back the usage count snapshot when an update fails', function () {
    $right = Right::factory()->create(['usage_count' => 17]);
    $resource = Resource::factory()->create();
    $resource->rights()->attach($right);
    $throwOnReset = true;

    DB::listen(function (QueryExecuted $query) use (&$throwOnReset): void {
        if ($throwOnReset && preg_match('/^update\s+[`"]?rights[`"]?\s+set/i', $query->sql) === 1) {
            $throwOnReset = false;

            throw new RuntimeException('Simulated usage count update failure.');
        }
    });

    expect(fn () => $this->artisan('rights:update-usage-count')->run())
        ->toThrow(RuntimeException::class, 'Simulated usage count update failure.');

    expect($right->fresh()->usage_count)->toBe(17);
});

<?php

use App\Models\Resource;
use App\Models\Right;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
    $this->artisan('licenses:update-usage-count')
        ->expectsOutput('Calculating license usage counts...')
        ->expectsOutput('Successfully updated usage counts for 3 licenses.')
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
    $this->artisan('licenses:update-usage-count')
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
    $this->artisan('licenses:update-usage-count')
        ->assertExitCode(0);
});

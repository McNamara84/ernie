<?php

use App\Models\License;
use App\Models\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('updates license usage counts correctly', function () {
    // Create licenses
    $mit = License::factory()->create(['identifier' => 'MIT', 'name' => 'MIT License', 'usage_count' => 0]);
    $apache = License::factory()->create(['identifier' => 'Apache', 'name' => 'Apache License', 'usage_count' => 0]);
    $gpl = License::factory()->create(['identifier' => 'GPL', 'name' => 'GPL License', 'usage_count' => 0]);

    // Create resources and associate licenses
    $resource1 = Resource::factory()->create();
    $resource1->licenses()->attach([$mit->id, $apache->id]);

    $resource2 = Resource::factory()->create();
    $resource2->licenses()->attach([$mit->id]);

    $resource3 = Resource::factory()->create();
    $resource3->licenses()->attach([$mit->id, $gpl->id]);

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

it('resets usage counts to zero for unused licenses', function () {
    // Create licenses with existing usage counts
    $mit = License::factory()->create(['identifier' => 'MIT', 'name' => 'MIT License', 'usage_count' => 10]);
    $apache = License::factory()->create(['identifier' => 'Apache', 'name' => 'Apache License', 'usage_count' => 5]);

    // Create resource with only MIT license
    $resource = Resource::factory()->create();
    $resource->licenses()->attach([$mit->id]);

    // Run the command
    $this->artisan('licenses:update-usage-count')
        ->assertExitCode(0);

    // MIT should have count 1, Apache should be reset to 0
    expect($mit->fresh()->usage_count)->toBe(1)
        ->and($apache->fresh()->usage_count)->toBe(0);
});

it('handles resources with no licenses gracefully', function () {
    // Create licenses
    $mit = License::factory()->create(['identifier' => 'MIT', 'name' => 'MIT License', 'usage_count' => 0]);

    // Create resource without licenses
    Resource::factory()->create();

    // Run the command
    $this->artisan('licenses:update-usage-count')
        ->assertExitCode(0);

    // MIT should still have count 0
    expect($mit->fresh()->usage_count)->toBe(0);
});

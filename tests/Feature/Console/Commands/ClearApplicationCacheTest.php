<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;

it('clears all caches when no category specified', function () {
    // Create cache entries with different tags
    Cache::tags(['resources'])->put('resource-key', 'value', 3600);
    Cache::tags(['vocabularies'])->put('vocab-key', 'value', 3600);
    Cache::tags(['ror'])->put('ror-key', 'value', 3600);

    // Verify all exist
    expect(Cache::tags(['resources'])->has('resource-key'))->toBeTrue();
    expect(Cache::tags(['vocabularies'])->has('vocab-key'))->toBeTrue();
    expect(Cache::tags(['ror'])->has('ror-key'))->toBeTrue();

    // Run command without category (defaults to 'all')
    $this->artisan('cache:clear-app')
        ->assertSuccessful()
        ->assertExitCode(0);

    // Verify all cleared
    expect(Cache::tags(['resources'])->has('resource-key'))->toBeFalse();
    expect(Cache::tags(['vocabularies'])->has('vocab-key'))->toBeFalse();
    expect(Cache::tags(['ror'])->has('ror-key'))->toBeFalse();
});

it('clears only resources cache', function () {
    // Create cache entries with different tags
    Cache::tags(['resources'])->put('resource-key', 'value', 3600);
    Cache::tags(['vocabularies'])->put('vocab-key', 'value', 3600);
    Cache::tags(['ror'])->put('ror-key', 'value', 3600);

    // Run command with 'resources' category
    $this->artisan('cache:clear-app', ['category' => 'resources'])
        ->assertSuccessful()
        ->assertExitCode(0);

    // Only resources should be cleared
    expect(Cache::tags(['resources'])->has('resource-key'))->toBeFalse();
    expect(Cache::tags(['vocabularies'])->has('vocab-key'))->toBeTrue();
    expect(Cache::tags(['ror'])->has('ror-key'))->toBeTrue();
});

it('clears only vocabularies cache', function () {
    Cache::tags(['resources'])->put('resource-key', 'value', 3600);
    Cache::tags(['vocabularies'])->put('vocab-key', 'value', 3600);

    $this->artisan('cache:clear-app', ['category' => 'vocabularies'])
        ->assertSuccessful();

    expect(Cache::tags(['resources'])->has('resource-key'))->toBeTrue();
    expect(Cache::tags(['vocabularies'])->has('vocab-key'))->toBeFalse();
});

it('clears only ROR cache', function () {
    Cache::tags(['resources'])->put('resource-key', 'value', 3600);
    Cache::tags(['ror'])->put('ror-key', 'value', 3600);

    $this->artisan('cache:clear-app', ['category' => 'ror'])
        ->assertSuccessful();

    expect(Cache::tags(['resources'])->has('resource-key'))->toBeTrue();
    expect(Cache::tags(['ror'])->has('ror-key'))->toBeFalse();
});

it('clears only ORCID cache', function () {
    Cache::tags(['resources'])->put('resource-key', 'value', 3600);
    Cache::tags(['orcid'])->put('orcid-key', 'value', 3600);

    $this->artisan('cache:clear-app', ['category' => 'orcid'])
        ->assertSuccessful();

    expect(Cache::tags(['resources'])->has('resource-key'))->toBeTrue();
    expect(Cache::tags(['orcid'])->has('orcid-key'))->toBeFalse();
});

it('clears only system cache', function () {
    Cache::tags(['resources'])->put('resource-key', 'value', 3600);
    Cache::tags(['system'])->put('system-key', 'value', 3600);

    $this->artisan('cache:clear-app', ['category' => 'system'])
        ->assertSuccessful();

    expect(Cache::tags(['resources'])->has('resource-key'))->toBeTrue();
    expect(Cache::tags(['system'])->has('system-key'))->toBeFalse();
});

it('fails with invalid category', function () {
    $this->artisan('cache:clear-app', ['category' => 'invalid'])
        ->assertFailed()
        ->assertExitCode(1);
});

it('displays success message', function () {
    $this->artisan('cache:clear-app', ['category' => 'resources'])
        ->expectsOutput("✓ Cache category 'resources' cleared successfully.")
        ->assertSuccessful();
});

it('displays success message for all categories', function () {
    $this->artisan('cache:clear-app', ['category' => 'all'])
        ->expectsOutput("✓ Cache category 'all' cleared successfully.")
        ->assertSuccessful();
});

<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('does not register routes for retired navigation pages and endpoints', function (): void {
    expect(Route::has('igsns.map'))->toBeFalse()
        ->and(Route::has('old-datasets'))->toBeFalse()
        ->and(Route::has('old-datasets.filter-options'))->toBeFalse()
        ->and(Route::has('old-statistics'))->toBeFalse();
});

it('returns not found for retired pages and endpoints without redirecting', function (string $path): void {
    $this->get($path)->assertNotFound();

    $this->actingAs(User::factory()->create())
        ->get($path)
        ->assertNotFound();
})->with([
    'IGSN map' => ['/igsns-map'],
    'old datasets' => ['/old-datasets'],
    'old dataset filter options' => ['/old-datasets/filter-options'],
    'old dataset detail endpoint' => ['/old-datasets/1/authors'],
    'old statistics' => ['/old-statistics'],
]);

it('keeps the IGSN portal and its map routes registered', function (): void {
    expect(Route::has('portal.igsn'))->toBeTrue()
        ->and(Route::has('portal.igsn.map'))->toBeTrue();
});

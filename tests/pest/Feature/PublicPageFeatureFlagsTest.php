<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;

dataset('public page flags', [
    'homepage' => ['/', 'home_enabled'],
    'find overview' => ['/find', 'find_enabled'],
    'data centres' => ['/data-centres', 'data_centres_enabled'],
    'data centre descriptions' => ['/data-centres/description', 'data_centre_description_enabled'],
]);

it('serves or disables each public page independently', function (bool $home, bool $find, bool $centres, bool $description, bool $authenticated): void {
    config([
        'public_pages.home_enabled' => $home,
        'public_pages.find_enabled' => $find,
        'public_pages.data_centres_enabled' => $centres,
        'public_pages.data_centre_description_enabled' => $description,
    ]);

    if ($authenticated) {
        $this->actingAs(User::factory()->unverified()->create());
    }

    foreach ([
        ['/', $home, 'home'],
        ['/find', $find, 'find'],
        ['/data-centres', $centres, 'data-centres/index'],
        ['/data-centres/description', $description, 'data-centres/description'],
    ] as [$path, $enabled, $component]) {
        $response = $this->get($path);

        if ($enabled) {
            $response->assertOk()->assertInertia(fn (Assert $page) => $page->component($component));
        } else {
            $response->assertNotFound()->assertHeaderMissing('Location');
        }
    }
})->with(['home enabled' => true, 'home disabled' => false])
    ->with(['find enabled' => true, 'find disabled' => false])
    ->with(['centres enabled' => true, 'centres disabled' => false])
    ->with(['descriptions enabled' => true, 'descriptions disabled' => false])
    ->with(['guest' => false, 'signed in' => true]);

it('returns a local not-found response for Inertia navigation to disabled pages', function (string $path, string $flag): void {
    config(['public_pages.'.$flag => false]);

    // Start on an ERNIE page so navigation carries the current asset version.
    $this->get('/about')->assertOk();

    $this->get($path.'?preview=true', ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion()])
        ->assertNotFound()
        ->assertHeaderMissing('X-Inertia-Location');
})->with('public page flags');

it('keeps other public pages and both search portals available when all four pages are disabled', function (): void {
    config([
        'public_pages.home_enabled' => false,
        'public_pages.find_enabled' => false,
        'public_pages.data_centres_enabled' => false,
        'public_pages.data_centre_description_enabled' => false,
    ]);

    foreach (['/about', '/legal-notice', '/doi-search', '/igsn-search', '/login', '/health'] as $path) {
        $this->get($path)->assertOk();
    }
});

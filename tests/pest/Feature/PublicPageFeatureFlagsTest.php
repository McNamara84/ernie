<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;

dataset('public page redirects', [
    'homepage' => ['/', 'home_enabled', 'https://dataservices.gfz-potsdam.de/'],
    'find overview' => ['/find', 'find_enabled', 'https://dataservices.gfz-potsdam.de/web/find'],
    'data centres' => ['/data-centres', 'data_centres_enabled', 'https://dataservices.gfz-potsdam.de/web/find/data-centres'],
    'data centre descriptions' => ['/data-centres/description', 'data_centre_description_enabled', 'https://dataservices.gfz-potsdam.de/web/find/data-centres/data-centre-description'],
]);

it('serves or redirects each public page independently', function (bool $home, bool $find, bool $centres, bool $description, bool $authenticated): void {
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
        ['/', $home, 'home', 'https://dataservices.gfz-potsdam.de/'],
        ['/find', $find, 'find', 'https://dataservices.gfz-potsdam.de/web/find'],
        ['/data-centres', $centres, 'data-centres/index', 'https://dataservices.gfz-potsdam.de/web/find/data-centres'],
        ['/data-centres/description', $description, 'data-centres/description', 'https://dataservices.gfz-potsdam.de/web/find/data-centres/data-centre-description'],
    ] as [$path, $enabled, $component, $target]) {
        $response = $this->get($path);

        if ($enabled) {
            $response->assertOk()->assertInertia(fn (Assert $page) => $page->component($component));
        } else {
            $response->assertStatus(302)->assertRedirect($target);
        }
    }
})->with(['home enabled' => true, 'home disabled' => false])
    ->with(['find enabled' => true, 'find disabled' => false])
    ->with(['centres enabled' => true, 'centres disabled' => false])
    ->with(['descriptions enabled' => true, 'descriptions disabled' => false])
    ->with(['guest' => false, 'signed in' => true]);

it('leaves Inertia navigation for TYPO3 when a public page is disabled', function (string $path, string $flag, string $target): void {
    config(['public_pages.'.$flag => false]);

    // Start on an ERNIE page so navigation carries the current asset version.
    $this->get('/about')->assertOk();

    $this->get($path.'?preview=true', ['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion()])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', $target);
})->with('public page redirects');

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

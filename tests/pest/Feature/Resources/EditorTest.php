<?php

use App\Enums\UserRole;
use App\Models\LandingPage;
use App\Models\LandingPageDomain;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\withoutVite;

uses(RefreshDatabase::class);

test('guests are redirected to login page', function () {
    $this->get(route('editor'))->assertRedirect(route('login'));
});

test('authenticated users can view editor page', function () {
    $this->actingAs(User::factory()->create());

    withoutVite();

    $response = $this->get(route('editor'))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page->component('editor')
        ->where('titles', [])
        ->where('initialLicenses', [])
    );
});

test('editor exposes DOI edit permission for an unpublished resource', function (UserRole $role, bool $expected) {
    $user = User::factory()->create(['role' => $role]);
    $resource = Resource::factory()->withDoi('10.5880/editor.permission.draft')->create();
    LandingPage::factory()->for($resource)->draft()->withDoi((string) $resource->doi)->create();

    withoutVite();

    $this->actingAs($user);

    loadExistingResourceInEditor($this, $resource->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('editor')
            ->where('canEditDoi', $expected)
        );
})->with([
    'admin' => [UserRole::ADMIN, true],
    'group leader' => [UserRole::GROUP_LEADER, true],
    'curator' => [UserRole::CURATOR, true],
    'beginner' => [UserRole::BEGINNER, false],
]);

test('editor exposes DOI edit permission for a published resource', function (UserRole $role, bool $expected) {
    $user = User::factory()->create(['role' => $role]);
    $resource = Resource::factory()->withDoi('10.5880/editor.permission.published')->create();
    LandingPage::factory()->for($resource)->published()->withDoi((string) $resource->doi)->create();

    withoutVite();

    $this->actingAs($user);

    loadExistingResourceInEditor($this, $resource->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('editor')
            ->where('canEditDoi', $expected)
        );
})->with([
    'admin' => [UserRole::ADMIN, true],
    'group leader' => [UserRole::GROUP_LEADER, false],
    'curator' => [UserRole::CURATOR, false],
    'beginner' => [UserRole::BEGINNER, false],
]);

test('editor exposes draft landing page preview summary for existing resource', function () {
    $user = User::factory()->create();
    $resource = Resource::factory()->create(['doi' => null]);
    $landingPage = LandingPage::factory()
        ->withoutDoi()
        ->draft()
        ->create([
            'resource_id' => $resource->id,
            'slug' => 'editor-draft-resource',
            'template' => 'default_gfz',
        ]);

    withoutVite();

    $this->actingAs($user);

    loadExistingResourceInEditor($this, $resource->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('editor')
            ->where('landingPage.id', $landingPage->id)
            ->where('landingPage.is_published', false)
            ->where('landingPage.status', 'draft')
            ->where('landingPage.public_url', $landingPage->public_url)
            ->where('landingPage.preview_url', $landingPage->preview_url)
        );
});

test('editor exposes published external landing page public url for existing resource', function () {
    $user = User::factory()->create();
    $resource = Resource::factory()->create(['doi' => '10.5880/editor.external']);
    $externalDomain = LandingPageDomain::factory()->withDomain('https://example.org/')->create();
    $landingPage = LandingPage::factory()
        ->published()
        ->external()
        ->create([
            'resource_id' => $resource->id,
            'doi_prefix' => '10.5880/editor.external',
            'slug' => 'editor-external-resource',
            'external_domain_id' => $externalDomain->id,
            'external_path' => 'datasets/editor-resource',
        ]);

    withoutVite();

    $this->actingAs($user);

    loadExistingResourceInEditor($this, $resource->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('editor')
            ->where('landingPage.id', $landingPage->id)
            ->where('landingPage.is_published', true)
            ->where('landingPage.status', 'published')
            ->where('landingPage.public_url', 'https://example.org/datasets/editor-resource')
            ->where('landingPage.external_url', 'https://example.org/datasets/editor-resource')
        );
});

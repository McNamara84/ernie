<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\Resource;
use Illuminate\Support\Facades\Cache;

uses()->group('landing-pages', 'public');

beforeEach(function () {
    $this->resource = Resource::factory()->create();
});

describe('Public Landing Page Access', function () {
    test('can access published landing page', function () {
        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'template' => 'default_gfz',
            ]);

        $response = $this->get("/datasets/{$this->resource->id}");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('LandingPages/default_gfz')
                ->has('resource')
                ->has('landingPage')
                ->where('isPreview', false)
            );
    });

    test('cannot access draft landing page without token', function () {
        LandingPage::factory()
            ->draft()
            ->create([
                'resource_id' => $this->resource->id,
            ]);

        $response = $this->get("/datasets/{$this->resource->id}");

        $response->assertStatus(404);
    });

    test('cannot access depublished landing page', function () {
        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
            ]);

        // Depublish
        $landingPage->unpublish();

        $response = $this->get("/datasets/{$this->resource->id}");

        $response->assertStatus(404);
    });

    test('returns 404 when landing page does not exist', function () {
        $response = $this->get("/datasets/{$this->resource->id}");

        $response->assertStatus(404);
    });
});

describe('Preview Token Access', function () {
    test('can access draft with valid preview token', function () {
        $landingPage = LandingPage::factory()
            ->draft()
            ->create([
                'resource_id' => $this->resource->id,
                'template' => 'default_gfz',
            ]);

        $response = $this->get("/datasets/{$this->resource->id}?preview={$landingPage->preview_token}");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('LandingPages/default_gfz')
                ->where('isPreview', true)
            );
    });

    test('cannot access draft with invalid preview token', function () {
        LandingPage::factory()
            ->draft()
            ->create([
                'resource_id' => $this->resource->id,
            ]);

        $response = $this->get("/datasets/{$this->resource->id}?preview=invalid-token");

        $response->assertStatus(403);
    });

    test('can access published page with preview token', function () {
        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
            ]);

        $response = $this->get("/datasets/{$this->resource->id}?preview={$landingPage->preview_token}");

        $response->assertStatus(200);
    });
});

describe('Landing Page Caching', function () {
    test('caches published landing pages', function () {
        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
            ]);

        // First request - should cache
        $this->get("/datasets/{$this->resource->id}");

        expect(Cache::has("landing_page.{$this->resource->id}"))->toBeTrue();
    });

    test('does not cache draft previews', function () {
        $landingPage = LandingPage::factory()
            ->draft()
            ->create([
                'resource_id' => $this->resource->id,
            ]);

        $this->get("/datasets/{$this->resource->id}?preview={$landingPage->preview_token}");

        expect(Cache::has("landing_page.{$this->resource->id}"))->toBeFalse();
    });

    test('serves cached response for published pages', function () {
        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
            ]);

        // First request - populates cache
        $response1 = $this->get("/datasets/{$this->resource->id}");

        // Modify landing page
        $landingPage->update(['ftp_url' => 'https://new-url.com']);

        // Second request - should serve cached version
        $response2 = $this->get("/datasets/{$this->resource->id}");

        expect($response1->getContent())->toBe($response2->getContent());
    });

    test('cache respects published status check before serving', function () {
        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
            ]);

        // Cache the page
        $this->get("/datasets/{$this->resource->id}");

        // Depublish
        $landingPage->unpublish();

        // Should not serve cached version
        $response = $this->get("/datasets/{$this->resource->id}");
        $response->assertStatus(404);
    });
});

describe('View Counter', function () {
    test('increments view count for published pages', function () {
        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'view_count' => 0,
            ]);

        $this->get("/datasets/{$this->resource->id}");

        expect($landingPage->fresh()->view_count)->toBe(1);
    });

    test('does not increment view count for draft previews', function () {
        $landingPage = LandingPage::factory()
            ->draft()
            ->create([
                'resource_id' => $this->resource->id,
                'view_count' => 0,
            ]);

        $this->get("/datasets/{$this->resource->id}?preview={$landingPage->preview_token}");

        expect($landingPage->fresh()->view_count)->toBe(0);
    });

    test('increments view count only once per cached request', function () {
        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'view_count' => 0,
            ]);

        // First request
        $this->get("/datasets/{$this->resource->id}");
        expect($landingPage->fresh()->view_count)->toBe(1);

        // Second request (cached)
        $this->get("/datasets/{$this->resource->id}");
        expect($landingPage->fresh()->view_count)->toBe(2); // Should still increment
    });
});

describe('Resource Data Loading', function () {
    test('loads all required resource relationships', function () {
        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
            ]);

        $response = $this->get("/datasets/{$this->resource->id}");

        $response->assertInertia(fn ($page) => $page
            ->has('resource')
            ->has('resource.titles')
            ->has('resource.authors')
            ->has('resource.descriptions')
            ->has('resource.keywords')
            ->has('resource.controlled_keywords')
            ->has('resource.funding_references')
            ->has('resource.related_identifiers')
        );
    });
});

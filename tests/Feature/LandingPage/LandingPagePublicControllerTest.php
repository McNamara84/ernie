<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\Resource;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\withoutVite;

uses()->group('landing-pages', 'public');

beforeEach(function () {
    withoutVite();

    $this->resource = Resource::factory()->create([
        'doi' => '10.5880/test.public.001',
    ]);
});

/**
 * Helper to build the semantic URL for a landing page.
 * Uses the model's public_url accessor to avoid duplicating URL generation logic.
 *
 * @param  LandingPage  $landingPage  The landing page model
 * @param  string|null  $preview  Optional preview token to append as query parameter
 * @return string The full URL path
 */
function landingPageUrl(LandingPage $landingPage, ?string $preview = null): string
{
    $url = $landingPage->public_url;

    return $preview ? "{$url}?preview={$preview}" : $url;
}

describe('Public Landing Page Access', function () {
    test('can access published landing page', function () {
        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'test-dataset',
                'template' => 'default_gfz',
            ]);

        $response = $this->get(landingPageUrl($landingPage));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('LandingPages/default_gfz')
                ->has('resource')
                ->has('landingPage')
                ->where('isPreview', false)
            );
    });

    test('cannot access draft landing page without token', function () {
        $landingPage = LandingPage::factory()
            ->draft()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'draft-dataset',
            ]);

        $response = $this->get(landingPageUrl($landingPage));

        $response->assertStatus(404);
    });

    test('cannot access depublished landing page', function () {
        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'depublished-dataset',
            ]);

        // Depublish
        $landingPage->unpublish();

        $response = $this->get(landingPageUrl($landingPage));

        $response->assertStatus(404);
    });

    test('returns 404 when landing page does not exist', function () {
        // Using a non-existent DOI/slug combination
        $response = $this->get('/10.5880/nonexistent/nonexistent-slug');

        $response->assertStatus(404);
    });
});

describe('Preview Token Access', function () {
    test('can access draft with valid preview token', function () {
        $landingPage = LandingPage::factory()
            ->draft()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'draft-preview-test',
                'template' => 'default_gfz',
            ]);

        $response = $this->get(landingPageUrl($landingPage, $landingPage->preview_token));

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('LandingPages/default_gfz')
                ->where('isPreview', true)
            );
    });

    test('cannot access draft with invalid preview token', function () {
        $landingPage = LandingPage::factory()
            ->draft()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'invalid-token-test',
            ]);

        $response = $this->get(landingPageUrl($landingPage, 'invalid-token'));

        $response->assertStatus(403);
    });

    test('can access published page with preview token', function () {
        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'published-preview-test',
            ]);

        $response = $this->get(landingPageUrl($landingPage, $landingPage->preview_token));

        $response->assertStatus(200);
    });
});

describe('Landing Page Caching', function () {
    /**
     * Caching for semantic URLs is not yet implemented.
     *
     * Current state: The controller does NOT cache responses.
     * When implementing caching, the cache key should use the semantic URL
     * components (doi_prefix + slug) instead of resource_id.
     *
     * These tests document the expected behavior and verify that current
     * behavior (no caching) doesn't break anything. They should be updated
     * when caching is implemented.
     *
     * @see https://github.com/McNamara84/ernie/issues/XXX (create tracking issue)
     */

    test('draft previews are not cached (no cache key created)', function () {
        $landingPage = LandingPage::factory()
            ->draft()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'draft-cache-test',
            ]);

        $this->get(landingPageUrl($landingPage, $landingPage->preview_token));

        expect(Cache::has("landing_page.{$this->resource->id}"))->toBeFalse();
    });

    test('published pages return fresh data (caching not yet implemented)', function () {
        // This test verifies current behavior: no caching.
        // When caching is implemented, update this test to verify:
        // - Cache key format: "landing-page.{doi_prefix}.{slug}"
        // - Cache is invalidated when landing page is updated
        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'cached-response-test',
            ]);

        // Expected cache keys if caching were implemented
        $semanticCacheKey = "landing-page.{$landingPage->doi_prefix}.{$landingPage->slug}";
        $resourceIdCacheKey = "landing-page.{$this->resource->id}";

        // First request
        $response1 = $this->get(landingPageUrl($landingPage));
        $response1->assertStatus(200);

        // Verify no cache keys were created (caching not yet implemented)
        expect(Cache::has($semanticCacheKey))->toBeFalse(
            "Expected semantic cache key '{$semanticCacheKey}' to not exist (caching not implemented)"
        );
        expect(Cache::has($resourceIdCacheKey))->toBeFalse(
            "Expected resource ID cache key '{$resourceIdCacheKey}' to not exist (caching not implemented)"
        );

        // Modify landing page
        $landingPage->update(['ftp_url' => 'https://new-url.com']);

        // Second request - should reflect the change since caching is not yet implemented
        $response2 = $this->get(landingPageUrl($landingPage));
        $response2->assertStatus(200);

        // Still no caching after second request
        expect(Cache::has($semanticCacheKey))->toBeFalse();
        expect(Cache::has($resourceIdCacheKey))->toBeFalse();
    });

    test('cache respects published status check before serving', function () {
        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'depublish-cache-test',
            ]);

        // Access the page while published
        $this->get(landingPageUrl($landingPage));

        // Depublish
        $landingPage->unpublish();

        // Should not serve cached version - unpublished pages require preview token
        $response = $this->get(landingPageUrl($landingPage));
        $response->assertStatus(404);
    });
});

describe('View Counter', function () {
    test('increments view count for published pages', function () {
        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'view-count-test',
                'view_count' => 0,
            ]);

        $this->get(landingPageUrl($landingPage));

        expect($landingPage->fresh()->view_count)->toBe(1);
    });

    test('does not increment view count for draft previews', function () {
        $landingPage = LandingPage::factory()
            ->draft()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'draft-view-count-test',
                'view_count' => 0,
            ]);

        $this->get(landingPageUrl($landingPage, $landingPage->preview_token));

        expect($landingPage->fresh()->view_count)->toBe(0);
    });

    test('increments view count only once per cached request', function () {
        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'multiple-view-test',
                'view_count' => 0,
            ]);

        // First request
        $this->get(landingPageUrl($landingPage));
        expect($landingPage->fresh()->view_count)->toBe(1);

        // Second request
        $this->get(landingPageUrl($landingPage));
        expect($landingPage->fresh()->view_count)->toBe(2);
    });
});

describe('Resource Data Loading', function () {
    test('loads all required resource relationships', function () {
        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'resource-loading-test',
            ]);

        $response = $this->get(landingPageUrl($landingPage));

        $response->assertInertia(fn ($page) => $page
            ->has('resource')
            ->has('resource.titles')
            ->has('resource.descriptions')
            ->has('resource.funding_references')
            ->has('resource.related_identifiers')
        );
    });
});

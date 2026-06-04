<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Http\Controllers\LandingPagePublicController;
use App\Models\LandingPage;
use App\Models\LandingPageDailyStatistic;
use App\Models\LandingPageDomain;
use App\Models\LandingPageTemplate;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

covers(LandingPagePublicController::class);

uses()->group('landing-pages', 'public');

beforeEach(function () {
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

function landingPageDailyViewCount(LandingPage $landingPage): ?int
{
    return LandingPageDailyStatistic::query()
        ->where('landing_page_id', $landingPage->id)
        ->whereDate('statistic_date', now()->toDateString())
        ->value('page_view_count');
}

function landingPageDailyDownloadCount(LandingPage $landingPage): ?int
{
    return LandingPageDailyStatistic::query()
        ->where('landing_page_id', $landingPage->id)
        ->whereDate('statistic_date', now()->toDateString())
        ->value('file_download_click_count');
}

function invokeLandingPagePublicControllerHelper(string $method, mixed ...$arguments): mixed
{
    $controller = new LandingPagePublicController;
    $reflection = new ReflectionMethod($controller, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($controller, $arguments);
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

    test('cannot access draft landing page without preview token', function () {
        // Draft landing pages are not publicly accessible without a valid preview token
        // Note: Published landing pages cannot be unpublished because DOIs are persistent
        $landingPage = LandingPage::factory()
            ->draft()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'draft-only-dataset',
            ]);

        $response = $this->get(landingPageUrl($landingPage));

        $response->assertStatus(404);
    });

    test('returns 404 when landing page does not exist', function () {
        // Using a non-existent DOI/slug combination
        $response = $this->get('/10.5880/nonexistent/nonexistent-slug');

        $response->assertStatus(404);
    });

    test('strongly throttles known ai bots on public landing pages', function () {
        config([
            'bot_protection.enabled' => true,
            'bot_protection.ai_user_agents' => ['GPTBot'],
            'bot_protection.limits.ai_bot_public_per_minute' => 1,
            'bot_protection.limits.public_landing_per_minute' => 10,
        ]);

        RateLimiter::clear('landing-page:ai-bot:203.0.113.20');

        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'throttled-ai-bot-test',
                'template' => 'default_gfz',
            ]);

        $this->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.20',
            'HTTP_USER_AGENT' => 'GPTBot',
        ])->get(landingPageUrl($landingPage))->assertOk();

        $this->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.20',
            'HTTP_USER_AGENT' => 'GPTBot',
        ])->get(landingPageUrl($landingPage))->assertTooManyRequests();
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

    test('published pages return fresh data when bot protection cache is disabled', function () {
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

        // Verify no legacy cache keys were created while the new cache is disabled
        expect(Cache::has($semanticCacheKey))->toBeFalse(
            "Expected legacy semantic cache key '{$semanticCacheKey}' to not exist"
        );
        expect(Cache::has($resourceIdCacheKey))->toBeFalse(
            "Expected legacy resource ID cache key '{$resourceIdCacheKey}' to not exist"
        );

        // Modify landing page
        $landingPage->update(['ftp_url' => 'https://new-url.com']);

        // Second request - should reflect the change because caching is disabled
        $response2 = $this->get(landingPageUrl($landingPage));
        $response2->assertStatus(200);

        // Still no legacy caching after the second request
        expect(Cache::has($semanticCacheKey))->toBeFalse();
        expect(Cache::has($resourceIdCacheKey))->toBeFalse();
    });

    test('cache respects template changes', function () {
        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'template-change-cache-test',
                'template' => 'default_gfz',
            ]);

        // Access the page
        $this->get(landingPageUrl($landingPage));

        // Change template (this should invalidate the cache)
        $landingPage->update(['template' => 'minimal']);

        // Access again - should show the new template (not cached old version)
        $response = $this->get(landingPageUrl($landingPage));
        $response->assertStatus(200);

        // Note: Published landing pages cannot be unpublished because DOIs are persistent
        // and must always resolve to a valid landing page
    });

    test('published landing page render data is cached and invalidated after landing page updates', function () {
        config([
            'bot_protection.enabled' => true,
            'bot_protection.landing_cache_ttl' => 600,
        ]);

        Cache::flush();

        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'render-cache-test',
                'template' => 'default_gfz',
                'ftp_url' => 'https://data.gfz.de/old.zip',
            ]);

        $cacheKey = CacheKey::LANDING_PAGE_RENDER_DATA->key($landingPage->id);

        $this->get(landingPageUrl($landingPage))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('landingPage.ftp_url', 'https://data.gfz.de/old.zip'));

        expect(Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->has($cacheKey))->toBeTrue();

        $landingPage->update(['ftp_url' => 'https://data.gfz.de/new.zip']);

        expect(Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->has($cacheKey))->toBeFalse();

        $landingPage->refresh();

        $this->get(landingPageUrl($landingPage))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('landingPage.ftp_url', 'https://data.gfz.de/new.zip'));
    });

    test('preview token requests are not stored in the public render cache', function () {
        config([
            'bot_protection.enabled' => true,
            'bot_protection.landing_cache_ttl' => 600,
        ]);

        Cache::flush();

        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'preview-cache-test',
            ]);

        $this->get(landingPageUrl($landingPage, $landingPage->preview_token))->assertOk();

        expect(Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->has(
            CacheKey::LANDING_PAGE_RENDER_DATA->key($landingPage->id),
        ))->toBeFalse();
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
        expect(landingPageDailyViewCount($landingPage))->toBe(1);
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
        expect(landingPageDailyViewCount($landingPage))->toBeNull();
    });

    test('increments view count for repeated requests when bot protection is disabled', function () {
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
        expect(landingPageDailyViewCount($landingPage))->toBe(1);

        // Second request
        $this->get(landingPageUrl($landingPage));
        expect($landingPage->fresh()->view_count)->toBe(2);
        expect(landingPageDailyViewCount($landingPage))->toBe(2);
    });

    test('debounces view count per visitor when bot protection is enabled', function () {
        config([
            'bot_protection.enabled' => true,
            'bot_protection.ai_user_agents' => ['GPTBot'],
            'bot_protection.view_count_debounce_seconds' => 3600,
        ]);

        Cache::flush();

        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'debounced-view-test',
                'view_count' => 0,
            ]);

        $server = [
            'REMOTE_ADDR' => '203.0.113.30',
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
        ];

        $this->withServerVariables($server)->get(landingPageUrl($landingPage))->assertOk();
        expect($landingPage->fresh()->view_count)->toBe(1);
        expect(landingPageDailyViewCount($landingPage))->toBe(1);

        $this->withServerVariables($server)->get(landingPageUrl($landingPage))->assertOk();
        expect($landingPage->fresh()->view_count)->toBe(1);
        expect(landingPageDailyViewCount($landingPage))->toBe(1);

        $this->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.31',
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
        ])->get(landingPageUrl($landingPage))->assertOk();
        expect($landingPage->fresh()->view_count)->toBe(2);
        expect(landingPageDailyViewCount($landingPage))->toBe(2);
    });

    test('does not count known ai bot landing page views when bot protection is enabled', function () {
        config([
            'bot_protection.enabled' => true,
            'bot_protection.ai_user_agents' => ['GPTBot'],
            'bot_protection.limits.ai_bot_public_per_minute' => 10,
        ]);

        Cache::flush();

        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'ai-bot-view-test',
                'view_count' => 0,
            ]);

        $this->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.40',
            'HTTP_USER_AGENT' => 'GPTBot',
        ])->get(landingPageUrl($landingPage))->assertOk();

        expect($landingPage->fresh()->view_count)->toBe(0);
        expect(landingPageDailyViewCount($landingPage))->toBeNull();
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

describe('Tracked Download URLs', function () {
    test('published landing pages expose tracked download URLs in the public payload', function () {
        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'tracked-downloads-test',
                'ftp_url' => 'https://downloads.example.org/dataset.zip',
            ]);

        $file = $landingPage->files()->create([
            'url' => 'https://downloads.example.org/supplement.csv',
            'position' => 0,
        ]);

        $this->get(landingPageUrl($landingPage))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('landingPage.ftp_url', 'https://downloads.example.org/dataset.zip')
                ->where('landingPage.tracked_ftp_url', route('landing-page.download.primary', ['landingPage' => $landingPage->id]))
                ->where('landingPage.files.0.url', 'https://downloads.example.org/supplement.csv')
                ->where('landingPage.files.0.tracked_url', route('landing-page.download.file', ['landingPage' => $landingPage->id, 'landingPageFile' => $file->id]))
            );
    });

    test('preview payloads do not expose tracked download URLs', function () {
        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'tracked-download-preview-test',
                'ftp_url' => 'https://downloads.example.org/dataset.zip',
            ]);

        $landingPage->files()->create([
            'url' => 'https://downloads.example.org/supplement.csv',
            'position' => 0,
        ]);

        $this->get(landingPageUrl($landingPage, $landingPage->preview_token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->missing('landingPage.tracked_ftp_url')
                ->missing('landingPage.files.0.tracked_url')
            );
    });

    test('tracked download helper clears malformed file payloads and omits blank primary download urls', function () {
        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'tracked-download-helper-test',
            ]);

        $result = invokeLandingPagePublicControllerHelper('attachTrackedDownloadUrls', [
            'ftp_url' => '   ',
            'files' => 'invalid-payload',
        ], $landingPage);

        expect($result['tracked_ftp_url'])->toBeNull()
            ->and($result['files'])->toBe([]);
    });

    test('tracked download helper leaves malformed file entries untouched', function () {
        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'tracked-download-helper-invalid-file-test',
                'ftp_url' => 'https://downloads.example.org/dataset.zip',
            ]);

        $result = invokeLandingPagePublicControllerHelper('attachTrackedDownloadUrls', [
            'ftp_url' => 'https://downloads.example.org/dataset.zip',
            'files' => [
                'plain-string',
                ['id' => 'not-numeric', 'url' => 'https://downloads.example.org/ignored.zip'],
                ['id' => 42, 'url' => 'https://downloads.example.org/tracked.zip'],
            ],
        ], $landingPage);

        expect($result['files'][0])->toBe('plain-string')
            ->and($result['files'][1])->toBe([
                'id' => 'not-numeric',
                'url' => 'https://downloads.example.org/ignored.zip',
            ])
            ->and($result['files'][2]['tracked_url'])->toBe(route('landing-page.download.file', [
                'landingPage' => $landingPage->id,
                'landingPageFile' => 42,
            ]));
    });
});

describe('External Landing Page Redirect', function () {
    test('published external landing page returns 301 redirect', function () {
        $domain = LandingPageDomain::factory()->withDomain('https://geofon.gfz.de/')->create();

        $landingPage = LandingPage::factory()
            ->published()
            ->external()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'external-redirect-test',
                'external_domain_id' => $domain->id,
                'external_path' => 'doi/network/GE1',
            ]);

        // Use getPublicPath() since public_url returns the external URL for external pages
        $response = $this->get($landingPage->getPublicPath());

        $response->assertRedirect('https://geofon.gfz.de/doi/network/GE1');
        $response->assertStatus(301);
    });

    test('external redirect increments view count for published pages', function () {
        $domain = LandingPageDomain::factory()->create();

        $landingPage = LandingPage::factory()
            ->published()
            ->external()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'external-views-test',
                'external_domain_id' => $domain->id,
                'external_path' => 'test/path',
                'view_count' => 0,
            ]);

        $this->get($landingPage->getPublicPath());

        expect($landingPage->fresh()->view_count)->toBe(1);
        expect(landingPageDailyViewCount($landingPage))->toBe(1);
    });

    test('external draft is not accessible without preview token', function () {
        $domain = LandingPageDomain::factory()->create();

        $landingPage = LandingPage::factory()
            ->draft()
            ->external()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'external-draft-test',
                'external_domain_id' => $domain->id,
                'external_path' => 'test/path',
            ]);

        $response = $this->get($landingPage->getPublicPath());

        $response->assertStatus(404);
    });

    test('external draft redirects with valid preview token', function () {
        $domain = LandingPageDomain::factory()->withDomain('https://data.gfz.de/')->create();

        $landingPage = LandingPage::factory()
            ->draft()
            ->external()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'external-preview-test',
                'external_domain_id' => $domain->id,
                'external_path' => 'dataset/preview',
            ]);

        $response = $this->get($landingPage->getPublicPath().'?preview='.$landingPage->preview_token);

        $response->assertRedirect('https://data.gfz.de/dataset/preview');
        // Draft previews use temporary redirect (302) to avoid browser caching
        $response->assertStatus(302);
    });

    test('published external page with preview token uses temporary redirect', function () {
        $domain = LandingPageDomain::factory()->withDomain('https://data.gfz.de/')->create();

        $landingPage = LandingPage::factory()
            ->published()
            ->external()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'external-published-preview-test',
                'external_domain_id' => $domain->id,
                'external_path' => 'dataset/published',
            ]);

        // Even for published pages, preview token access should use 302
        $response = $this->get($landingPage->getPublicPath().'?preview='.$landingPage->preview_token);

        $response->assertRedirect('https://data.gfz.de/dataset/published');
        $response->assertStatus(302);
    });

    test('external draft does not increment view count with preview token', function () {
        $domain = LandingPageDomain::factory()->create();

        $landingPage = LandingPage::factory()
            ->draft()
            ->external()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'external-no-views-test',
                'external_domain_id' => $domain->id,
                'external_path' => 'test/path',
                'view_count' => 0,
            ]);

        $this->get($landingPage->getPublicPath().'?preview='.$landingPage->preview_token);

        expect($landingPage->fresh()->view_count)->toBe(0);
    });

    test('external landing page strips leading slash from path', function () {
        $domain = LandingPageDomain::factory()->withDomain('https://example.org/')->create();

        $landingPage = LandingPage::factory()
            ->published()
            ->external()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'external-slash-test',
                'external_domain_id' => $domain->id,
                'external_path' => '/leading/slash/path',
            ]);

        $response = $this->get($landingPage->getPublicPath());

        // Should strip the leading slash to avoid double-slash
        $response->assertRedirect('https://example.org/leading/slash/path');
    });
});

describe('Landing Page with Custom Template', function () {
    test('renders landing page with custom template section order and logo', function () {
        $template = LandingPageTemplate::factory()->create([
            'created_by' => User::factory()->admin()->create()->id,
            'right_column_order' => ['location', 'abstract', 'methods', 'technical_info', 'series_information', 'table_of_contents', 'other', 'creators', 'contributors', 'funders', 'keywords', 'metadata_download'],
            'left_column_order' => ['contact', 'files', 'model_description', 'related_work'],
            'logo_path' => 'landing-page-logos/test/custom-logo.png',
            'creator_display_limit' => 12,
            'contributor_display_limit' => 34,
        ]);

        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'template-test',
                'template' => 'default_gfz',
                'landing_page_template_id' => $template->id,
            ]);

        $response = $this->get(landingPageUrl($landingPage));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('LandingPages/default_gfz')
                ->has('sectionOrder', fn ($order) => $order
                    ->has('rightColumn')
                    ->has('leftColumn')
                )
                ->where('displayLimits.creators', 12)
                ->where('displayLimits.contributors', 34)
                ->where('customLogoUrl', fn ($url) => str_contains($url, 'landing-page-logos/test/custom-logo.png'))
            );
    });

    test('renders landing page without custom template (default)', function () {
        LandingPageTemplate::ensureDefaultTemplateExists()->update([
            'creator_display_limit' => 22,
            'contributor_display_limit' => 44,
        ]);

        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.001',
                'slug' => 'no-template-test',
                'template' => 'default_gfz',
                'landing_page_template_id' => null,
            ]);

        $response = $this->get(landingPageUrl($landingPage));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('LandingPages/default_gfz')
                ->where('sectionOrder', null)
                ->where('customLogoUrl', null)
                ->where('displayLimits.creators', 22)
                ->where('displayLimits.contributors', 44)
            );
    });

    test('normalizes legacy Physical Object landing pages to the igsn renderer and keeps matching igsn custom templates', function () {
        $physicalObjectType = ResourceType::firstOrCreate(
            ['slug' => 'physical-object'],
            ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true]
        );

        $resource = Resource::factory()->create([
            'doi' => '10.5880/test.public.igsn.001',
            'resource_type_id' => $physicalObjectType->id,
        ]);

        $template = LandingPageTemplate::factory()->igsn()->create([
            'created_by' => User::factory()->admin()->create()->id,
            'right_column_order' => ['location', 'abstract', 'methods', 'technical_info', 'series_information', 'table_of_contents', 'other', 'creators', 'contributors', 'funders', 'keywords', 'metadata_download'],
            'left_column_order' => ['contact', 'general', 'acquisition', 'model_description', 'related_work'],
            'logo_path' => 'landing-page-logos/test/igsn-logo.png',
            'creator_display_limit' => 21,
            'contributor_display_limit' => 31,
        ]);
        $domain = LandingPageDomain::factory()->withDomain('https://legacy.example.org/')->create();

        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/test.public.igsn.001',
                'slug' => 'legacy-igsn-template-test',
                'template' => 'default_gfz',
                'landing_page_template_id' => $template->id,
                'ftp_url' => 'https://datapub.gfz-potsdam.de/download/legacy.zip',
                'external_domain_id' => $domain->id,
                'external_path' => 'stale/external/path',
            ]);
        $landingPage->links()->create([
            'url' => 'https://example.org/file.zip',
            'label' => 'Legacy link',
            'position' => 0,
        ]);

        $response = $this->get(landingPageUrl($landingPage));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('LandingPages/default_gfz_igsn')
                ->where('landingPage.template', 'default_gfz_igsn')
                ->where('landingPage.ftp_url', null)
                ->where('landingPage.links', [])
                ->where('landingPage.external_domain_id', null)
                ->where('landingPage.external_path', null)
                ->where('landingPage.external_domain', null)
                ->where('landingPage.external_url', null)
                ->where('landingPage.landing_page_template_id', $template->id)
                ->has('sectionOrder', fn ($order) => $order
                    ->has('rightColumn')
                    ->has('leftColumn')
                )
                ->where('displayLimits.creators', 21)
                ->where('displayLimits.contributors', 31)
                ->where('customLogoUrl', fn ($url) => str_contains($url, 'landing-page-logos/test/igsn-logo.png'))
            );
    });

    test('normalizes legacy igsn custom template left-column order before rendering', function () {
        $physicalObjectType = ResourceType::firstOrCreate(
            ['slug' => 'physical-object'],
            ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true]
        );

        $resource = Resource::factory()->create([
            'doi' => '10.5880/test.public.igsn.001a',
            'resource_type_id' => $physicalObjectType->id,
        ]);

        $template = LandingPageTemplate::factory()->igsn()->create([
            'created_by' => User::factory()->admin()->create()->id,
            'left_column_order' => ['contact', 'files', 'model_description', 'related_work'],
        ]);

        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/test.public.igsn.001a',
                'slug' => 'legacy-igsn-left-order-test',
                'template' => 'default_gfz_igsn',
                'landing_page_template_id' => $template->id,
            ]);

        $response = $this->get(landingPageUrl($landingPage));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('LandingPages/default_gfz_igsn')
                ->where('landingPage.landing_page_template_id', $template->id)
                ->where('sectionOrder.leftColumn', ['contact', 'model_description', 'related_work', 'general', 'acquisition'])
            );
    });

    test('normalizes legacy Physical Object landing pages and clears mismatched resource custom templates', function () {
        $physicalObjectType = ResourceType::firstOrCreate(
            ['slug' => 'physical-object'],
            ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true]
        );

        $resource = Resource::factory()->create([
            'doi' => '10.5880/test.public.igsn.002',
            'resource_type_id' => $physicalObjectType->id,
        ]);

        $template = LandingPageTemplate::factory()->create([
            'created_by' => User::factory()->admin()->create()->id,
            'right_column_order' => ['location', 'abstract', 'methods', 'technical_info', 'series_information', 'table_of_contents', 'other', 'creators', 'contributors', 'funders', 'keywords', 'metadata_download'],
            'left_column_order' => ['contact', 'files', 'model_description', 'related_work'],
            'logo_path' => 'landing-page-logos/test/resource-logo.png',
        ]);

        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/test.public.igsn.002',
                'slug' => 'legacy-igsn-mismatch-test',
                'template' => 'default_gfz',
                'landing_page_template_id' => $template->id,
            ]);

        $response = $this->get(landingPageUrl($landingPage));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('LandingPages/default_gfz_igsn')
                ->where('landingPage.template', 'default_gfz_igsn')
                ->where('landingPage.landing_page_template_id', null)
                ->where('sectionOrder', null)
                ->where('customLogoUrl', null)
            );
    });

    test('ignores built-in default template ids in the public payload', function () {
        $defaultTemplate = LandingPageTemplate::ensureDefaultTemplateExists();

        $landingPage = LandingPage::factory()
            ->published()
            ->create([
                'resource_id' => $this->resource->id,
                'doi_prefix' => '10.5880/test.public.003',
                'slug' => 'default-template-id-test',
                'template' => 'default_gfz',
                'landing_page_template_id' => $defaultTemplate->id,
            ]);

        $response = $this->get(landingPageUrl($landingPage));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('LandingPages/default_gfz')
                ->where('landingPage.landing_page_template_id', null)
                ->where('sectionOrder', null)
                ->where('customLogoUrl', null)
            );
    });
});

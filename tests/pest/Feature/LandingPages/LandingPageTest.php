<?php

use App\Enums\UserRole;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\withoutVite;

uses()->group('landing-pages');

beforeEach(function () {
    withoutVite();

    // Use a Curator role to satisfy LandingPagePolicy authorization
    // Only ADMIN, GROUP_LEADER, and CURATOR can manage landing pages
    $this->user = User::factory()->create(['role' => UserRole::CURATOR]);
    $this->resource = Resource::factory()->create();
});

describe('Landing Page Creation', function () {
    test('authenticated user can create a landing page for a resource', function () {
        $response = $this->actingAs($this->user)
            ->postJson("/resources/{$this->resource->id}/landing-page", [
                'template' => 'default_gfz',
                'ftp_url' => 'https://datapub.gfz-potsdam.de/download/test',
                'is_published' => false,
            ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'message',
                'landing_page' => ['id', 'preview_token', 'preview_url'],
            ]);

        expect($this->resource->fresh()->landingPage)->not->toBeNull();
        expect($this->resource->landingPage->template)->toBe('default_gfz');
        expect($this->resource->landingPage->is_published)->toBeFalse();
    });

    test('preview token is automatically generated on creation', function () {
        $this->actingAs($this->user)
            ->postJson("/resources/{$this->resource->id}/landing-page", [
                'template' => 'default_gfz',
                'is_published' => false,
            ]);

        $landingPage = $this->resource->fresh()->landingPage;

        expect($landingPage->preview_token)->not->toBeNull();
        expect($landingPage->preview_token)->toHaveLength(64);
    });

    test('published_at is set when status is published', function () {
        $this->actingAs($this->user)
            ->postJson("/resources/{$this->resource->id}/landing-page", [
                'template' => 'default_gfz',
                'is_published' => true,
            ]);

        $landingPage = $this->resource->fresh()->landingPage;

        expect($landingPage->published_at)->not->toBeNull();
        expect($landingPage->isPublished())->toBeTrue();
    });

    test('cannot create duplicate landing page for same resource', function () {
        LandingPage::factory()->create(['resource_id' => $this->resource->id]);

        $response = $this->actingAs($this->user)
            ->postJson("/resources/{$this->resource->id}/landing-page", [
                'template' => 'default_gfz',
                'is_published' => false,
            ]);

        $response->assertStatus(409)
            ->assertJson([
                'message' => 'Landing page already exists for this resource',
            ]);
    });

    test('unauthenticated user cannot create landing page', function () {
        $response = $this->postJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'default_gfz',
            'is_published' => false,
        ]);

        $response->assertUnauthorized();
    });

    test('validates required fields', function () {
        $response = $this->actingAs($this->user)
            ->postJson("/resources/{$this->resource->id}/landing-page", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['template']);
    });

    test('validates template must be valid', function () {
        $response = $this->actingAs($this->user)
            ->postJson("/resources/{$this->resource->id}/landing-page", [
                'template' => 'invalid_template',
                'is_published' => false,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['template']);
    });

    test('validates ftp_url must be valid URL', function () {
        $response = $this->actingAs($this->user)
            ->postJson("/resources/{$this->resource->id}/landing-page", [
                'template' => 'default_gfz',
                'ftp_url' => 'not-a-valid-url',
                'is_published' => false,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['ftp_url']);
    });

    test('is_published defaults to draft when not specified', function () {
        // When is_published is not provided in the request, the landing page
        // should default to unpublished (draft) status for safety.
        // This prevents accidental publication of incomplete landing pages.
        $response = $this->actingAs($this->user)
            ->postJson("/resources/{$this->resource->id}/landing-page", [
                'template' => 'default_gfz',
                // intentionally omitting is_published
            ]);

        $response->assertCreated();

        $landingPage = $this->resource->fresh()->landingPage;

        expect($landingPage->is_published)->toBeFalse();
        expect($landingPage->published_at)->toBeNull();
    });
});

describe('Landing Page Updates', function () {
    test('authenticated user can update landing page', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
            'template' => 'default_gfz',
            'is_published' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/resources/{$this->resource->id}/landing-page", [
                'ftp_url' => 'https://datapub.gfz-potsdam.de/download/updated',
                'is_published' => true,
            ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'Landing page updated successfully',
            ]);

        $landingPage->refresh();
        expect($landingPage->ftp_url)->toBe('https://datapub.gfz-potsdam.de/download/updated');
        expect($landingPage->isPublished())->toBeTrue();
        expect($landingPage->published_at)->not->toBeNull();
    });

    test('updating to published sets published_at timestamp', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
            'is_published' => false,
            'published_at' => null,
        ]);

        $this->actingAs($this->user)
            ->putJson("/resources/{$this->resource->id}/landing-page", [
                'is_published' => true,
            ]);

        $landingPage->refresh();
        expect($landingPage->published_at)->not->toBeNull();
    });

    test('cannot unpublish a published landing page because DOIs are persistent', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
            'is_published' => true,
        ]);

        $landingPage->publish(); // Set published_at

        $response = $this->actingAs($this->user)
            ->putJson("/resources/{$this->resource->id}/landing-page", [
                'is_published' => false,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Cannot unpublish a published landing page. DOIs are persistent and must always resolve to a valid landing page.',
                'error' => 'cannot_unpublish',
            ]);

        $landingPage->refresh();
        expect($landingPage->is_published)->toBeTrue();
        expect($landingPage->published_at)->not->toBeNull();
    });

    test('returns 404 when landing page does not exist', function () {
        $response = $this->actingAs($this->user)
            ->putJson("/resources/{$this->resource->id}/landing-page", [
                'is_published' => true,
            ]);

        $response->assertNotFound()
            ->assertJson([
                'message' => 'Landing page not found',
            ]);
    });

    test('invalidates cache on update', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
            'is_published' => true,
        ]);

        // Set cache
        Cache::put("landing-page.{$this->resource->id}", 'cached-data', 3600);
        expect(Cache::has("landing-page.{$this->resource->id}"))->toBeTrue();

        // Update
        $this->actingAs($this->user)
            ->putJson("/resources/{$this->resource->id}/landing-page", [
                'ftp_url' => 'https://example.com/new-url',
            ]);

        // Cache should be cleared
        expect(Cache::has("landing-page.{$this->resource->id}"))->toBeFalse();
    });
});

describe('Landing Page Deletion', function () {
    test('authenticated user can delete draft landing page', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
            'is_published' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/resources/{$this->resource->id}/landing-page");

        $response->assertOk()
            ->assertJson([
                'message' => 'Landing page deleted successfully',
            ]);

        expect(LandingPage::find($landingPage->id))->toBeNull();
        expect($this->resource->fresh()->landingPage)->toBeNull();
    });

    test('cannot delete published landing page because DOIs are persistent', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
            'is_published' => true,
            'published_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/resources/{$this->resource->id}/landing-page");

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Cannot delete a published landing page. DOIs are persistent and must always resolve to a valid landing page.',
                'error' => 'cannot_delete_published',
            ]);

        expect(LandingPage::find($landingPage->id))->not->toBeNull();
    });

    test('returns 404 when trying to delete non-existent landing page', function () {
        $response = $this->actingAs($this->user)
            ->deleteJson("/resources/{$this->resource->id}/landing-page");

        $response->assertNotFound()
            ->assertJson([
                'message' => 'Landing page not found',
            ]);
    });

    test('invalidates cache on deletion', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
            'is_published' => false, // Only draft landing pages can be deleted
        ]);

        // Set cache
        Cache::put("landing-page.{$this->resource->id}", 'cached-data', 3600);

        $this->actingAs($this->user)
            ->deleteJson("/resources/{$this->resource->id}/landing-page");

        expect(Cache::has("landing-page.{$this->resource->id}"))->toBeFalse();
    });
});

describe('Landing Page Retrieval', function () {
    test('authenticated user can get landing page configuration', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
            'template' => 'default_gfz',
            'ftp_url' => 'https://example.com/data',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/resources/{$this->resource->id}/landing-page");

        $response->assertOk()
            ->assertJson([
                'landing_page' => [
                    'id' => $landingPage->id,
                    'template' => 'default_gfz',
                    'ftp_url' => 'https://example.com/data',
                ],
            ]);
    });

    test('returns 404 when landing page does not exist', function () {
        $response = $this->actingAs($this->user)
            ->getJson("/resources/{$this->resource->id}/landing-page");

        $response->assertNotFound();
    });
});

describe('Public Landing Page Display', function () {
    test('published landing page is publicly accessible', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
            'is_published' => true,
            'template' => 'default_gfz',
        ]);
        $landingPage->publish();

        // Use the semantic URL from the model accessor
        $response = $this->get($landingPage->public_url);

        $response->assertOk();
        expect($response->viewData('page')['component'])->toBe('LandingPages/default_gfz');
    });

    test('draft landing page requires preview token', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
            'is_published' => false,
        ]);

        // Without token: 404 (use public_url which is the semantic URL)
        $response = $this->get($landingPage->public_url);
        $response->assertNotFound();

        // With valid token: OK
        $response = $this->get("{$landingPage->public_url}?preview={$landingPage->preview_token}");
        $response->assertOk();
    });

    test('draft landing page with invalid token returns 403', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
            'is_published' => false,
        ]);

        $response = $this->get("{$landingPage->public_url}?preview=invalid-token");
        $response->assertForbidden();
    });

    test('increments view count on each visit for published pages', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
            'is_published' => true,
            'view_count' => 5,
        ]);

        $this->get($landingPage->public_url);

        expect($landingPage->fresh()->view_count)->toBe(6);
    });

    test('does not increment view count in preview mode', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
            'is_published' => false,
            'view_count' => 5,
        ]);

        $this->get("{$landingPage->public_url}?preview={$landingPage->preview_token}");

        expect($landingPage->fresh()->view_count)->toBe(5);
    });

    test('updates last_viewed_at timestamp on visit', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
            'is_published' => true,
            'last_viewed_at' => null,
        ]);

        $this->get($landingPage->public_url);

        expect($landingPage->fresh()->last_viewed_at)->not->toBeNull();
    });
});

describe('Preview Token Generation', function () {
    test('generates unique preview tokens', function () {
        $lp1 = LandingPage::factory()->create();
        $lp2 = LandingPage::factory()->create();

        expect($lp1->preview_token)->not->toEqual($lp2->preview_token);
    });

    test('preview token is 64 characters long', function () {
        $landingPage = LandingPage::factory()->create();

        expect($landingPage->preview_token)->toHaveLength(64);
    });

    test('can regenerate preview token', function () {
        $landingPage = LandingPage::factory()->create();
        $originalToken = $landingPage->preview_token;

        $newToken = $landingPage->generatePreviewToken();

        expect($newToken)->not->toEqual($originalToken);
        expect($newToken)->toHaveLength(64);
        expect($landingPage->fresh()->preview_token)->toBe($newToken);
    });
});

describe('Landing Page Model', function () {
    test('has correct public_url attribute for resource with DOI', function () {
        // Use published() and withDoi() factory states to ensure DOI prefix is set
        $landingPage = LandingPage::factory()->published()->withDoi('10.5880/test.example.001')->create([
            'resource_id' => $this->resource->id,
        ]);

        // Should be semantic URL: /{doi_prefix}/{slug}
        expect($landingPage->doi_prefix)->not->toBeNull();
        expect($landingPage->doi_prefix)->toBe('10.5880/test.example.001');
        expect($landingPage->public_url)->toContain("/{$landingPage->doi_prefix}/");
        expect($landingPage->public_url)->toContain($landingPage->slug);
    });

    test('has correct public_url attribute for draft resource without DOI', function () {
        // Use draft() and withoutDoi() factory states to create a draft without DOI
        $landingPage = LandingPage::factory()->draft()->withoutDoi()->create([
            'resource_id' => $this->resource->id,
        ]);

        // Should be draft URL: /draft-{id}/{slug}
        expect($landingPage->doi_prefix)->toBeNull();
        expect($landingPage->public_url)->toContain("/draft-{$this->resource->id}/");
        expect($landingPage->public_url)->toContain($landingPage->slug);
    });

    test('has correct preview_url attribute with token', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
        ]);

        expect($landingPage->preview_url)->toContain($landingPage->slug);
        expect($landingPage->preview_url)->toContain("?preview={$landingPage->preview_token}");
    });

    test('contact_url is computed from public_url', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
        ]);

        expect($landingPage->contact_url)->toBe($landingPage->public_url.'/contact');
    });

    test('isPublished returns correct status', function () {
        $published = LandingPage::factory()->create(['is_published' => true]);
        $draft = LandingPage::factory()->create(['is_published' => false]);

        expect($published->isPublished())->toBeTrue();
        expect($draft->isPublished())->toBeFalse();
    });

    test('isDraft returns correct status', function () {
        $published = LandingPage::factory()->create(['is_published' => true]);
        $draft = LandingPage::factory()->create(['is_published' => false]);

        expect($published->isDraft())->toBeFalse();
        expect($draft->isDraft())->toBeTrue();
    });

    test('publish method updates status and published_at', function () {
        $landingPage = LandingPage::factory()->create([
            'is_published' => false,
            'published_at' => null,
        ]);

        $landingPage->publish();

        expect($landingPage->fresh()->is_published)->toBeTrue();
        expect($landingPage->fresh()->published_at)->not->toBeNull();
    });

    test('unpublish method updates status to draft', function () {
        $landingPage = LandingPage::factory()->create(['is_published' => true]);

        $landingPage->unpublish();

        expect($landingPage->fresh()->is_published)->toBeFalse();
    });

    test('belongs to resource', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
        ]);

        expect($landingPage->resource->id)->toBe($this->resource->id);
    });

    test('resource has one landing page', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
        ]);

        expect($this->resource->landingPage->id)->toBe($landingPage->id);
    });

    test('landing page is deleted when resource is deleted', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
        ]);

        $landingPageId = $landingPage->id;
        $this->resource->delete();

        expect(LandingPage::find($landingPageId))->toBeNull();
    });
});

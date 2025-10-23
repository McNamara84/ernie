<?php

use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

uses()->group('landing-pages');

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->resource = Resource::factory()->create();
});

describe('Landing Page Creation', function () {
    test('authenticated user can create a landing page for a resource', function () {
        $response = $this->actingAs($this->user)
            ->postJson("/resources/{$this->resource->id}/landing-page", [
                'template' => 'default_gfz',
                'ftp_url' => 'https://datapub.gfz-potsdam.de/download/test',
                'status' => 'draft',
            ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'message',
                'landing_page' => ['id', 'preview_token', 'preview_url'],
                'preview_url',
            ]);

        expect($this->resource->fresh()->landingPage)->not->toBeNull();
        expect($this->resource->landingPage->template)->toBe('default_gfz');
        expect($this->resource->landingPage->status)->toBe('draft');
    });

    test('preview token is automatically generated on creation', function () {
        $this->actingAs($this->user)
            ->postJson("/resources/{$this->resource->id}/landing-page", [
                'template' => 'default_gfz',
                'status' => 'draft',
            ]);

        $landingPage = $this->resource->fresh()->landingPage;
        
        expect($landingPage->preview_token)->not->toBeNull();
        expect($landingPage->preview_token)->toHaveLength(64);
    });

    test('published_at is set when status is published', function () {
        $this->actingAs($this->user)
            ->postJson("/resources/{$this->resource->id}/landing-page", [
                'template' => 'default_gfz',
                'status' => 'published',
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
                'status' => 'draft',
            ]);

        $response->assertStatus(409)
            ->assertJson([
                'message' => 'Landing page already exists for this resource',
            ]);
    });

    test('unauthenticated user cannot create landing page', function () {
        $response = $this->postJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'default_gfz',
            'status' => 'draft',
        ]);

        $response->assertUnauthorized();
    });

    test('validates required fields', function () {
        $response = $this->actingAs($this->user)
            ->postJson("/resources/{$this->resource->id}/landing-page", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['template', 'status']);
    });

    test('validates template must be valid', function () {
        $response = $this->actingAs($this->user)
            ->postJson("/resources/{$this->resource->id}/landing-page", [
                'template' => 'invalid_template',
                'status' => 'draft',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['template']);
    });

    test('validates ftp_url must be valid URL', function () {
        $response = $this->actingAs($this->user)
            ->postJson("/resources/{$this->resource->id}/landing-page", [
                'template' => 'default_gfz',
                'ftp_url' => 'not-a-valid-url',
                'status' => 'draft',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['ftp_url']);
    });
});

describe('Landing Page Updates', function () {
    test('authenticated user can update landing page', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
            'template' => 'default_gfz',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/resources/{$this->resource->id}/landing-page", [
                'ftp_url' => 'https://datapub.gfz-potsdam.de/download/updated',
                'status' => 'published',
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
            'status' => 'draft',
            'published_at' => null,
        ]);

        $this->actingAs($this->user)
            ->putJson("/resources/{$this->resource->id}/landing-page", [
                'status' => 'published',
            ]);

        $landingPage->refresh();
        expect($landingPage->published_at)->not->toBeNull();
    });

    test('updating to draft keeps published_at but changes status', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
            'status' => 'published',
        ]);

        $originalPublishedAt = $landingPage->published_at;

        $this->actingAs($this->user)
            ->putJson("/resources/{$this->resource->id}/landing-page", [
                'status' => 'draft',
            ]);

        $landingPage->refresh();
        expect($landingPage->status)->toBe('draft');
        expect($landingPage->published_at->toString())->toBe($originalPublishedAt->toString());
    });

    test('returns 404 when landing page does not exist', function () {
        $response = $this->actingAs($this->user)
            ->putJson("/resources/{$this->resource->id}/landing-page", [
                'status' => 'published',
            ]);

        $response->assertNotFound()
            ->assertJson([
                'message' => 'Landing page not found',
            ]);
    });

    test('invalidates cache on update', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
            'status' => 'published',
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
    test('authenticated user can delete landing page', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
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
            'status' => 'published',
        ]);

        $response = $this->get("/datasets/{$this->resource->id}");

        $response->assertOk();
        expect($response->viewData('page')['component'])->toBe('landing-page');
    });

    test('draft landing page requires preview token', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
            'status' => 'draft',
        ]);

        // Without token: 404
        $response = $this->get("/datasets/{$this->resource->id}");
        $response->assertNotFound();

        // With valid token: OK
        $response = $this->get("/datasets/{$this->resource->id}?preview={$landingPage->preview_token}");
        $response->assertOk();
    });

    test('draft landing page with invalid token returns 404', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
            'status' => 'draft',
        ]);

        $response = $this->get("/datasets/{$this->resource->id}?preview=invalid-token");
        $response->assertNotFound();
    });

    test('increments view count on each visit for published pages', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
            'status' => 'published',
            'view_count' => 5,
        ]);

        $this->get("/datasets/{$this->resource->id}");
        
        expect($landingPage->fresh()->view_count)->toBe(6);
    });

    test('does not increment view count in preview mode', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
            'status' => 'draft',
            'view_count' => 5,
        ]);

        $this->get("/datasets/{$this->resource->id}?preview={$landingPage->preview_token}");
        
        expect($landingPage->fresh()->view_count)->toBe(5);
    });

    test('updates last_viewed_at timestamp on visit', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
            'status' => 'published',
            'last_viewed_at' => null,
        ]);

        $this->get("/datasets/{$this->resource->id}");
        
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
    test('has correct public_url attribute', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
        ]);

        expect($landingPage->public_url)->toContain("/datasets/{$this->resource->id}");
    });

    test('has correct preview_url attribute with token', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
        ]);

        expect($landingPage->preview_url)->toContain("/datasets/{$this->resource->id}?preview=");
        expect($landingPage->preview_url)->toContain($landingPage->preview_token);
    });

    test('isPublished returns correct status', function () {
        $published = LandingPage::factory()->create(['status' => 'published']);
        $draft = LandingPage::factory()->create(['status' => 'draft']);

        expect($published->isPublished())->toBeTrue();
        expect($draft->isPublished())->toBeFalse();
    });

    test('isDraft returns correct status', function () {
        $published = LandingPage::factory()->create(['status' => 'published']);
        $draft = LandingPage::factory()->create(['status' => 'draft']);

        expect($published->isDraft())->toBeFalse();
        expect($draft->isDraft())->toBeTrue();
    });

    test('publish method updates status and published_at', function () {
        $landingPage = LandingPage::factory()->create([
            'status' => 'draft',
            'published_at' => null,
        ]);

        $landingPage->publish();

        expect($landingPage->fresh()->status)->toBe('published');
        expect($landingPage->fresh()->published_at)->not->toBeNull();
    });

    test('unpublish method updates status to draft', function () {
        $landingPage = LandingPage::factory()->create(['status' => 'published']);

        $landingPage->unpublish();

        expect($landingPage->fresh()->status)->toBe('draft');
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

<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

uses()->group('landing-pages');

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->resource = Resource::factory()->create([
        'created_by_user_id' => $this->user->id,
    ]);
});

describe('Landing Page Creation', function () {
    test('can create landing page as draft', function () {
        $response = $this->postJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'default_gfz',
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/test.zip',
            'status' => 'draft',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'landing_page' => [
                    'id',
                    'resource_id',
                    'template',
                    'ftp_url',
                    'status',
                    'preview_token',
                    'preview_url',
                    'public_url',
                ],
                'preview_url',
            ]);

        expect($this->resource->fresh()->landingPage)
            ->not->toBeNull()
            ->status->toBe('draft')
            ->preview_token->not->toBeNull()
            ->published_at->toBeNull();
    });

    test('can create landing page as published', function () {
        $response = $this->postJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'default_gfz',
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/test.zip',
            'status' => 'published',
        ]);

        $response->assertStatus(201);

        expect($this->resource->fresh()->landingPage)
            ->not->toBeNull()
            ->status->toBe('published')
            ->published_at->not->toBeNull();
    });

    test('cannot create duplicate landing page', function () {
        LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
        ]);

        $response = $this->postJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'default_gfz',
            'status' => 'draft',
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'message' => 'Landing page already exists for this resource',
            ]);
    });

    test('validates required fields', function () {
        $response = $this->postJson("/resources/{$this->resource->id}/landing-page", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['template']);
        // Note: 'status' is optional and defaults to 'draft'
    });

    test('validates template value', function () {
        $response = $this->postJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'invalid_template',
            'status' => 'draft',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['template']);
    });

    test('validates status value', function () {
        $response = $this->postJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'default_gfz',
            'status' => 'invalid_status',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    });

    test('validates ftp_url format', function () {
        $response = $this->postJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'default_gfz',
            'ftp_url' => 'not-a-url',
            'status' => 'draft',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['ftp_url']);
    });
});

describe('Landing Page Updates', function () {
    beforeEach(function () {
        $this->landingPage = LandingPage::factory()->draft()->create([
            'resource_id' => $this->resource->id,
        ]);
    });

    test('can update landing page', function () {
        $response = $this->putJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'default_gfz',
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/updated.zip',
            'status' => 'draft',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Landing page updated successfully',
            ]);

        expect($this->landingPage->fresh())
            ->ftp_url->toBe('https://datapub.gfz-potsdam.de/download/updated.zip');
    });

    test('can publish draft landing page', function () {
        $response = $this->putJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'default_gfz',
            'status' => 'published',
        ]);

        $response->assertStatus(200);

        expect($this->landingPage->fresh())
            ->status->toBe('published')
            ->published_at->not->toBeNull();
    });

    test('can depublish landing page', function () {
        $this->landingPage->publish();

        $response = $this->putJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'default_gfz',
            'status' => 'draft',
        ]);

        $response->assertStatus(200);

        expect($this->landingPage->fresh())
            ->status->toBe('draft')
            ->published_at->toBeNull();
    });

    test('invalidates cache on update', function () {
        // First, cache should be populated by public controller
        Cache::shouldReceive('forget')
            ->once()
            ->with("landing_page.{$this->resource->id}");

        $this->putJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'default_gfz',
            'status' => 'draft',
        ]);
    })->skip('Cache invalidation tested in integration test');

    test('returns 404 when landing page does not exist', function () {
        $newResource = Resource::factory()->create([
            'created_by_user_id' => $this->user->id,
        ]);

        $response = $this->putJson("/resources/{$newResource->id}/landing-page", [
            'template' => 'default_gfz',
            'status' => 'draft',
        ]);

        $response->assertStatus(404);
    });
});

describe('Landing Page Deletion', function () {
    test('can delete landing page', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
        ]);

        $response = $this->deleteJson("/resources/{$this->resource->id}/landing-page");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Landing page deleted successfully',
            ]);

        expect(LandingPage::find($landingPage->id))->toBeNull();
    });

    test('invalidates cache on deletion', function () {
        // Cache invalidation is tested in PublicController tests
    })->skip('Cache invalidation tested in integration test');

    test('returns 404 when landing page does not exist', function () {
        $response = $this->deleteJson("/resources/{$this->resource->id}/landing-page");

        $response->assertStatus(404);
    });
});

describe('Landing Page Retrieval', function () {
    test('can get landing page configuration', function () {
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
            'template' => 'default_gfz',
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/test.zip',
        ]);

        $response = $this->getJson("/resources/{$this->resource->id}/landing-page");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'landing_page' => [
                    'id',
                    'resource_id',
                    'template',
                    'ftp_url',
                    'status',
                    'preview_url',
                    'public_url',
                ],
            ])
            ->assertJson([
                'landing_page' => [
                    'id' => $landingPage->id,
                    'template' => 'default_gfz',
                    'ftp_url' => 'https://datapub.gfz-potsdam.de/download/test.zip',
                ],
            ]);
    });

    test('returns 404 when landing page does not exist', function () {
        $response = $this->getJson("/resources/{$this->resource->id}/landing-page");

        $response->assertStatus(404);
    });
});

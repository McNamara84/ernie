<?php

declare(strict_types=1);

use App\Http\Controllers\LandingPagePreviewController;
use App\Models\LandingPageTemplate;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\User;
use Illuminate\Support\Facades\Session;

covers(LandingPagePreviewController::class);

uses()->group('landing-pages', 'preview');

beforeEach(function () {
    $this->user = User::factory()->curator()->create();
    $this->actingAs($this->user);

    $this->resource = Resource::factory()->create([
        'created_by_user_id' => $this->user->id,
    ]);
});

describe('Session Preview Creation', function () {
    test('can create temporary preview in session', function () {
        $response = $this->postJson("/resources/{$this->resource->id}/landing-page/preview", [
            'template' => 'default_gfz',
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/test.zip',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['preview_url']);

        $sessionKey = "landing_page_preview.{$this->resource->id}";
        expect(Session::has($sessionKey))->toBeTrue();

        $sessionData = Session::get($sessionKey);
        expect($sessionData)
            ->toHaveKey('template', 'default_gfz')
            ->toHaveKey('ftp_url', 'https://datapub.gfz-potsdam.de/download/test.zip')
            ->toHaveKey('resource_id', $this->resource->id);
    });

    test('validates required template field', function () {
        $response = $this->postJson("/resources/{$this->resource->id}/landing-page/preview", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['template']);
    });

    test('validates ftp_url format', function () {
        $response = $this->postJson("/resources/{$this->resource->id}/landing-page/preview", [
            'template' => 'default_gfz',
            'ftp_url' => 'not-a-url',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['ftp_url']);
    });

    test('does not create database record', function () {
        $this->postJson("/resources/{$this->resource->id}/landing-page/preview", [
            'template' => 'default_gfz',
        ]);

        expect($this->resource->fresh()->landingPage)->toBeNull();
    });

    test('external template returns 422 with external_not_previewable error', function () {
        $response = $this->postJson("/resources/{$this->resource->id}/landing-page/preview", [
            'template' => 'external',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'error' => 'external_not_previewable',
                'message' => 'External landing pages do not support session-based previews.',
            ]);

        // Verify no session data was stored
        $sessionKey = "landing_page_preview.{$this->resource->id}";
        expect(Session::has($sessionKey))->toBeFalse();
    });

    test('stores igsn custom template preview data and clears ftp_url for Physical Object resources', function () {
        $physicalObjectType = ResourceType::firstOrCreate(
            ['slug' => 'physical-object'],
            ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true]
        );
        $resource = Resource::factory()->create([
            'created_by_user_id' => $this->user->id,
            'resource_type_id' => $physicalObjectType->id,
        ]);
        $template = LandingPageTemplate::factory()->igsn()->create([
            'created_by' => $this->user->id,
            'logo_path' => 'landing-page-logos/test/igsn-logo.png',
        ]);

        $response = $this->postJson("/resources/{$resource->id}/landing-page/preview", [
            'template' => 'default_gfz_igsn',
            'landing_page_template_id' => $template->id,
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/should-clear.zip',
        ]);

        $response->assertCreated();

        $sessionKey = "landing_page_preview.{$resource->id}";
        $sessionData = Session::get($sessionKey);

        expect($sessionData)
            ->toHaveKey('landing_page_template_id', $template->id)
            ->toHaveKey('ftp_url', null);
    });
});

describe('Session Preview Display', function () {
    test('can view temporary preview from session', function () {
        Session::put("landing_page_preview.{$this->resource->id}", [
            'template' => 'default_gfz',
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/test.zip',
            'resource_id' => $this->resource->id,
        ]);

        $response = $this->get("/resources/{$this->resource->id}/landing-page/preview");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('LandingPages/default_gfz')
                ->has('resource')
                ->has('landingPage')
                ->where('isPreview', true)
            );
    });

    test('returns 404 when session preview does not exist', function () {
        $response = $this->get("/resources/{$this->resource->id}/landing-page/preview");

        $response->assertStatus(404);
    });

    test('session preview has correct structure', function () {
        Session::put("landing_page_preview.{$this->resource->id}", [
            'template' => 'default_gfz',
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/test.zip',
            'resource_id' => $this->resource->id,
        ]);

        $response = $this->get("/resources/{$this->resource->id}/landing-page/preview");

        $response->assertInertia(fn ($page) => $page
            ->where('landingPage.status', 'preview')
            ->where('landingPage.template', 'default_gfz')
            ->where('landingPage.ftp_url', 'https://datapub.gfz-potsdam.de/download/test.zip')
        );
    });

    test('preview display passes custom section order and logo for igsn custom templates', function () {
        $physicalObjectType = ResourceType::firstOrCreate(
            ['slug' => 'physical-object'],
            ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true]
        );
        $resource = Resource::factory()->create([
            'created_by_user_id' => $this->user->id,
            'resource_type_id' => $physicalObjectType->id,
        ]);
        $template = LandingPageTemplate::factory()->igsn()->create([
            'created_by' => $this->user->id,
            'right_column_order' => ['location', 'abstract', 'methods', 'technical_info', 'series_information', 'table_of_contents', 'other', 'creators', 'contributors', 'funders', 'keywords', 'metadata_download'],
            'left_column_order' => ['contact', 'general', 'acquisition', 'model_description', 'related_work'],
            'logo_path' => 'landing-page-logos/test/custom-igsn-logo.png',
        ]);

        Session::put("landing_page_preview.{$resource->id}", [
            'template' => 'default_gfz_igsn',
            'landing_page_template_id' => $template->id,
            'ftp_url' => null,
            'resource_id' => $resource->id,
        ]);

        $response = $this->get("/resources/{$resource->id}/landing-page/preview");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('LandingPages/default_gfz_igsn')
                ->where('landingPage.landing_page_template_id', $template->id)
                ->has('sectionOrder', fn ($order) => $order
                    ->has('rightColumn')
                    ->has('leftColumn')
                )
                ->where('customLogoUrl', fn ($url) => str_contains($url, 'landing-page-logos/test/custom-igsn-logo.png'))
            );
    });

    test('preview display normalizes a legacy Physical Object session to the igsn renderer and keeps a matching igsn custom template', function () {
        $physicalObjectType = ResourceType::firstOrCreate(
            ['slug' => 'physical-object'],
            ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true]
        );
        $resource = Resource::factory()->create([
            'created_by_user_id' => $this->user->id,
            'resource_type_id' => $physicalObjectType->id,
        ]);
        $template = LandingPageTemplate::factory()->igsn()->create([
            'created_by' => $this->user->id,
            'right_column_order' => ['location', 'abstract', 'methods', 'technical_info', 'series_information', 'table_of_contents', 'other', 'creators', 'contributors', 'funders', 'keywords', 'metadata_download'],
            'left_column_order' => ['contact', 'general', 'acquisition', 'model_description', 'related_work'],
            'logo_path' => 'landing-page-logos/test/normalized-igsn-logo.png',
        ]);

        Session::put("landing_page_preview.{$resource->id}", [
            'template' => 'default_gfz',
            'landing_page_template_id' => $template->id,
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/legacy.zip',
            'links' => [['url' => 'https://example.org/file.zip', 'label' => 'Legacy link', 'position' => 0]],
            'resource_id' => $resource->id,
        ]);

        $response = $this->get("/resources/{$resource->id}/landing-page/preview");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('LandingPages/default_gfz_igsn')
                ->where('landingPage.template', 'default_gfz_igsn')
                ->where('landingPage.landing_page_template_id', $template->id)
                ->where('landingPage.ftp_url', null)
                ->where('landingPage.links', [])
                ->has('sectionOrder', fn ($order) => $order
                    ->has('rightColumn')
                    ->has('leftColumn')
                )
                ->where('customLogoUrl', fn ($url) => str_contains($url, 'landing-page-logos/test/normalized-igsn-logo.png'))
            );
    });

    test('preview display clears a mismatched custom template when the session renderer is normalized', function () {
        $physicalObjectType = ResourceType::firstOrCreate(
            ['slug' => 'physical-object'],
            ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true]
        );
        $resource = Resource::factory()->create([
            'created_by_user_id' => $this->user->id,
            'resource_type_id' => $physicalObjectType->id,
        ]);
        $template = LandingPageTemplate::factory()->create([
            'created_by' => $this->user->id,
            'logo_path' => 'landing-page-logos/test/resource-logo.png',
        ]);

        Session::put("landing_page_preview.{$resource->id}", [
            'template' => 'default_gfz',
            'landing_page_template_id' => $template->id,
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/legacy.zip',
            'resource_id' => $resource->id,
        ]);

        $response = $this->get("/resources/{$resource->id}/landing-page/preview");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('LandingPages/default_gfz_igsn')
                ->where('landingPage.template', 'default_gfz_igsn')
                ->where('landingPage.landing_page_template_id', null)
                ->where('customLogoUrl', null)
                ->where('sectionOrder', null)
            );
    });

    test('preview display ignores built-in default template ids passed as custom overrides', function () {
        $defaultTemplate = LandingPageTemplate::ensureDefaultTemplateExists();

        Session::put("landing_page_preview.{$this->resource->id}", [
            'template' => 'default_gfz',
            'landing_page_template_id' => $defaultTemplate->id,
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/test.zip',
            'resource_id' => $this->resource->id,
        ]);

        $response = $this->get("/resources/{$this->resource->id}/landing-page/preview");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('LandingPages/default_gfz')
                ->where('landingPage.landing_page_template_id', null)
                ->where('customLogoUrl', null)
                ->where('sectionOrder', null)
            );
    });
});

describe('Session Preview Deletion', function () {
    test('can clear preview session', function () {
        $sessionKey = "landing_page_preview.{$this->resource->id}";
        Session::put($sessionKey, [
            'template' => 'default_gfz',
            'resource_id' => $this->resource->id,
        ]);

        $response = $this->deleteJson("/resources/{$this->resource->id}/landing-page/preview");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Preview session cleared',
            ]);

        expect(Session::has($sessionKey))->toBeFalse();
    });

    test('clearing non-existent session returns success', function () {
        $response = $this->deleteJson("/resources/{$this->resource->id}/landing-page/preview");

        $response->assertStatus(200);
    });
});

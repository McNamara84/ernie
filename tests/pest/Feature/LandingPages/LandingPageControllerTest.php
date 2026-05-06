<?php

declare(strict_types=1);

use App\Http\Controllers\LandingPageController;
use App\Models\LandingPage;
use App\Models\LandingPageDomain;
use App\Models\LandingPageTemplate;
use App\Models\Resource;
use App\Models\User;

covers(LandingPageController::class);

uses()->group('landing-pages');

beforeEach(function () {
    $this->user = User::factory()->curator()->create();
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

    test('cannot depublish published landing page because DOIs are persistent', function () {
        $this->landingPage->publish();

        $response = $this->putJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'default_gfz',
            'status' => 'draft',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Cannot unpublish a published landing page. DOIs are persistent and must always resolve to a valid landing page.',
                'error' => 'cannot_unpublish',
            ]);

        // Verify landing page is still published
        expect($this->landingPage->fresh())
            ->status->toBe('published')
            ->published_at->not->toBeNull();
    });

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
    test('can delete draft landing page', function () {
        // Create a draft (unpublished) landing page
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
            'is_published' => false,
        ]);

        $response = $this->deleteJson("/resources/{$this->resource->id}/landing-page");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Landing page deleted successfully',
            ]);

        expect(LandingPage::find($landingPage->id))->toBeNull();
    });

    test('cannot delete published landing page because DOIs are persistent', function () {
        // Create a published landing page
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $this->resource->id,
            'is_published' => true,
            'published_at' => now(),
        ]);

        $response = $this->deleteJson("/resources/{$this->resource->id}/landing-page");

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Cannot delete a published landing page. DOIs are persistent and must always resolve to a valid landing page.',
                'error' => 'cannot_delete_published',
            ]);

        // Verify landing page still exists
        expect(LandingPage::find($landingPage->id))->not->toBeNull();
    });

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

describe('External Landing Page Creation', function () {
    test('can create external landing page as draft', function () {
        $domain = LandingPageDomain::factory()->withDomain('https://geofon.gfz.de/')->create();

        $response = $this->postJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'external',
            'external_domain_id' => $domain->id,
            'external_path' => 'doi/network/GE1',
            'status' => 'draft',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('landing_page.template', 'external')
            ->assertJsonPath('landing_page.external_domain_id', $domain->id)
            ->assertJsonPath('landing_page.external_path', 'doi/network/GE1')
            ->assertJsonPath('landing_page.external_url', 'https://geofon.gfz.de/doi/network/GE1');

        $landingPage = $this->resource->fresh()->landingPage;
        expect($landingPage)
            ->template->toBe('external')
            ->ftp_url->toBeNull();
    });

    test('can create external landing page as published', function () {
        $domain = LandingPageDomain::factory()->withDomain('https://data.gfz.de/')->create();

        $response = $this->postJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'external',
            'external_domain_id' => $domain->id,
            'external_path' => 'dataset/42',
            'status' => 'published',
        ]);

        $response->assertStatus(201);

        $landingPage = $this->resource->fresh()->landingPage;
        expect($landingPage)
            ->is_published->toBeTrue()
            ->external_url->toBe('https://data.gfz.de/dataset/42');
    });

    test('external landing page requires domain_id', function () {
        $response = $this->postJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'external',
            'external_path' => 'some/path',
            'status' => 'draft',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['external_domain_id']);
    });

    test('external landing page requires path', function () {
        $domain = LandingPageDomain::factory()->create();

        $response = $this->postJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'external',
            'external_domain_id' => $domain->id,
            'status' => 'draft',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['external_path']);
    });

    test('external landing page rejects invalid domain_id', function () {
        $response = $this->postJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'external',
            'external_domain_id' => 99999,
            'external_path' => 'some/path',
            'status' => 'draft',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['external_domain_id']);
    });

    test('ftp_url is cleared for external landing pages', function () {
        $domain = LandingPageDomain::factory()->create();

        $response = $this->postJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'external',
            'external_domain_id' => $domain->id,
            'external_path' => 'some/path',
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/test.zip',
            'status' => 'draft',
        ]);

        $response->assertStatus(201);
        expect($this->resource->fresh()->landingPage->ftp_url)->toBeNull();
    });

    test('ignores stale custom template id when creating an external landing page', function () {
        $domain = LandingPageDomain::factory()->create();
        $template = LandingPageTemplate::factory()->igsn()->create([
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'external',
            'external_domain_id' => $domain->id,
            'external_path' => 'some/path',
            'status' => 'draft',
            'landing_page_template_id' => $template->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('landing_page.landing_page_template_id', null);

        expect($this->resource->fresh()->landingPage->landing_page_template_id)->toBeNull();
    });
});

describe('External Landing Page Update', function () {
    test('can update landing page to external template', function () {
        $landingPage = LandingPage::factory()->draft()->create([
            'resource_id' => $this->resource->id,
            'template' => 'default_gfz',
        ]);

        $domain = LandingPageDomain::factory()->withDomain('https://data.gfz.de/')->create();

        $response = $this->putJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'external',
            'external_domain_id' => $domain->id,
            'external_path' => 'dataset/123',
            'status' => 'draft',
        ]);

        $response->assertOk()
            ->assertJsonPath('landing_page.template', 'external')
            ->assertJsonPath('landing_page.external_url', 'https://data.gfz.de/dataset/123');
    });

    test('switching from external clears external fields', function () {
        $domain = LandingPageDomain::factory()->create();
        $landingPage = LandingPage::factory()->draft()->external()->create([
            'resource_id' => $this->resource->id,
            'external_domain_id' => $domain->id,
            'external_path' => 'old/path',
        ]);

        $response = $this->putJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'default_gfz',
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/test.zip',
            'status' => 'draft',
        ]);

        $response->assertOk();

        $updated = $this->resource->fresh()->landingPage;
        expect($updated)
            ->template->toBe('default_gfz')
            ->external_domain_id->toBeNull()
            ->external_path->toBeNull();
    });

    test('ignores stale custom template id when switching to external', function () {
        $domain = LandingPageDomain::factory()->withDomain('https://data.gfz.de/')->create();
        $template = LandingPageTemplate::factory()->igsn()->create([
            'created_by' => $this->user->id,
        ]);

        LandingPage::factory()->draft()->create([
            'resource_id' => $this->resource->id,
            'template' => 'default_gfz',
            'landing_page_template_id' => null,
        ]);

        $response = $this->putJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'external',
            'external_domain_id' => $domain->id,
            'external_path' => 'dataset/123',
            'status' => 'draft',
            'landing_page_template_id' => $template->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('landing_page.template', 'external')
            ->assertJsonPath('landing_page.landing_page_template_id', null);

        expect($this->resource->fresh()->landingPage->landing_page_template_id)->toBeNull();
    });

    test('switching to external clears an existing custom template id even when the field is omitted', function () {
        $domain = LandingPageDomain::factory()->withDomain('https://data.gfz.de/')->create();
        $template = LandingPageTemplate::factory()->create([
            'created_by' => $this->user->id,
        ]);

        LandingPage::factory()->draft()->create([
            'resource_id' => $this->resource->id,
            'template' => 'default_gfz',
            'landing_page_template_id' => $template->id,
        ]);

        $response = $this->putJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'external',
            'external_domain_id' => $domain->id,
            'external_path' => 'dataset/123',
            'status' => 'draft',
        ]);

        $response->assertOk()
            ->assertJsonPath('landing_page.template', 'external')
            ->assertJsonPath('landing_page.landing_page_template_id', null);

        expect($this->resource->fresh()->landingPage->landing_page_template_id)->toBeNull();
    });

    test('updating an existing external landing page clears a stale custom template id even when template is omitted', function () {
        $domain = LandingPageDomain::factory()->withDomain('https://data.gfz.de/')->create();
        $template = LandingPageTemplate::factory()->create([
            'created_by' => $this->user->id,
        ]);

        LandingPage::factory()->draft()->external()->create([
            'resource_id' => $this->resource->id,
            'external_domain_id' => $domain->id,
            'external_path' => 'dataset/original',
            'landing_page_template_id' => $template->id,
        ]);

        $response = $this->putJson("/resources/{$this->resource->id}/landing-page", [
            'external_path' => 'dataset/updated',
            'status' => 'draft',
        ]);

        $response->assertOk()
            ->assertJsonPath('landing_page.template', 'external')
            ->assertJsonPath('landing_page.external_path', 'dataset/updated')
            ->assertJsonPath('landing_page.landing_page_template_id', null);

        $updated = $this->resource->fresh()->landingPage;
        expect($updated)
            ->external_path->toBe('dataset/updated')
            ->landing_page_template_id->toBeNull();
    });
});

describe('Landing Page Template Assignment', function () {
    test('can create landing page with custom template', function () {
        $template = LandingPageTemplate::factory()->create([
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'default_gfz',
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/test.zip',
            'status' => 'draft',
            'landing_page_template_id' => $template->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('landing_page.landing_page_template_id', $template->id)
            ->assertJsonPath('landing_page.landing_page_template.id', $template->id)
            ->assertJsonPath('landing_page.landing_page_template.name', $template->name);

        $landingPage = $this->resource->fresh()->landingPage;
        expect($landingPage->landing_page_template_id)->toBe($template->id);
    });

    test('can update landing page template assignment', function () {
        $template = LandingPageTemplate::factory()->create([
            'created_by' => $this->user->id,
        ]);

        LandingPage::factory()->draft()->create([
            'resource_id' => $this->resource->id,
            'landing_page_template_id' => null,
        ]);

        $response = $this->putJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'default_gfz',
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/test.zip',
            'status' => 'draft',
            'landing_page_template_id' => $template->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('landing_page.landing_page_template_id', $template->id)
            ->assertJsonPath('landing_page.landing_page_template.id', $template->id)
            ->assertJsonPath('landing_page.landing_page_template.name', $template->name);

        $landingPage = $this->resource->fresh()->landingPage;
        expect($landingPage->landing_page_template_id)->toBe($template->id);
    });

    test('rejects unsupported fields when explicitly switching to an external template', function () {
        $domain = LandingPageDomain::factory()->withDomain('https://data.gfz.de/')->create();

        LandingPage::factory()->draft()->create([
            'resource_id' => $this->resource->id,
            'template' => 'default_gfz',
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/original.zip',
        ]);

        $response = $this->putJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'external',
            'external_domain_id' => $domain->id,
            'external_path' => 'dataset/123',
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/updated.zip',
            'links' => [
                [
                    'url' => 'https://example.org/details',
                    'label' => 'Details',
                    'position' => 0,
                ],
            ],
            'status' => 'draft',
        ]);

        $response->assertUnprocessable()
            ->assertJson([
                'message' => 'The request includes fields that are not supported for this landing page template.',
            ])
            ->assertJsonValidationErrors(['ftp_url', 'links']);

        expect($this->resource->fresh()->landingPage)
            ->template->toBe('default_gfz')
            ->ftp_url->toBe('https://datapub.gfz-potsdam.de/download/original.zip')
            ->external_domain_id->toBeNull()
            ->external_path->toBeNull();
    });

    test('can clear template assignment by setting null', function () {
        $template = LandingPageTemplate::factory()->create([
            'created_by' => $this->user->id,
        ]);

        LandingPage::factory()->draft()->create([
            'resource_id' => $this->resource->id,
            'landing_page_template_id' => $template->id,
        ]);

        $response = $this->putJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'default_gfz',
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/test.zip',
            'status' => 'draft',
            'landing_page_template_id' => null,
        ]);

        $response->assertOk();

        $landingPage = $this->resource->fresh()->landingPage;
        expect($landingPage->landing_page_template_id)->toBeNull();
    });

    test('rejects invalid template id', function () {
        $response = $this->postJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'default_gfz',
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/test.zip',
            'status' => 'draft',
            'landing_page_template_id' => 99999,
        ]);

        $response->assertJsonValidationErrors(['landing_page_template_id']);
    });

    test('rejects assigning an igsn custom template to a regular resource on create', function () {
        $template = LandingPageTemplate::factory()->igsn()->create([
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'default_gfz',
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/test.zip',
            'status' => 'draft',
            'landing_page_template_id' => $template->id,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'The selected custom landing page template is only available for IGSN landing pages.',
                'error' => 'invalid_template_for_resource_type',
            ]);
    });

    test('rejects assigning an igsn custom template to a regular resource on update', function () {
        $template = LandingPageTemplate::factory()->igsn()->create([
            'created_by' => $this->user->id,
        ]);

        LandingPage::factory()->draft()->create([
            'resource_id' => $this->resource->id,
            'landing_page_template_id' => null,
        ]);

        $response = $this->putJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'default_gfz',
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/test.zip',
            'status' => 'draft',
            'landing_page_template_id' => $template->id,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'The selected custom landing page template is only available for IGSN landing pages.',
                'error' => 'invalid_template_for_resource_type',
            ]);
    });

    test('rejects assigning the built-in resource default template id as a custom override on create', function () {
        $template = LandingPageTemplate::ensureDefaultTemplateExists();

        $response = $this->postJson("/resources/{$this->resource->id}/landing-page", [
            'template' => 'default_gfz',
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/test.zip',
            'status' => 'draft',
            'landing_page_template_id' => $template->id,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'The selected landing page template is a built-in default and cannot be used as a custom override.',
                'error' => 'invalid_template_for_resource_type',
            ]);
    });
});

describe('Landing Page GET Endpoint', function () {
    // Regression coverage for the Setup Landing Page dialog template-persistence
    // bug: the GET endpoint must return landing_page_template_id so the frontend
    // can hydrate the dropdown with the currently saved custom template.

    test('returns landing_page_template_id when a custom template is assigned', function () {
        $template = LandingPageTemplate::factory()->create([
            'created_by' => $this->user->id,
        ]);

        LandingPage::factory()->draft()->create([
            'resource_id' => $this->resource->id,
            'template' => 'default_gfz',
            'landing_page_template_id' => $template->id,
        ]);

        $response = $this->getJson("/resources/{$this->resource->id}/landing-page");

        $response
            ->assertOk()
            ->assertJsonPath('landing_page.landing_page_template_id', $template->id)
            ->assertJsonPath('landing_page.template', 'default_gfz')
            ->assertJsonPath('landing_page.landing_page_template.id', $template->id)
            ->assertJsonPath('landing_page.landing_page_template.name', $template->name);
    });

    test('returns null landing_page_template_id when no custom template is assigned', function () {
        LandingPage::factory()->draft()->create([
            'resource_id' => $this->resource->id,
            'template' => 'default_gfz',
            'landing_page_template_id' => null,
        ]);

        $response = $this->getJson("/resources/{$this->resource->id}/landing-page");

        $response
            ->assertOk()
            ->assertJsonPath('landing_page.landing_page_template_id', null);
    });
});

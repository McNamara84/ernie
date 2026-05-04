<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Controllers\LandingPageController;
use App\Models\LandingPage;
use App\Models\LandingPageTemplate;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\User;

/**
 * IGSN Landing Page Tests
 *
 * Tests the IGSN-specific landing page functionality including:
 * - Template restriction to PhysicalObject resources only
 * - IGSN landing page creation and updates
 * - Session-based preview validation
 */
uses()->group('landing-pages', 'igsn');

describe('IGSN Template Configuration', function () {
    test('allowed templates contains default_gfz_igsn', function () {
        expect(LandingPageController::ALLOWED_TEMPLATES)->toContain('default_gfz_igsn');
    });

    test('igsn only templates contains default_gfz_igsn', function () {
        expect(LandingPageController::IGSN_ONLY_TEMPLATES)->toContain('default_gfz_igsn');
    });

    test('default_gfz is not in igsn only templates', function () {
        expect(LandingPageController::IGSN_ONLY_TEMPLATES)->not->toContain('default_gfz');
    });

    test('resource only templates contains default_gfz', function () {
        expect(LandingPageController::RESOURCE_ONLY_TEMPLATES)->toContain('default_gfz');
    });
});

describe('IGSN Template Restriction on Creation', function () {
    test('cannot use igsn template for non-PhysicalObject resource', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        // Create a regular Dataset resource (not PhysicalObject)
        $datasetType = ResourceType::firstOrCreate(
            ['slug' => 'dataset'],
            ['name' => 'Dataset', 'slug' => 'dataset', 'is_active' => true]
        );
        $resource = Resource::factory()->create(['resource_type_id' => $datasetType->id]);

        $response = $this->actingAs($user)
            ->postJson("/resources/{$resource->id}/landing-page", [
                'template' => 'default_gfz_igsn',
                'status' => 'draft',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'The IGSN template can only be used with Physical Object resources.',
                'error' => 'invalid_template_for_resource_type',
            ]);
    });

    test('can use igsn template for PhysicalObject resource', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        // Create a PhysicalObject resource (IGSN)
        $physicalObjectType = ResourceType::firstOrCreate(
            ['slug' => 'physical-object'],
            ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true]
        );
        $resource = Resource::factory()->create(['resource_type_id' => $physicalObjectType->id]);

        $response = $this->actingAs($user)
            ->postJson("/resources/{$resource->id}/landing-page", [
                'template' => 'default_gfz_igsn',
                'status' => 'draft',
            ]);

        $response->assertCreated();
        expect(LandingPage::where('resource_id', $resource->id)->first()->template)->toBe('default_gfz_igsn');
    });

    test('cannot use default_gfz template for PhysicalObject resource', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        $physicalObjectType = ResourceType::firstOrCreate(
            ['slug' => 'physical-object'],
            ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true]
        );
        $resource = Resource::factory()->create(['resource_type_id' => $physicalObjectType->id]);

        $response = $this->actingAs($user)
            ->postJson("/resources/{$resource->id}/landing-page", [
                'template' => 'default_gfz',
                'status' => 'draft',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'The Default GFZ Data Services template cannot be used with Physical Object resources. Use the IGSN template instead.',
                'error' => 'invalid_template_for_resource_type',
            ]);

        expect(LandingPage::where('resource_id', $resource->id)->first())->toBeNull();
    });

    test('rejects assigning a regular resource custom template to a PhysicalObject resource on create', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        $physicalObjectType = ResourceType::firstOrCreate(
            ['slug' => 'physical-object'],
            ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true]
        );
        $resource = Resource::factory()->create(['resource_type_id' => $physicalObjectType->id]);
        $template = LandingPageTemplate::factory()->create(['created_by' => $user->id]);

        $response = $this->actingAs($user)
            ->postJson("/resources/{$resource->id}/landing-page", [
                'template' => 'default_gfz_igsn',
                'status' => 'draft',
                'landing_page_template_id' => $template->id,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'The selected custom landing page template is only available for regular resource landing pages.',
                'error' => 'invalid_template_for_resource_type',
            ]);
    });

    test('can assign an igsn custom template on create', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        $physicalObjectType = ResourceType::firstOrCreate(
            ['slug' => 'physical-object'],
            ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true]
        );
        $resource = Resource::factory()->create(['resource_type_id' => $physicalObjectType->id]);
        $template = LandingPageTemplate::factory()->igsn()->create(['created_by' => $user->id]);

        $response = $this->actingAs($user)
            ->postJson("/resources/{$resource->id}/landing-page", [
                'template' => 'default_gfz_igsn',
                'status' => 'draft',
                'landing_page_template_id' => $template->id,
            ]);

        $response->assertCreated()
            ->assertJsonPath('landing_page.template', 'default_gfz_igsn')
            ->assertJsonPath('landing_page.landing_page_template_id', $template->id);

        expect(LandingPage::where('resource_id', $resource->id)->first()->landing_page_template_id)->toBe($template->id);
    });

    test('clears ftp_url when creating an igsn landing page', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        $physicalObjectType = ResourceType::firstOrCreate(
            ['slug' => 'physical-object'],
            ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true]
        );
        $resource = Resource::factory()->create(['resource_type_id' => $physicalObjectType->id]);

        $response = $this->actingAs($user)
            ->postJson("/resources/{$resource->id}/landing-page", [
                'template' => 'default_gfz_igsn',
                'status' => 'draft',
                'ftp_url' => 'https://datapub.gfz-potsdam.de/download/sample.zip',
            ]);

        $response->assertCreated()
            ->assertJsonPath('landing_page.ftp_url', null);

        expect(LandingPage::where('resource_id', $resource->id)->first()->ftp_url)->toBeNull();
    });
});

describe('IGSN Template Restriction on Update', function () {
    test('cannot change template to igsn for non-PhysicalObject resource', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        $datasetType = ResourceType::firstOrCreate(
            ['slug' => 'dataset'],
            ['name' => 'Dataset', 'slug' => 'dataset', 'is_active' => true]
        );
        $resource = Resource::factory()->create(['resource_type_id' => $datasetType->id]);
        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => false,
        ]);

        $response = $this->actingAs($user)
            ->putJson("/resources/{$resource->id}/landing-page", [
                'template' => 'default_gfz_igsn',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'The IGSN template can only be used with Physical Object resources.',
                'error' => 'invalid_template_for_resource_type',
            ]);
    });

    test('can change template to igsn for PhysicalObject resource', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        $physicalObjectType = ResourceType::firstOrCreate(
            ['slug' => 'physical-object'],
            ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true]
        );
        $resource = Resource::factory()->create(['resource_type_id' => $physicalObjectType->id]);
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => false,
        ]);

        $response = $this->actingAs($user)
            ->putJson("/resources/{$resource->id}/landing-page", [
                'template' => 'default_gfz_igsn',
            ]);

        $response->assertOk();
        expect($landingPage->fresh()->template)->toBe('default_gfz_igsn');
    });

    test('cannot change template to default_gfz for PhysicalObject resource', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        $physicalObjectType = ResourceType::firstOrCreate(
            ['slug' => 'physical-object'],
            ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true]
        );
        $resource = Resource::factory()->create(['resource_type_id' => $physicalObjectType->id]);
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz_igsn',
            'is_published' => false,
        ]);

        $response = $this->actingAs($user)
            ->putJson("/resources/{$resource->id}/landing-page", [
                'template' => 'default_gfz',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'The Default GFZ Data Services template cannot be used with Physical Object resources. Use the IGSN template instead.',
                'error' => 'invalid_template_for_resource_type',
            ]);

        expect($landingPage->fresh()->template)->toBe('default_gfz_igsn');
    });

    test('rejects assigning a regular resource custom template to a PhysicalObject resource on update', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        $physicalObjectType = ResourceType::firstOrCreate(
            ['slug' => 'physical-object'],
            ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true]
        );
        $resource = Resource::factory()->create(['resource_type_id' => $physicalObjectType->id]);
        $template = LandingPageTemplate::factory()->create(['created_by' => $user->id]);
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'landing_page_template_id' => $template->id,
            'is_published' => false,
        ]);

        $response = $this->actingAs($user)
            ->putJson("/resources/{$resource->id}/landing-page", [
                'template' => 'default_gfz_igsn',
                'landing_page_template_id' => $template->id,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'The selected custom landing page template is only available for regular resource landing pages.',
                'error' => 'invalid_template_for_resource_type',
            ]);

        expect($landingPage->fresh()->landing_page_template_id)->toBe($template->id);
    });

    test('can assign an igsn custom template on update', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        $physicalObjectType = ResourceType::firstOrCreate(
            ['slug' => 'physical-object'],
            ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true]
        );
        $resource = Resource::factory()->create(['resource_type_id' => $physicalObjectType->id]);
        $template = LandingPageTemplate::factory()->igsn()->create(['created_by' => $user->id]);
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz_igsn',
            'landing_page_template_id' => null,
            'is_published' => false,
        ]);

        $response = $this->actingAs($user)
            ->putJson("/resources/{$resource->id}/landing-page", [
                'landing_page_template_id' => $template->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('landing_page.template', 'default_gfz_igsn')
            ->assertJsonPath('landing_page.landing_page_template_id', $template->id);

        expect($landingPage->fresh()->landing_page_template_id)->toBe($template->id);
    });

    test('switching a legacy PhysicalObject page to the igsn template clears a stale custom template id when the field is omitted', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        $physicalObjectType = ResourceType::firstOrCreate(
            ['slug' => 'physical-object'],
            ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true]
        );
        $resource = Resource::factory()->create(['resource_type_id' => $physicalObjectType->id]);
        $template = LandingPageTemplate::factory()->create(['created_by' => $user->id]);
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'landing_page_template_id' => $template->id,
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/legacy.zip',
            'is_published' => false,
        ]);

        $response = $this->actingAs($user)
            ->putJson("/resources/{$resource->id}/landing-page", [
                'template' => 'default_gfz_igsn',
            ]);

        $response->assertOk()
            ->assertJsonPath('landing_page.template', 'default_gfz_igsn')
            ->assertJsonPath('landing_page.landing_page_template_id', null)
            ->assertJsonPath('landing_page.ftp_url', null);

        expect($landingPage->fresh()->landing_page_template_id)->toBeNull();
        expect($landingPage->fresh()->ftp_url)->toBeNull();
    });

    test('publishing a legacy PhysicalObject page normalizes the built-in template and clears a stale resource custom template id when the field is omitted', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        $physicalObjectType = ResourceType::firstOrCreate(
            ['slug' => 'physical-object'],
            ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true]
        );
        $resource = Resource::factory()->create(['resource_type_id' => $physicalObjectType->id]);
        $template = LandingPageTemplate::factory()->create(['created_by' => $user->id]);
        $landingPage = LandingPage::factory()->draft()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'landing_page_template_id' => $template->id,
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/legacy.zip',
            'is_published' => false,
        ]);

        $response = $this->actingAs($user)
            ->putJson("/resources/{$resource->id}/landing-page", [
                'status' => 'published',
            ]);

        $response->assertOk()
            ->assertJsonPath('landing_page.template', 'default_gfz_igsn')
            ->assertJsonPath('landing_page.landing_page_template_id', null)
            ->assertJsonPath('landing_page.ftp_url', null)
            ->assertJsonPath('landing_page.is_published', true);

        expect($landingPage->fresh()->template)->toBe('default_gfz_igsn');
        expect($landingPage->fresh()->landing_page_template_id)->toBeNull();
        expect($landingPage->fresh()->ftp_url)->toBeNull();
        expect($landingPage->fresh()->is_published)->toBeTrue();
    });

    test('clears ftp_url when updating an igsn landing page', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        $physicalObjectType = ResourceType::firstOrCreate(
            ['slug' => 'physical-object'],
            ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true]
        );
        $resource = Resource::factory()->create(['resource_type_id' => $physicalObjectType->id]);
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz_igsn',
            'ftp_url' => null,
            'is_published' => false,
        ]);

        $response = $this->actingAs($user)
            ->putJson("/resources/{$resource->id}/landing-page", [
                'ftp_url' => 'https://datapub.gfz-potsdam.de/download/new.zip',
            ]);

        $response->assertOk()
            ->assertJsonPath('landing_page.ftp_url', null);

        expect($landingPage->fresh()->ftp_url)->toBeNull();
    });
});

describe('IGSN Template Preview Session', function () {
    test('cannot create igsn template preview for non-PhysicalObject resource', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        $datasetType = ResourceType::firstOrCreate(
            ['slug' => 'dataset'],
            ['name' => 'Dataset', 'slug' => 'dataset', 'is_active' => true]
        );
        $resource = Resource::factory()->create(['resource_type_id' => $datasetType->id]);

        $response = $this->actingAs($user)
            ->postJson("/resources/{$resource->id}/landing-page/preview", [
                'template' => 'default_gfz_igsn',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'The IGSN template can only be used with Physical Object resources.',
                'error' => 'invalid_template_for_resource_type',
            ]);
    });

    test('can create igsn template preview for PhysicalObject resource', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        $physicalObjectType = ResourceType::firstOrCreate(
            ['slug' => 'physical-object'],
            ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true]
        );
        $resource = Resource::factory()->create(['resource_type_id' => $physicalObjectType->id]);

        $response = $this->actingAs($user)
            ->postJson("/resources/{$resource->id}/landing-page/preview", [
                'template' => 'default_gfz_igsn',
            ]);

        $response->assertCreated()
            ->assertJsonStructure(['preview_url']);
    });

    test('cannot create default_gfz preview for PhysicalObject resource', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        $physicalObjectType = ResourceType::firstOrCreate(
            ['slug' => 'physical-object'],
            ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true]
        );
        $resource = Resource::factory()->create(['resource_type_id' => $physicalObjectType->id]);

        $response = $this->actingAs($user)
            ->postJson("/resources/{$resource->id}/landing-page/preview", [
                'template' => 'default_gfz',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'The Default GFZ Data Services template cannot be used with Physical Object resources. Use the IGSN template instead.',
                'error' => 'invalid_template_for_resource_type',
            ]);
    });
});

describe('IGSN Landing Page without FTP URL', function () {
    test('igsn landing page can be created without ftp_url', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        $physicalObjectType = ResourceType::firstOrCreate(
            ['slug' => 'physical-object'],
            ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true]
        );
        $resource = Resource::factory()->create(['resource_type_id' => $physicalObjectType->id]);

        $response = $this->actingAs($user)
            ->postJson("/resources/{$resource->id}/landing-page", [
                'template' => 'default_gfz_igsn',
                'status' => 'draft',
                // Note: No ftp_url field - this is typical for IGSN landing pages
            ]);

        $response->assertCreated();
        $landingPage = LandingPage::where('resource_id', $resource->id)->first();
        expect($landingPage->template)->toBe('default_gfz_igsn');
        expect($landingPage->ftp_url)->toBeNull();
    });
});

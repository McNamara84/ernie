<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Controllers\LandingPageController;
use App\Models\LandingPage;
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
});

describe('IGSN Template Restriction on Creation', function () {
    test('cannot use igsn template for non-PhysicalObject resource', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        // Create a regular Dataset resource (not PhysicalObject)
        $datasetType = ResourceType::firstOrCreate(
            ['slug' => 'Dataset'],
            ['name' => 'Dataset', 'slug' => 'Dataset', 'is_active' => true]
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
            ['slug' => 'PhysicalObject'],
            ['name' => 'PhysicalObject', 'slug' => 'PhysicalObject', 'is_active' => true]
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

    test('can use default_gfz template for PhysicalObject resource', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        $physicalObjectType = ResourceType::firstOrCreate(
            ['slug' => 'PhysicalObject'],
            ['name' => 'PhysicalObject', 'slug' => 'PhysicalObject', 'is_active' => true]
        );
        $resource = Resource::factory()->create(['resource_type_id' => $physicalObjectType->id]);

        $response = $this->actingAs($user)
            ->postJson("/resources/{$resource->id}/landing-page", [
                'template' => 'default_gfz',
                'status' => 'draft',
            ]);

        $response->assertCreated();
        expect(LandingPage::where('resource_id', $resource->id)->first()->template)->toBe('default_gfz');
    });
});

describe('IGSN Template Restriction on Update', function () {
    test('cannot change template to igsn for non-PhysicalObject resource', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        $datasetType = ResourceType::firstOrCreate(
            ['slug' => 'Dataset'],
            ['name' => 'Dataset', 'slug' => 'Dataset', 'is_active' => true]
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
            ['slug' => 'PhysicalObject'],
            ['name' => 'PhysicalObject', 'slug' => 'PhysicalObject', 'is_active' => true]
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
});

describe('IGSN Template Preview Session', function () {
    test('cannot create igsn template preview for non-PhysicalObject resource', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        $datasetType = ResourceType::firstOrCreate(
            ['slug' => 'Dataset'],
            ['name' => 'Dataset', 'slug' => 'Dataset', 'is_active' => true]
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
            ['slug' => 'PhysicalObject'],
            ['name' => 'PhysicalObject', 'slug' => 'PhysicalObject', 'is_active' => true]
        );
        $resource = Resource::factory()->create(['resource_type_id' => $physicalObjectType->id]);

        $response = $this->actingAs($user)
            ->postJson("/resources/{$resource->id}/landing-page/preview", [
                'template' => 'default_gfz_igsn',
            ]);

        $response->assertCreated()
            ->assertJsonStructure(['preview_url']);
    });
});

describe('IGSN Landing Page without FTP URL', function () {
    test('igsn landing page can be created without ftp_url', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        $physicalObjectType = ResourceType::firstOrCreate(
            ['slug' => 'PhysicalObject'],
            ['name' => 'PhysicalObject', 'slug' => 'PhysicalObject', 'is_active' => true]
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

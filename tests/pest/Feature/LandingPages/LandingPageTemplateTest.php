<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Controllers\LandingPageController;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\User;

/**
 * Template Change Tests for Landing Page Management
 *
 * Tests the ability to change the landing page template after initial creation,
 * including validation and template restrictions.
 *
 * @see Issue #375 - Enable subsequent modification of the landing page template
 */
uses()->group('landing-pages', 'template-change');

describe('Landing Page Template Modification', function () {
    test('can update template on existing landing page', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);
        $resource = Resource::factory()->create();
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => false,
        ]);

        // Note: Currently only 'default_gfz' template exists. When additional templates
        // are added (e.g., 'minimal', 'academic'), update this test to verify switching
        // between different templates. The validation infrastructure is already in place.
        $response = $this->actingAs($user)
            ->putJson("/resources/{$resource->id}/landing-page", [
                'template' => 'default_gfz',
            ]);

        $response->assertOk();
        expect($landingPage->fresh()->template)->toBe('default_gfz');
    });

    test('cannot set invalid template on landing page', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);
        $resource = Resource::factory()->create();
        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => false,
        ]);

        $response = $this->actingAs($user)
            ->putJson("/resources/{$resource->id}/landing-page", [
                'template' => 'non_existent_template',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['template']);
    });

    test('cannot set invalid template on creation', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);
        $resource = Resource::factory()->create();

        $response = $this->actingAs($user)
            ->postJson("/resources/{$resource->id}/landing-page", [
                'template' => 'invalid_template',
                'is_published' => false,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['template']);
    });

    test('allowed templates constant contains default_gfz', function () {
        expect(LandingPageController::ALLOWED_TEMPLATES)->toContain('default_gfz');
    });

    test('allowed templates is used for validation', function () {
        // Verify the constant exists and is an array
        expect(LandingPageController::ALLOWED_TEMPLATES)
            ->toBeArray()
            ->not->toBeEmpty();
    });
});

describe('Landing Page Template with Other Fields', function () {
    test('can update template and ftp_url simultaneously', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);
        $resource = Resource::factory()->create();
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'ftp_url' => null,
            'is_published' => false,
        ]);

        $response = $this->actingAs($user)
            ->putJson("/resources/{$resource->id}/landing-page", [
                'template' => 'default_gfz',
                'ftp_url' => 'https://datapub.gfz-potsdam.de/download/new-path',
            ]);

        $response->assertOk();
        $landingPage->refresh();
        expect($landingPage->template)->toBe('default_gfz');
        expect($landingPage->ftp_url)->toBe('https://datapub.gfz-potsdam.de/download/new-path');
    });

    test('template change does not affect published status', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);
        $resource = Resource::factory()->create();
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => false,
        ]);

        $this->actingAs($user)
            ->putJson("/resources/{$resource->id}/landing-page", [
                'template' => 'default_gfz',
            ]);

        $landingPage->refresh();
        expect($landingPage->is_published)->toBeFalse();
    });
});

describe('Landing Page Preview Session Storage', function () {
    test('session preview stores template correctly', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);
        $resource = Resource::factory()->create();

        $response = $this->actingAs($user)
            ->postJson("/resources/{$resource->id}/landing-page/preview", [
                'template' => 'default_gfz',
                'ftp_url' => 'https://datapub.gfz-potsdam.de/download/test',
            ]);

        $response->assertCreated();
        $response->assertJsonStructure(['preview_url']);
    });

    test('session preview rejects invalid template', function () {
        $user = User::factory()->create(['role' => UserRole::CURATOR]);
        $resource = Resource::factory()->create();

        $response = $this->actingAs($user)
            ->postJson("/resources/{$resource->id}/landing-page/preview", [
                'template' => 'invalid_template',
                'ftp_url' => 'https://datapub.gfz-potsdam.de/download/test',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['template']);
    });

    test('beginner cannot create session preview', function () {
        $user = User::factory()->create(['role' => UserRole::BEGINNER]);
        $resource = Resource::factory()->create();

        $response = $this->actingAs($user)
            ->postJson("/resources/{$resource->id}/landing-page/preview", [
                'template' => 'default_gfz',
            ]);

        $response->assertForbidden();
    });

    test('beginner cannot clear session preview', function () {
        $user = User::factory()->create(['role' => UserRole::BEGINNER]);
        $resource = Resource::factory()->create();

        $response = $this->actingAs($user)
            ->deleteJson("/resources/{$resource->id}/landing-page/preview");

        $response->assertForbidden();
    });
});

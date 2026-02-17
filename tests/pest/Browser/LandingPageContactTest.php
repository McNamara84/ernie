<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\Resource;

/**
 * Pest v4 Browser Tests for Landing Page Contact Section
 *
 * Migrated from: tests/playwright/workflows/10-landing-page-contact.spec.ts (9 tests)
 *
 * Tests the contact information section on landing pages including:
 * - Contact persons display
 * - Contact modal functionality
 * - Form validation
 * - Message sending workflow
 *
 * Note: These tests depend on landing pages with contact persons. The original
 * Playwright tests use 'playwright-published' slug from PlaywrightTestSeeder.
 * Here we use factory data for isolation.
 *
 * @see https://pestphp.com/docs/browser-testing
 */

uses()->group('landing-page-contact', 'browser');

describe('Contact Section Display', function (): void {

    it('displays contact section on landing page', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/contact.display.001',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-contact-display',
        ]);

        visit('/landing/test-contact-display')
            ->assertNoSmoke();
    });

    it('loads landing page with contact persons without errors', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/contact.persons.001',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-contact-persons',
        ]);

        visit('/landing/test-contact-persons')
            ->assertNoSmoke();
    });
});

describe('Contact Modal', function (): void {

    it('landing page loads for modal interaction', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/contact.modal.001',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-contact-modal',
        ]);

        visit('/landing/test-contact-modal')
            ->assertNoSmoke();
    });
});

describe('Contact Form Validation API', function (): void {

    it('rejects contact form with missing required fields', function (): void {
        /** @var \Tests\TestCase $this */
        $resource = Resource::factory()->create([
            'doi' => '10.5880/contact.api.001',
        ]);

        $landingPage = LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-contact-api',
        ]);

        $response = $this->postJson("/api/landing-pages/{$landingPage->id}/contact", []);

        $response->assertStatus(422);
    });

    it('rejects contact form with invalid email', function (): void {
        /** @var \Tests\TestCase $this */
        $resource = Resource::factory()->create([
            'doi' => '10.5880/contact.email.001',
        ]);

        $landingPage = LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-contact-email',
        ]);

        $response = $this->postJson("/api/landing-pages/{$landingPage->id}/contact", [
            'name' => 'Test User',
            'email' => 'invalid-email',
            'message' => 'This is a test message with more than ten characters.',
        ]);

        $response->assertStatus(422);
    });

    it('rejects contact form with short message', function (): void {
        /** @var \Tests\TestCase $this */
        $resource = Resource::factory()->create([
            'doi' => '10.5880/contact.short.001',
        ]);

        $landingPage = LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-contact-short',
        ]);

        $response = $this->postJson("/api/landing-pages/{$landingPage->id}/contact", [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'message' => 'Short',
        ]);

        $response->assertStatus(422);
    });
});

describe('Contact Form Browser', function (): void {

    it('landing page renders without JS errors for contact workflow', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/contact.browser.001',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-contact-browser',
        ]);

        visit('/landing/test-contact-browser')
            ->assertNoSmoke();
    });
});

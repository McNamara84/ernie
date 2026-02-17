<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\Resource;
use Tests\TestCase;

/**
 * Pest v4 Browser Tests for Landing Page Contact Section
 *
 * Converted from 7 original tests. Smoke tests verify landing pages with contact sections
 * load without JS errors. HTTP tests verify the contact form API endpoint validation.
 *
 * Landing page URL format: /{doiPrefix}/{slug}
 * Contact API route: POST /{doiPrefix}/{slug}/contact
 *
 * @see https://pestphp.com/docs/browser-testing
 */

uses()->group('landing-page-contact', 'browser');

describe('Contact Section Display (Smoke)', function (): void {

    it('displays landing page with contact section', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/contact.display.001',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => '10.5880/contact.display.001',
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-contact-display',
        ]);

        visit('/10.5880/contact.display.001/test-contact-display')
            ->assertNoSmoke();
    });

    it('loads landing page with contact persons without errors', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/contact.persons.001',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => '10.5880/contact.persons.001',
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-contact-persons',
        ]);

        visit('/10.5880/contact.persons.001/test-contact-persons')
            ->assertNoSmoke();
    });

    it('landing page loads for modal interaction', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/contact.modal.001',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => '10.5880/contact.modal.001',
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-contact-modal',
        ]);

        visit('/10.5880/contact.modal.001/test-contact-modal')
            ->assertNoSmoke();
    });

    it('landing page renders without JS errors for contact workflow', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/contact.browser.001',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => '10.5880/contact.browser.001',
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-contact-browser',
        ]);

        visit('/10.5880/contact.browser.001/test-contact-browser')
            ->assertNoSmoke();
    });
});

describe('Contact Form Validation API', function (): void {

    it('rejects contact form with missing required fields', function (): void {
        /** @var TestCase $this */
        $resource = Resource::factory()->create([
            'doi' => '10.5880/contact.api.001',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => '10.5880/contact.api.001',
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-contact-api',
        ]);

        $response = $this->postJson('/10.5880/contact.api.001/test-contact-api/contact', []);

        $response->assertStatus(422);
    });

    it('rejects contact form with invalid email', function (): void {
        /** @var TestCase $this */
        $resource = Resource::factory()->create([
            'doi' => '10.5880/contact.email.001',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => '10.5880/contact.email.001',
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-contact-email',
        ]);

        $response = $this->postJson('/10.5880/contact.email.001/test-contact-email/contact', [
            'sender_name' => 'Test User',
            'sender_email' => 'invalid-email',
            'message' => 'This is a test message with more than ten characters.',
        ]);

        $response->assertStatus(422);
    });

    it('rejects contact form with short message', function (): void {
        /** @var TestCase $this */
        $resource = Resource::factory()->create([
            'doi' => '10.5880/contact.short.001',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => '10.5880/contact.short.001',
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-contact-short',
        ]);

        $response = $this->postJson('/10.5880/contact.short.001/test-contact-short/contact', [
            'sender_name' => 'Test User',
            'sender_email' => 'test@example.com',
            'message' => 'Short',
        ]);

        $response->assertStatus(422);
    });
});

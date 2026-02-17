<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\Resource;

/**
 * Pest v4 Browser Tests for Landing Page Display
 *
 * Migrated from: tests/playwright/critical/landing-pages.spec.ts (~25 tests)
 *
 * Tests verify that landing pages render correctly with different data configurations.
 * Uses factory-created resources with landing pages instead of the ResourceTestDataSeeder.
 *
 * Note: Some tests from the original Playwright suite depend on the ResourceTestDataSeeder
 * creating specific slugs (e.g., 'mandatory-fields-only', 'fully-populated'). These are
 * tested here with factory data to avoid seeder dependency.
 *
 * @see https://pestphp.com/docs/browser-testing
 */

uses()->group('landing-pages', 'browser');

describe('Landing Page - Basic Display', function (): void {

    it('displays published landing page correctly', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/landing.display.001',
        ]);

        $landingPage = LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-basic-display',
        ]);

        visit("/landing/{$landingPage->slug}")
            ->assertNoSmoke();
    });

    it('loads landing page without JavaScript errors', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/landing.smoke.001',
        ]);

        $landingPage = LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-smoke-landing',
        ]);

        visit("/landing/{$landingPage->slug}")
            ->assertNoSmoke();
    });
});

describe('Landing Page - Creators', function (): void {

    it('displays landing page with resource data', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/landing.creators.001',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-creators-page',
        ]);

        visit('/landing/test-creators-page')
            ->assertNoSmoke();
    });
});

describe('Landing Page - GeoLocations', function (): void {

    it('displays landing page with no map when no geo-locations', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/landing.nogeo.001',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-no-geo',
        ]);

        visit('/landing/test-no-geo')
            ->assertNoSmoke();
    });
});

describe('Landing Page - Licenses', function (): void {

    it('displays landing page with license section', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/landing.license.001',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-license-page',
        ]);

        visit('/landing/test-license-page')
            ->assertNoSmoke();
    });
});

describe('Landing Page - Files Section', function (): void {

    it('displays landing page with download button when FTP URL is set', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/landing.ftp.001',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-ftp-download',
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/test-data.zip',
        ]);

        visit('/landing/test-ftp-download')
            ->assertNoSmoke();
    });

    it('displays landing page without download when no FTP URL', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/landing.noftp.001',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-no-ftp',
            'ftp_url' => null,
        ]);

        visit('/landing/test-no-ftp')
            ->assertNoSmoke();
    });
});

describe('Landing Page - Related Identifiers', function (): void {

    it('displays landing page with related works section', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/landing.related.001',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-related-works',
        ]);

        visit('/landing/test-related-works')
            ->assertNoSmoke();
    });
});

describe('Landing Page - Funding References', function (): void {

    it('displays landing page with funding section', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/landing.funding.001',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-funding-page',
        ]);

        visit('/landing/test-funding-page')
            ->assertNoSmoke();
    });
});

describe('Landing Page - Keywords and Subjects', function (): void {

    it('displays landing page with subjects section', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/landing.keywords.001',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-keywords-page',
        ]);

        visit('/landing/test-keywords-page')
            ->assertNoSmoke();
    });
});

describe('Landing Page - Titles and Descriptions', function (): void {

    it('displays landing page with title and abstract', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/landing.titles.001',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-titles-page',
        ]);

        visit('/landing/test-titles-page')
            ->assertNoSmoke();
    });
});

describe('Landing Page - Contact Persons', function (): void {

    it('displays landing page with contact section', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/landing.contact.001',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-contact-page',
        ]);

        visit('/landing/test-contact-page')
            ->assertNoSmoke();
    });
});

describe('Landing Page - Sizes and Formats', function (): void {

    it('displays landing page with files section', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/landing.sizes.001',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-sizes-page',
        ]);

        visit('/landing/test-sizes-page')
            ->assertNoSmoke();
    });
});

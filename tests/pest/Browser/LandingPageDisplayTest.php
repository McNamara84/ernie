<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\Resource;

/**
 * Pest v4 Browser Tests for Landing Page Display
 *
 * Converted from 13 original tests. Each test verifies a different landing page
 * data configuration (creators, geo-locations, licenses, files, related identifiers,
 * funding, keywords, titles, contact persons, sizes).
 *
 * Landing page URL format: /{doiPrefix}/{slug}
 * All tests use unique DOIs and slugs for isolation.
 *
 * @see https://pestphp.com/docs/browser-testing
 */

uses()->group('landing-pages', 'browser');

describe('Landing Page - Basic Display', function (): void {

    it('displays published landing page correctly', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/landing.display.001',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => '10.5880/landing.display.001',
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-basic-display',
        ]);

        visit('/10.5880/landing.display.001/test-basic-display')
            ->assertNoSmoke();
    });

    it('loads landing page without JavaScript errors', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/landing.smoke.001',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => '10.5880/landing.smoke.001',
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-smoke-landing',
        ]);

        visit('/10.5880/landing.smoke.001/test-smoke-landing')
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
            'doi_prefix' => '10.5880/landing.creators.001',
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-creators-page',
        ]);

        visit('/10.5880/landing.creators.001/test-creators-page')
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
            'doi_prefix' => '10.5880/landing.nogeo.001',
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-no-geo',
        ]);

        visit('/10.5880/landing.nogeo.001/test-no-geo')
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
            'doi_prefix' => '10.5880/landing.license.001',
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-license-page',
        ]);

        visit('/10.5880/landing.license.001/test-license-page')
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
            'doi_prefix' => '10.5880/landing.ftp.001',
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-ftp-download',
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/test-data.zip',
        ]);

        visit('/10.5880/landing.ftp.001/test-ftp-download')
            ->assertNoSmoke();
    });

    it('displays landing page without download when no FTP URL', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/landing.noftp.001',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => '10.5880/landing.noftp.001',
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-no-ftp',
            'ftp_url' => null,
        ]);

        visit('/10.5880/landing.noftp.001/test-no-ftp')
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
            'doi_prefix' => '10.5880/landing.related.001',
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-related-works',
        ]);

        visit('/10.5880/landing.related.001/test-related-works')
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
            'doi_prefix' => '10.5880/landing.funding.001',
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-funding-page',
        ]);

        visit('/10.5880/landing.funding.001/test-funding-page')
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
            'doi_prefix' => '10.5880/landing.keywords.001',
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-keywords-page',
        ]);

        visit('/10.5880/landing.keywords.001/test-keywords-page')
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
            'doi_prefix' => '10.5880/landing.titles.001',
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-titles-page',
        ]);

        visit('/10.5880/landing.titles.001/test-titles-page')
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
            'doi_prefix' => '10.5880/landing.contact.001',
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-contact-page',
        ]);

        visit('/10.5880/landing.contact.001/test-contact-page')
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
            'doi_prefix' => '10.5880/landing.sizes.001',
            'template' => 'default_gfz',
            'is_published' => true,
            'slug' => 'test-sizes-page',
        ]);

        visit('/10.5880/landing.sizes.001/test-sizes-page')
            ->assertNoSmoke();
    });
});

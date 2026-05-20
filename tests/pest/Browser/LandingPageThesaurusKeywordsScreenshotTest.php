<?php

declare(strict_types=1);

use App\Models\Description;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\Subject;
use App\Models\Title;

describe('Landing Page Thesaurus Keywords Screenshot', function (): void {
    it('captures a published landing page with thesaurus keywords', function (): void {
        $resource = Resource::factory()
            ->withDoi('10.5880/browser.thesaurus-keywords')
            ->withPublicationYear(2026)
            ->create();

        Title::factory()->create([
            'resource_id' => $resource->id,
            'value' => 'Browser Screenshot: Thesaurus Keywords Landing Page',
        ]);

        Description::factory()->abstract()->create([
            'resource_id' => $resource->id,
            'value' => 'This browser-test fixture exists purely to render a landing page with visible thesaurus keyword badges.',
        ]);

        ResourceCreator::factory()->create([
            'resource_id' => $resource->id,
            'position' => 1,
        ]);

        Subject::factory()->create([
            'resource_id' => $resource->id,
            'value' => 'SEISMOLOGY',
            'subject_scheme' => 'Science Keywords',
            'scheme_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
            'value_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/science-seismology',
            'breadcrumb_path' => 'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY',
        ]);

        Subject::factory()->create([
            'resource_id' => $resource->id,
            'value' => 'Rock mechanics',
            'subject_scheme' => 'EPOS MSL vocabulary',
            'scheme_uri' => 'https://epos-msl.uu.nl/voc/',
            'value_uri' => 'https://epos-msl.uu.nl/voc/rock-mechanics',
            'breadcrumb_path' => 'Experimental methods > Rock mechanics',
        ]);

        Subject::factory()->create([
            'resource_id' => $resource->id,
            'value' => 'Air pollution',
            'subject_scheme' => 'GEMET - GEneral Multilingual Environmental Thesaurus',
            'scheme_uri' => 'http://www.eionet.europa.eu/gemet/',
            'value_uri' => 'http://www.eionet.europa.eu/gemet/concept/15154',
            'breadcrumb_path' => 'Environment > Air pollution',
        ]);

        Subject::factory()->create([
            'resource_id' => $resource->id,
            'value' => 'Additional free keyword',
            'subject_scheme' => null,
            'scheme_uri' => null,
            'value_uri' => null,
            'breadcrumb_path' => null,
        ]);

        $landingPage = LandingPage::factory()
            ->published()
            ->withDoi('10.5880/browser.thesaurus-keywords')
            ->create([
                'resource_id' => $resource->id,
                'slug' => 'browser-thesaurus-keywords',
                'template' => 'default_gfz',
            ]);

        $browserUrl = parse_url($landingPage->public_url, PHP_URL_PATH);

        if (! is_string($browserUrl) || $browserUrl === '') {
            $this->markTestSkipped('Landing page path not available');
        }

        visit($browserUrl)
            ->assertNoSmoke()
            ->waitForText('Keywords')
            ->waitForText('EARTH SCIENCE > SOLID EARTH > SEISMOLOGY')
            ->assertScreenshotMatches();
    });
});
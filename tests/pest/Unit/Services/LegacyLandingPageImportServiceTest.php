<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Models\LandingPage;
use App\Models\LandingPageDomain;
use App\Models\LandingPageFile;
use App\Models\LandingPageLink;
use App\Models\Resource;
use App\Services\LegacyLandingPageImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

describe('LegacyLandingPageImportService', function () {
    it('stores the first legacy file as primary download and remaining files as labelled links', function () {
        Cache::put(CacheKey::LANDING_PAGE_DOWNLOAD_URL_SUGGESTIONS->key(), ['urls' => ['stale']]);

        $resource = Resource::factory()->create(['doi' => '10.5880/landing.links']);

        $landingPage = (new LegacyLandingPageImportService)->createForResource(
            resource: $resource,
            fileEntries: [
                [
                    'url' => 'https://datapub.gfz.de/primary.zip',
                    'label' => 'Primary package',
                    'visible' => 'public',
                ],
                [
                    'url' => 'https://datapub.gfz.de/additional.zip',
                    'label' => 'Additional table',
                    'visible' => 'public',
                ],
            ],
            isPublished: true,
        );

        expect($landingPage)->not->toBeNull()
            ->and($landingPage->ftp_url)->toBe('https://datapub.gfz.de/primary.zip')
            ->and($landingPage->is_published)->toBeTrue()
            ->and($landingPage->published_at)->not->toBeNull();

        $links = LandingPageLink::query()->where('landing_page_id', $landingPage->id)->get();
        expect($links)->toHaveCount(1)
            ->and($links[0]->url)->toBe('https://datapub.gfz.de/additional.zip')
            ->and($links[0]->label)->toBe('Additional table')
            ->and(LandingPageFile::query()->where('landing_page_id', $landingPage->id)->count())->toBe(0)
            ->and(Cache::get(CacheKey::LANDING_PAGE_DOWNLOAD_URL_SUGGESTIONS->key()))->toBeNull();
    });

    it('creates an unpublished draft landing page without a download URL when requested for an empty pending import', function () {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/landing.empty',
            'force_review_status' => true,
        ]);

        $landingPage = (new LegacyLandingPageImportService)->createForResource(
            resource: $resource,
            fileEntries: [],
            isPublished: false,
            createWhenEmpty: true,
        );

        expect($landingPage)->not->toBeNull()
            ->and($landingPage->ftp_url)->toBeNull()
            ->and($landingPage->downloads_unavailable)->toBeTrue()
            ->and($landingPage->is_published)->toBeFalse()
            ->and($resource->fresh(['landingPage'])->publicStatus())->toBe('review');
    });

    it('clears downloads unavailable when legacy files are synced later', function () {
        $resource = Resource::factory()->create(['doi' => '10.5880/landing.empty.then.files']);
        $landingPage = LandingPage::factory()->downloadsUnavailable()->draft()->create([
            'resource_id' => $resource->id,
            'ftp_url' => null,
        ]);

        $result = (new LegacyLandingPageImportService)->syncMissingFileEntries(
            resource: $resource,
            fileEntries: [
                ['url' => 'https://datapub.gfz.de/now-available.zip', 'label' => 'Now available', 'visible' => 'public'],
            ],
            isPublished: true,
        );

        $landingPage = $landingPage->fresh();

        expect($result['changed'])->toBeTrue()
            ->and($result['ftp_url_added'])->toBeTrue()
            ->and($landingPage->ftp_url)->toBe('https://datapub.gfz.de/now-available.zip')
            ->and($landingPage->downloads_unavailable)->toBeFalse();
    });

    it('creates a landing page while syncing missing legacy files for an existing resource', function () {
        $resource = Resource::factory()->create(['doi' => '10.5880/landing.sync.create']);

        $result = (new LegacyLandingPageImportService)->syncMissingFileEntries(
            resource: $resource,
            fileEntries: [
                ['url' => 'https://datapub.gfz.de/sync-primary.zip', 'label' => 'Primary file', 'visible' => 'public'],
                ['url' => 'https://datapub.gfz.de/sync-extra.zip', 'label' => 'Extra file', 'visible' => 'public'],
            ],
            isPublished: true,
        );

        $landingPage = $resource->fresh(['landingPage.links'])->landingPage;

        expect($result['changed'])->toBeTrue()
            ->and($result['created'])->toBeTrue()
            ->and($result['ftp_url_added'])->toBeTrue()
            ->and($result['links_added'])->toBe(1)
            ->and($landingPage)->not->toBeNull()
            ->and($landingPage->ftp_url)->toBe('https://datapub.gfz.de/sync-primary.zip')
            ->and($landingPage->is_published)->toBeTrue()
            ->and($landingPage->links)->toHaveCount(1)
            ->and($landingPage->links[0]->url)->toBe('https://datapub.gfz.de/sync-extra.zip');
    });

    it('fills an empty primary download URL and appends only missing legacy links', function () {
        Cache::put(CacheKey::LANDING_PAGE_DOWNLOAD_URL_SUGGESTIONS->key(), ['urls' => ['stale']]);

        $resource = Resource::factory()->create(['doi' => '10.5880/landing.sync.fill']);
        $landingPage = LandingPage::factory()->draft()->create([
            'resource_id' => $resource->id,
            'ftp_url' => null,
            'is_published' => false,
            'published_at' => null,
        ]);
        $landingPage->links()->create([
            'url' => 'https://datapub.gfz.de/already-linked.zip',
            'label' => 'Already linked',
            'position' => 0,
        ]);

        $result = (new LegacyLandingPageImportService)->syncMissingFileEntries(
            resource: $resource,
            fileEntries: [
                ['url' => 'https://datapub.gfz.de/new-primary.zip', 'label' => 'Primary file', 'visible' => 'public'],
                ['url' => 'https://datapub.gfz.de/already-linked.zip', 'label' => 'Duplicate file', 'visible' => 'public'],
                ['url' => 'https://datapub.gfz.de/new-extra.zip', 'label' => 'New extra', 'visible' => 'public'],
            ],
            isPublished: true,
        );

        $landingPage = $landingPage->fresh(['links']);

        expect($result['changed'])->toBeTrue()
            ->and($result['created'])->toBeFalse()
            ->and($result['ftp_url_added'])->toBeTrue()
            ->and($result['links_added'])->toBe(1)
            ->and($landingPage->ftp_url)->toBe('https://datapub.gfz.de/new-primary.zip')
            ->and($landingPage->is_published)->toBeFalse()
            ->and($landingPage->published_at)->toBeNull()
            ->and($landingPage->links)->toHaveCount(2)
            ->and($landingPage->links->pluck('url')->all())->toBe([
                'https://datapub.gfz.de/already-linked.zip',
                'https://datapub.gfz.de/new-extra.zip',
            ])
            ->and(Cache::get(CacheKey::LANDING_PAGE_DOWNLOAD_URL_SUGGESTIONS->key()))->toBeNull();
    });

    it('preserves an existing primary download URL and syncs legacy files as additional links', function () {
        $resource = Resource::factory()->create(['doi' => '10.5880/landing.sync.preserve']);
        $landingPage = LandingPage::factory()->published()->create([
            'resource_id' => $resource->id,
            'ftp_url' => 'https://curated.example.org/download.zip',
        ]);

        $result = (new LegacyLandingPageImportService)->syncMissingFileEntries(
            resource: $resource,
            fileEntries: [
                ['url' => 'https://datapub.gfz.de/legacy-primary.zip', 'visible' => 'public'],
                ['url' => 'https://datapub.gfz.de/legacy-extra.zip', 'visible' => 'public'],
            ],
            isPublished: false,
        );

        $landingPage = $landingPage->fresh(['links']);

        expect($result['changed'])->toBeTrue()
            ->and($result['created'])->toBeFalse()
            ->and($result['ftp_url_added'])->toBeFalse()
            ->and($result['links_added'])->toBe(2)
            ->and($landingPage->ftp_url)->toBe('https://curated.example.org/download.zip')
            ->and($landingPage->is_published)->toBeTrue()
            ->and($landingPage->links->pluck('url')->all())->toBe([
                'https://datapub.gfz.de/legacy-primary.zip',
                'https://datapub.gfz.de/legacy-extra.zip',
            ])
            ->and($landingPage->links->pluck('label')->all())->toBe([
                'Download 2',
                'Download 3',
            ]);
    });
    it('does not add legacy download URLs to external landing pages', function () {
        $resource = Resource::factory()->create(['doi' => '10.14470/external.sync']);
        $domain = LandingPageDomain::factory()->withDomain('https://geofon.gfz.de/')->create();
        $landingPage = LandingPage::factory()->external()->published()->create([
            'resource_id' => $resource->id,
            'external_domain_id' => $domain->id,
            'external_path' => 'waveform/archive/network.php?ncode=SYNC',
            'ftp_url' => null,
        ]);

        $result = (new LegacyLandingPageImportService)->syncMissingFileEntries(
            resource: $resource,
            fileEntries: [
                ['url' => 'https://datapub.gfz.de/legacy-file.zip', 'label' => 'Legacy file', 'visible' => 'public'],
            ],
            isPublished: true,
        );

        $landingPage = $landingPage->fresh(['links']);

        expect($result['changed'])->toBeFalse()
            ->and($result['created'])->toBeFalse()
            ->and($result['ftp_url_added'])->toBeFalse()
            ->and($result['links_added'])->toBe(0)
            ->and($landingPage->template)->toBe('external')
            ->and($landingPage->ftp_url)->toBeNull()
            ->and($landingPage->links)->toHaveCount(0);
    });
});

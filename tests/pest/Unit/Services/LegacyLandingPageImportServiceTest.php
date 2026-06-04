<?php

declare(strict_types=1);

use App\Enums\CacheKey;
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
            ->and($landingPage->is_published)->toBeFalse()
            ->and($resource->fresh(['landingPage'])->publicStatus())->toBe('review');
    });
});

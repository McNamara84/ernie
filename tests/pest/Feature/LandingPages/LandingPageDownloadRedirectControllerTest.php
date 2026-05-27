<?php

declare(strict_types=1);

use App\Http\Controllers\LandingPageDownloadRedirectController;
use App\Models\LandingPage;
use App\Models\LandingPageDailyStatistic;
use App\Models\Resource;

covers(LandingPageDownloadRedirectController::class);

uses()->group('landing-pages', 'downloads');

beforeEach(function () {
    $this->resource = Resource::factory()->create([
        'doi' => '10.5880/test.downloads.001',
    ]);
});

function dailyDownloadCount(LandingPage $landingPage): ?int
{
    return LandingPageDailyStatistic::query()
        ->where('landing_page_id', $landingPage->id)
        ->whereDate('statistic_date', now()->toDateString())
        ->value('file_download_click_count');
}

describe('primary download redirect', function () {
    test('redirects and counts clicks for published landing pages', function () {
        $landingPage = LandingPage::factory()->published()->create([
            'resource_id' => $this->resource->id,
            'doi_prefix' => '10.5880/test.downloads.001',
            'slug' => 'primary-download',
            'ftp_url' => 'https://downloads.example.org/archive.zip',
        ]);

        $this->get(route('landing-page.download.primary', ['landingPage' => $landingPage->id]))
            ->assertRedirect('https://downloads.example.org/archive.zip')
            ->assertStatus(302);

        expect(dailyDownloadCount($landingPage))->toBe(1);
    });

    test('returns 404 for draft landing pages', function () {
        $landingPage = LandingPage::factory()->draft()->create([
            'resource_id' => $this->resource->id,
            'doi_prefix' => '10.5880/test.downloads.001',
            'slug' => 'draft-primary-download',
            'ftp_url' => 'https://downloads.example.org/archive.zip',
        ]);

        $this->get(route('landing-page.download.primary', ['landingPage' => $landingPage->id]))
            ->assertNotFound();

        expect(dailyDownloadCount($landingPage))->toBeNull();
    });

    test('does not count known ai bots for primary download redirects', function () {
        config([
            'bot_protection.enabled' => true,
            'bot_protection.ai_user_agents' => ['GPTBot'],
        ]);

        $landingPage = LandingPage::factory()->published()->create([
            'resource_id' => $this->resource->id,
            'doi_prefix' => '10.5880/test.downloads.001',
            'slug' => 'primary-download-ai-bot',
            'ftp_url' => 'https://downloads.example.org/archive.zip',
        ]);

        $this->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.50',
            'HTTP_USER_AGENT' => 'GPTBot',
        ])->get(route('landing-page.download.primary', ['landingPage' => $landingPage->id]))
            ->assertRedirect('https://downloads.example.org/archive.zip');

        expect(dailyDownloadCount($landingPage))->toBeNull();
    });
});

describe('file download redirect', function () {
    test('redirects and counts clicks for imported landing page files', function () {
        $landingPage = LandingPage::factory()->published()->create([
            'resource_id' => $this->resource->id,
            'doi_prefix' => '10.5880/test.downloads.001',
            'slug' => 'file-download',
        ]);

        $file = $landingPage->files()->create([
            'url' => 'https://downloads.example.org/supplement.csv',
            'position' => 0,
        ]);

        $this->get(route('landing-page.download.file', ['landingPage' => $landingPage->id, 'landingPageFile' => $file->id]))
            ->assertRedirect('https://downloads.example.org/supplement.csv')
            ->assertStatus(302);

        expect(dailyDownloadCount($landingPage))->toBe(1);
    });

    test('returns 404 when the file does not belong to the landing page', function () {
        $landingPage = LandingPage::factory()->published()->create([
            'resource_id' => $this->resource->id,
            'doi_prefix' => '10.5880/test.downloads.001',
            'slug' => 'mismatched-file-download',
        ]);

        $otherLandingPage = LandingPage::factory()->published()->create([
            'resource_id' => Resource::factory()->create(['doi' => '10.5880/test.downloads.002'])->id,
            'doi_prefix' => '10.5880/test.downloads.002',
            'slug' => 'other-landing-page',
        ]);

        $file = $otherLandingPage->files()->create([
            'url' => 'https://downloads.example.org/other.csv',
            'position' => 0,
        ]);

        $this->get(route('landing-page.download.file', ['landingPage' => $landingPage->id, 'landingPageFile' => $file->id]))
            ->assertNotFound();

        expect(dailyDownloadCount($landingPage))->toBeNull();
        expect(dailyDownloadCount($otherLandingPage))->toBeNull();
    });
});
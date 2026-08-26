<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\Resource;
use App\Services\LegacyDownloadLabelBackfillService;
use App\Services\MetaworksDownloadUrlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

it('dry-runs and applies safe legacy download label changes without overwriting curated labels', function () {
    $doi = '10.5880/download.labels.backfill';
    $resource = Resource::factory()->create(['doi' => $doi]);
    $landingPage = LandingPage::factory()->published()->create([
        'resource_id' => $resource->id,
        'template' => 'default_gfz',
        'ftp_url' => 'https://downloads.example.org/primary',
        'primary_download_label' => 'https://downloads.example.org/primary',
    ]);
    $file = $landingPage->files()->create([
        'url' => 'https://downloads.example.org/legacy-file',
        'label' => null,
        'position' => 0,
    ]);
    $automaticLink = $landingPage->links()->create([
        'url' => 'https://services.example.org/calculation',
        'label' => 'https://services.example.org/calculation',
        'position' => 0,
    ]);
    $curatedLink = $landingPage->links()->create([
        'url' => 'https://services.example.org/visualisation',
        'label' => 'Carefully curated visualisation label',
        'position' => 1,
    ]);

    $legacyResult = [
        'files' => [
            [
                'url' => 'https://downloads.example.org/primary',
                'label' => 'Download via GFZ Data Services',
                'source_name' => 'https://downloads.example.org/primary',
                'visible' => 'public',
            ],
            [
                'url' => 'https://downloads.example.org/legacy-file',
                'label' => 'Download model data',
                'source_name' => 'legacy-file.zip',
                'visible' => 'public',
            ],
            [
                'url' => 'https://services.example.org/calculation',
                'label' => 'Calculation service',
                'source_name' => 'https://services.example.org/calculation',
                'visible' => 'public',
            ],
            [
                'url' => 'https://services.example.org/visualisation',
                'label' => 'Visualisation service',
                'source_name' => 'https://services.example.org/visualisation',
                'visible' => 'public',
            ],
        ],
        'allPublic' => true,
        'resourceFound' => true,
        'hasFileRows' => true,
        'resourcePublicStatus' => 'published',
    ];

    $legacyFiles = Mockery::mock(MetaworksDownloadUrlService::class);
    $legacyFiles->shouldReceive('lookupFileEntries')->with($doi)->times(3)->andReturn($legacyResult);
    app()->instance(MetaworksDownloadUrlService::class, $legacyFiles);

    $reportPath = storage_path('framework/testing/download-label-backfill.csv');
    File::delete($reportPath);

    $this->artisan('landing-pages:backfill-download-labels', ['--doi' => [$doi], '--report' => $reportPath])
        ->expectsOutputToContain('Dry run only')
        ->expectsOutputToContain('Backfill report written')
        ->assertSuccessful();

    expect(File::exists($reportPath))->toBeTrue()
        ->and(File::get($reportPath))->toContain('would_update');
    File::delete($reportPath);

    expect($landingPage->fresh()->primary_download_label)->toBe('https://downloads.example.org/primary')
        ->and($file->fresh()->label)->toBeNull()
        ->and($automaticLink->fresh()->label)->toBe('https://services.example.org/calculation')
        ->and($curatedLink->fresh()->label)->toBe('Carefully curated visualisation label');

    $this->artisan('landing-pages:backfill-download-labels', ['--apply' => true, '--doi' => [$doi]])
        ->expectsOutputToContain('Download label backfill applied')
        ->assertSuccessful();

    expect($landingPage->fresh()->primary_download_label)->toBe('Download via GFZ Data Services')
        ->and($file->fresh()->label)->toBe('Download model data')
        ->and($automaticLink->fresh()->label)->toBe('Calculation service')
        ->and($curatedLink->fresh()->label)->toBe('Carefully curated visualisation label');

    $this->artisan('landing-pages:backfill-download-labels', ['--apply' => true, '--doi' => [$doi]])
        ->assertSuccessful();

    expect($landingPage->fresh()->primary_download_label)->toBe('Download via GFZ Data Services')
        ->and($file->fresh()->label)->toBe('Download model data')
        ->and($automaticLink->fresh()->label)->toBe('Calculation service')
        ->and($curatedLink->fresh()->label)->toBe('Carefully curated visualisation label');
});

it('honours resume, limit, and DOI filters while reporting unmatched URLs in dry-run mode', function () {
    $firstResource = Resource::factory()->create(['doi' => '10.5880/download.labels.first']);
    $first = LandingPage::factory()->published()->create([
        'resource_id' => $firstResource->id,
        'ftp_url' => 'https://downloads.example.org/first',
    ]);

    $selectedDoi = '10.5880/download.labels.selected';
    $selectedResource = Resource::factory()->create(['doi' => $selectedDoi]);
    $selected = LandingPage::factory()->published()->create([
        'resource_id' => $selectedResource->id,
        'ftp_url' => 'https://downloads.example.org/unmatched-primary',
    ]);
    $file = $selected->files()->create([
        'url' => 'https://downloads.example.org/unmatched-file',
        'position' => 0,
    ]);
    $generatedLink = $selected->links()->create([
        'url' => 'https://services.example.org/calculation',
        'label' => 'Download (2)',
        'position' => 0,
    ]);

    $thirdResource = Resource::factory()->create(['doi' => '10.5880/download.labels.third']);
    LandingPage::factory()->published()->create([
        'resource_id' => $thirdResource->id,
        'ftp_url' => 'https://downloads.example.org/third',
    ]);

    $legacyFiles = Mockery::mock(MetaworksDownloadUrlService::class);
    $legacyFiles->shouldReceive('lookupFileEntries')->once()->with($selectedDoi)->andReturn([
        'files' => [[
            'url' => 'https://services.example.org/calculation',
            'label' => 'Calculation service',
            'source_name' => 'legacy-calculation',
            'visible' => 'public',
        ]],
        'allPublic' => true,
        'resourceFound' => true,
        'hasFileRows' => true,
        'resourcePublicStatus' => 'published',
    ]);

    $result = (new LegacyDownloadLabelBackfillService($legacyFiles))->run(
        apply: false,
        afterId: $first->id,
        limit: 1,
        dois: ['  '.$selectedDoi.'  '],
    );

    expect($result['scanned'])->toBe(1)
        ->and($result['primary_labels_updated'])->toBe(0)
        ->and($result['file_labels_updated'])->toBe(0)
        ->and($result['link_labels_updated'])->toBe(1)
        ->and($result['unmatched_urls'])->toBe(2)
        ->and($result['errors'])->toBe(0)
        ->and($result['records'][0]['status'])->toBe('would_update')
        ->and($file->fresh()->label)->toBeNull()
        ->and($generatedLink->fresh()->label)->toBe('Download (2)');
});

it('fails safely when the legacy database is unavailable', function () {
    $doi = '10.5880/download.labels.unavailable';
    $resource = Resource::factory()->create(['doi' => $doi]);
    LandingPage::factory()->published()->create([
        'resource_id' => $resource->id,
        'template' => 'default_gfz',
        'ftp_url' => 'https://downloads.example.org/primary',
    ]);

    $legacyFiles = Mockery::mock(MetaworksDownloadUrlService::class);
    $legacyFiles->shouldReceive('lookupFileEntries')->once()->andThrow(new RuntimeException('Legacy connection failed'));
    app()->instance(MetaworksDownloadUrlService::class, $legacyFiles);

    $this->artisan('landing-pages:backfill-download-labels', ['--doi' => [$doi]])
        ->expectsOutputToContain('Dry run only')
        ->assertFailed();
});

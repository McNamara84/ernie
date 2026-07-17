<?php

declare(strict_types=1);

use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\LandingPageDomain;
use App\Models\LandingPageFile;
use App\Models\LandingPageLink;
use App\Models\Resource;
use App\Services\Assessment\FairImprovementContextFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

it('builds normalized state from eager-loaded Resource relations without queries', function (): void {
    config(['app.url' => 'https://ernie.test']);

    $resource = new Resource;
    $resource->forceFill([
        'doi' => '10.5880/example',
        'updated_at' => Carbon::parse('2026-07-17 10:00:00'),
    ]);

    $landingPage = new LandingPage;
    $landingPage->forceFill([
        'template' => 'default_gfz',
        'ftp_url' => 'https://files.example.test/data.zip',
        'downloads_unavailable' => false,
        'is_published' => true,
        'updated_at' => Carbon::parse('2026-07-17 10:01:00'),
        'published_at' => Carbon::parse('2026-07-17 10:01:00'),
    ]);

    $file = new LandingPageFile;
    $file->forceFill([
        'url' => 'https://files.example.test/data.csv',
        'updated_at' => Carbon::parse('2026-07-17 10:02:00'),
    ]);
    $link = new LandingPageLink;
    $link->forceFill([
        'url' => 'https://repository.example.test/project',
        'updated_at' => Carbon::parse('2026-07-17 10:03:00'),
    ]);

    $landingPage->setRelation('files', new Collection([$file]));
    $landingPage->setRelation('links', new Collection([$link]));
    $landingPage->setRelation('externalDomain', null);

    $igsnMetadata = new IgsnMetadata;
    $igsnMetadata->forceFill([
        'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        'updated_at' => Carbon::parse('2026-07-17 10:04:00'),
    ]);

    $resource->setRelation('landingPage', $landingPage);
    $resource->setRelation('igsnMetadata', $igsnMetadata);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $context = (new FairImprovementContextFactory)->fromResource(
        resource: $resource,
        assessedAt: Carbon::parse('2026-07-17 10:05:00'),
        assessedIdentifier: '10.5880/example',
    );

    expect(DB::getQueryLog())->toBe([])
        ->and($context->hasDoi)->toBeTrue()
        ->and($context->landingPageExists)->toBeTrue()
        ->and($context->landingPagePublished)->toBeTrue()
        ->and($context->landingPageIsInternal)->toBeTrue()
        ->and($context->landingPageUsesHttps)->toBeTrue()
        ->and($context->hasConfiguredDownloads)->toBeTrue()
        ->and($context->hasIgsnMetadata)->toBeTrue()
        ->and($context->igsnRegistered)->toBeTrue()
        ->and($context->latestRelevantChangeAt?->format('Y-m-d H:i:s'))->toBe('2026-07-17 10:04:00')
        ->and($context->requiresReassessment())->toBeFalse();
});

it('uses loaded external-domain state to detect a non-HTTPS target', function (): void {
    $resource = new Resource;
    $resource->forceFill(['doi' => '10.5880/example']);

    $landingPage = new LandingPage;
    $landingPage->forceFill([
        'template' => 'external',
        'external_domain_id' => 1,
        'external_path' => 'sample',
        'downloads_unavailable' => true,
        'is_published' => true,
    ]);
    $domain = new LandingPageDomain;
    $domain->forceFill(['domain' => 'http://samples.example.test/']);

    $landingPage->setRelation('files', new Collection);
    $landingPage->setRelation('links', new Collection);
    $landingPage->setRelation('externalDomain', $domain);
    $resource->setRelation('landingPage', $landingPage);
    $resource->setRelation('igsnMetadata', null);

    $context = (new FairImprovementContextFactory)->fromResource(
        $resource,
        assessedAt: null,
        assessedIdentifier: '10.5880/example',
    );

    expect($context)
        ->landingPageIsInternal->toBeFalse()
        ->landingPageUsesHttps->toBeFalse()
        ->hasConfiguredDownloads->toBeFalse();
});

it('treats a non-string internal application URL as non-HTTPS', function (): void {
    $originalUrl = config('app.url');
    config(['app.url' => null]);

    $resource = new Resource;
    $landingPage = new LandingPage;
    $landingPage->forceFill([
        'template' => 'default_gfz',
        'downloads_unavailable' => true,
        'is_published' => true,
    ]);
    $landingPage->setRelation('files', new Collection);
    $landingPage->setRelation('links', new Collection);
    $landingPage->setRelation('externalDomain', null);
    $resource->setRelation('landingPage', $landingPage);
    $resource->setRelation('igsnMetadata', null);

    $context = (new FairImprovementContextFactory)->fromResource($resource, null);

    config(['app.url' => $originalUrl]);

    expect($context->landingPageUsesHttps)->toBeFalse();
});

it('does not count hidden download configuration as public', function (): void {
    $resource = new Resource;
    $resource->setRelation('igsnMetadata', null);

    $landingPage = new LandingPage;
    $landingPage->forceFill([
        'template' => 'default_gfz',
        'ftp_url' => 'https://files.example.test/data.zip',
        'downloads_unavailable' => true,
        'is_published' => false,
    ]);
    $landingPage->setRelation('files', new Collection);
    $landingPage->setRelation('links', new Collection);
    $landingPage->setRelation('externalDomain', null);
    $resource->setRelation('landingPage', $landingPage);

    $context = (new FairImprovementContextFactory)->fromResource($resource, null);

    expect($context->hasConfiguredDownloads)->toBeFalse();
});

it('requires the fixed relation graph instead of issuing lazy-loading queries', function (
    Resource $resource,
): void {
    expect(fn (): mixed => (new FairImprovementContextFactory)->fromResource($resource, null))
        ->toThrow(LogicException::class);
})->with([
    'missing top-level relations' => [new Resource],
    'missing nested landing-page relations' => [(function (): Resource {
        $resource = new Resource;
        $resource->setRelation('landingPage', new LandingPage);
        $resource->setRelation('igsnMetadata', null);

        return $resource;
    })()],
]);

it('marks identifier changes as stale even when timestamps do not reveal the change', function (): void {
    $resource = new Resource;
    $resource->forceFill([
        'doi' => '10.5880/current',
        'updated_at' => Carbon::parse('2026-07-17 10:00:00'),
    ]);
    $resource->setRelation('landingPage', null);
    $resource->setRelation('igsnMetadata', null);

    $context = (new FairImprovementContextFactory)->fromResource(
        resource: $resource,
        assessedAt: Carbon::parse('2026-07-17 10:01:00'),
        assessedIdentifier: '10.5880/previous',
    );

    expect($context->requiresReassessment())->toBeTrue();
});

it('passes the explicit distribution capability into the context', function (): void {
    $resource = new Resource;
    $resource->setRelation('landingPage', null);
    $resource->setRelation('igsnMetadata', null);

    $context = (new FairImprovementContextFactory)->fromResource(
        resource: $resource,
        assessedAt: null,
        machineReadableDistributionVerified: true,
    );

    expect($context->machineReadableDistributionVerified)->toBeTrue();
});

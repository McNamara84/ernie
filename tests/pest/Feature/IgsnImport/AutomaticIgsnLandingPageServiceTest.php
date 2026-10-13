<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Models\DateType;
use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Services\AutomaticIgsnLandingPageService;

it('creates a published internal IGSN landing page with automatic template inheritance', function (): void {
    $resource = Resource::factory()->create(['doi' => '10.60510/gftest001']);

    $result = app(AutomaticIgsnLandingPageService::class)->createPublished($resource);

    expect($result['created'])->toBeTrue()
        ->and($result['landing_page']->template)->toBe('default_gfz_igsn')
        ->and($result['landing_page']->landing_page_template_id)->toBeNull()
        ->and($result['landing_page']->is_published)->toBeTrue()
        ->and($result['landing_page']->published_at)->not->toBeNull()
        ->and($result['landing_page']->downloads_unavailable)->toBeTrue()
        ->and($result['landing_page']->ftp_url)->toBeNull();
});

it('is idempotent and preserves an existing landing page', function (): void {
    $resource = Resource::factory()->create(['doi' => '10.60510/gftest002']);
    $existing = LandingPage::factory()->draft()->create([
        'resource_id' => $resource->id,
        'template' => 'default_gfz_igsn',
    ]);

    $result = app(AutomaticIgsnLandingPageService::class)->createPublished($resource);

    expect($result['created'])->toBeFalse()
        ->and($result['landing_page']->is($existing))->toBeTrue()
        ->and($result['landing_page']->is_published)->toBeFalse()
        ->and(LandingPage::where('resource_id', $resource->id)->count())->toBe(1);
});

it('keeps an unregistered embargoed IGSN in tokenized draft preview', function (): void {
    $resource = Resource::factory()->create(['doi' => '10.60510/future', 'access_level' => AccessLevel::EMBARGOED]);
    IgsnMetadata::create(['resource_id' => $resource->id, 'upload_status' => IgsnMetadata::STATUS_PENDING]);
    $available = DateType::firstOrCreate(['slug' => 'Available'], ['name' => 'Available', 'is_active' => true]);
    $resource->dates()->create(['date_type_id' => $available->id, 'date_value' => '2027-01-01']);

    $result = app(AutomaticIgsnLandingPageService::class)->createPublished($resource);

    expect($result['landing_page']->is_published)->toBeFalse()
        ->and($result['landing_page']->preview_token)->not->toBeNull();
});

it('preserves publication for an already registered imported IGSN', function (): void {
    $resource = Resource::factory()->create(['doi' => '10.60510/registered', 'access_level' => AccessLevel::EMBARGOED]);
    IgsnMetadata::create(['resource_id' => $resource->id, 'upload_status' => IgsnMetadata::STATUS_REGISTERED]);

    $result = app(AutomaticIgsnLandingPageService::class)->createPublished($resource);

    expect($result['landing_page']->is_published)->toBeTrue();
});

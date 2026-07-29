<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Models\DateType;
use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\LandingPageLink;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Services\MetadataAccessContentBackfillService;

covers(MetadataAccessContentBackfillService::class);

test('audits without writes and applies only unambiguous access mappings', function (): void {
    $open = Resource::factory()->create(['access_level' => null]);
    $metadataOnly = Resource::factory()->create(['access_level' => null]);
    LandingPage::factory()->downloadsUnavailable()->create(['resource_id' => $metadataOnly->id]);

    $physicalObject = ResourceType::firstOrCreate(
        ['slug' => 'physical-object'],
        ['name' => 'Physical Object', 'is_active' => true],
    );
    $igsnOpen = Resource::factory()->create([
        'resource_type_id' => $physicalObject->id,
        'access_level' => null,
    ]);
    IgsnMetadata::create(['resource_id' => $igsnOpen->id, 'sample_access' => 'Unrestricted']);
    $igsnUnknown = Resource::factory()->create([
        'resource_type_id' => $physicalObject->id,
        'access_level' => null,
    ]);
    IgsnMetadata::create(['resource_id' => $igsnUnknown->id, 'sample_access' => 'by arrangement']);

    $service = app(MetadataAccessContentBackfillService::class);
    $dryRun = $service->run();

    expect($dryRun['dry_run'])->toBeTrue()
        ->and($dryRun['access_changes'])->toBe(3)
        ->and($dryRun['sample_access_counts'])->toMatchArray([
            'Unrestricted' => 1,
            'by arrangement' => 1,
        ])
        ->and(collect($dryRun['review'])->pluck('category'))->toContain('access_unknown_igsn')
        ->and($open->fresh()->access_level)->toBeNull();

    $applied = $service->run(apply: true);

    expect($applied['access_changes'])->toBe(3)
        ->and($open->fresh()->access_level)->toBe(AccessLevel::OPEN)
        ->and($metadataOnly->fresh()->access_level)->toBe(AccessLevel::METADATA_ONLY)
        ->and($igsnOpen->fresh()->access_level)->toBe(AccessLevel::OPEN)
        ->and($igsnUnknown->fresh()->access_level)->toBeNull()
        ->and($service->run(apply: true)['access_changes'])->toBe(0);
});

test('reports an embargoed IGSN without an Available date', function (): void {
    $physicalObject = ResourceType::firstOrCreate(
        ['slug' => 'physical-object'],
        ['name' => 'Physical Object', 'is_active' => true],
    );
    $resource = Resource::factory()->create([
        'resource_type_id' => $physicalObject->id,
        'access_level' => null,
    ]);
    IgsnMetadata::create(['resource_id' => $resource->id, 'sample_access' => 'embargoed']);

    $result = app(MetadataAccessContentBackfillService::class)->run();

    expect(collect($result['review'])->pluck('category'))
        ->toContain('embargo_missing_available_date');

    $available = DateType::firstOrCreate(
        ['slug' => 'available'],
        ['name' => 'Available', 'is_active' => true],
    );
    $resource->dates()->create(['date_type_id' => $available->id, 'start_date' => '2027-01-01']);

    expect(collect(app(MetadataAccessContentBackfillService::class)->run()['review'])->pluck('category'))
        ->not->toContain('embargo_missing_available_date');
});

test('backfills exact descriptors conservatively and is idempotent', function (): void {
    $resource = Resource::factory()->create(['access_level' => AccessLevel::OPEN]);
    $format = $resource->formats()->create(['value' => '.ZIP']);
    $size = $resource->sizes()->create(['numeric_value' => 2.5, 'unit' => 'MB']);
    $landingPage = LandingPage::factory()->create([
        'resource_id' => $resource->id,
        'ftp_url' => 'https://downloads.example.org/data.zip',
    ]);
    $service = app(MetadataAccessContentBackfillService::class);

    $dryRun = $service->run();
    expect($dryRun['format_changes'])->toBe(1)
        ->and($dryRun['size_changes'])->toBe(1)
        ->and($landingPage->fresh()->ftp_format_id)->toBeNull()
        ->and($landingPage->fresh()->ftp_size_id)->toBeNull();

    $applied = $service->run(apply: true);
    expect($applied['format_changes'])->toBe(1)
        ->and($applied['size_changes'])->toBe(1)
        ->and($landingPage->fresh()->ftp_format_id)->toBe($format->id)
        ->and($landingPage->fresh()->ftp_size_id)->toBe($size->id);

    $again = $service->run(apply: true);
    expect($again['format_changes'])->toBe(0)
        ->and($again['size_changes'])->toBe(0);
});

test('does not guess one resource size across multiple content URLs', function (): void {
    $resource = Resource::factory()->create(['access_level' => AccessLevel::OPEN]);
    $format = $resource->formats()->create(['value' => 'application/zip']);
    $resource->sizes()->create(['numeric_value' => 5, 'unit' => 'MB']);
    $landingPage = LandingPage::factory()->create([
        'resource_id' => $resource->id,
        'ftp_url' => 'https://downloads.example.org/main.zip',
    ]);
    $link = $landingPage->links()->create([
        'url' => 'https://downloads.example.org/supplement.zip',
        'label' => 'Supplement',
        'kind' => LandingPageLink::KIND_DOWNLOAD,
        'position' => 0,
    ]);

    $result = app(MetadataAccessContentBackfillService::class)->run(apply: true);

    expect($landingPage->fresh()->ftp_format_id)->toBe($format->id)
        ->and($link->fresh()->format_id)->toBe($format->id)
        ->and($landingPage->fresh()->ftp_size_id)->toBeNull()
        ->and($link->fresh()->size_id)->toBeNull()
        ->and(collect($result['review'])->pluck('category'))->toContain('digital_size_ambiguous');
});

<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

test('migration adds references and conservatively backfills access plus descriptors', function (): void {
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_07_29_000001_add_access_and_content_descriptors.php');
    $migration->down();

    $dataset = Resource::factory()->create();
    $format = $dataset->formats()->create(['value' => '.ZIP']);
    $size = $dataset->sizes()->create(['numeric_value' => 2, 'unit' => 'MB']);
    $landingPage = LandingPage::factory()->create([
        'resource_id' => $dataset->id,
        'ftp_url' => 'https://downloads.example.org/data.zip',
        'downloads_unavailable' => false,
    ]);

    $metadataOnly = Resource::factory()->create();
    LandingPage::factory()->create([
        'resource_id' => $metadataOnly->id,
        'ftp_url' => null,
        'downloads_unavailable' => true,
    ]);

    $physicalObject = ResourceType::firstOrCreate(
        ['slug' => 'physical-object'],
        ['name' => 'Physical Object', 'is_active' => true],
    );
    $igsnRestricted = Resource::factory()->create(['resource_type_id' => $physicalObject->id]);
    IgsnMetadata::create(['resource_id' => $igsnRestricted->id, 'sample_access' => 'limited']);
    $igsnUnknown = Resource::factory()->create(['resource_type_id' => $physicalObject->id]);
    IgsnMetadata::create(['resource_id' => $igsnUnknown->id, 'sample_access' => 'closed']);

    $migration->up();

    expect(Schema::hasColumns('resources', ['access_level']))->toBeTrue()
        ->and(Schema::hasColumns('landing_pages', ['ftp_format_id', 'ftp_size_id']))->toBeTrue()
        ->and(Schema::hasColumns('landing_page_files', ['format_id', 'size_id']))->toBeTrue()
        ->and(Schema::hasColumns('landing_page_links', ['format_id', 'size_id']))->toBeTrue()
        ->and($dataset->fresh()->access_level)->toBe(AccessLevel::OPEN)
        ->and($metadataOnly->fresh()->access_level)->toBe(AccessLevel::METADATA_ONLY)
        ->and($igsnRestricted->fresh()->access_level)->toBe(AccessLevel::RESTRICTED)
        ->and($igsnUnknown->fresh()->access_level)->toBeNull()
        ->and($landingPage->fresh()->ftp_format_id)->toBe($format->id)
        ->and($landingPage->fresh()->ftp_size_id)->toBe($size->id);
});

test('migration recognizes IGSN metadata when the physical object resource type is missing', function (): void {
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_07_29_000001_add_access_and_content_descriptors.php');
    $migration->down();

    ResourceType::query()->where('slug', 'physical-object')->delete();

    $dataset = Resource::factory()->create();
    $igsnRestricted = Resource::factory()->create(['resource_type_id' => $dataset->resource_type_id]);
    IgsnMetadata::create(['resource_id' => $igsnRestricted->id, 'sample_access' => 'limited']);

    $igsnUnknown = Resource::factory()->create(['resource_type_id' => $dataset->resource_type_id]);
    IgsnMetadata::create(['resource_id' => $igsnUnknown->id, 'sample_access' => 'closed']);
    $igsnUnknown->sizes()->create(['numeric_value' => 2, 'unit' => 'MB']);
    $landingPage = LandingPage::factory()->create([
        'resource_id' => $igsnUnknown->id,
        'ftp_url' => 'https://downloads.example.org/sample.zip',
        'downloads_unavailable' => true,
    ]);

    $migration->up();

    expect($dataset->fresh()->access_level)->toBe(AccessLevel::OPEN)
        ->and($igsnRestricted->fresh()->access_level)->toBe(AccessLevel::RESTRICTED)
        ->and($igsnUnknown->fresh()->access_level)->toBeNull()
        ->and($landingPage->fresh()->ftp_size_id)->toBeNull();
});

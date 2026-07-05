<?php

declare(strict_types=1);

use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\Resource;
use App\Services\DescriptionSegmentation\DescriptionSegmentationPolicy;
use App\Services\DescriptionSegmentation\DescriptionSegmentationPreviewBuilder;

covers(DescriptionSegmentationPreviewBuilder::class);

function descriptionSegmentationPreviewType(string $slug, ?string $name = null): DescriptionType
{
    return DescriptionType::firstOrCreate(
        ['slug' => $slug],
        ['name' => $name ?? $slug, 'slug' => $slug, 'is_active' => true, 'is_elmo_active' => true],
    );
}

function descriptionSegmentationPreviewText(string $seed, int $sentences = 12): string
{
    return trim(str_repeat($seed.' ', $sentences));
}

/**
 * @return array{current: array<string, mixed>, proposed: array{remaining_abstract: string, target_types: list<string>, segments: list<array<string, mixed>>}, confidence: array<string, mixed>}
 */
function descriptionSegmentationPreviewMetadata(Description $description): array
{
    $metadata = app(DescriptionSegmentationPreviewBuilder::class)->buildForDescription($description);

    if ($metadata === null) {
        throw new RuntimeException('Expected a description segmentation preview.');
    }

    $current = $metadata['current'] ?? null;
    $proposed = $metadata['proposed'] ?? null;
    $confidence = $metadata['confidence'] ?? null;

    if (! is_array($current) || ! is_array($proposed) || ! is_array($confidence)) {
        throw new RuntimeException('Expected complete description segmentation preview metadata.');
    }

    $remainingAbstract = $proposed['remaining_abstract'] ?? null;
    $targetTypes = $proposed['target_types'] ?? null;
    $segments = $proposed['segments'] ?? null;

    if (! is_string($remainingAbstract) || ! is_array($targetTypes) || ! is_array($segments)) {
        throw new RuntimeException('Expected complete proposed description segmentation metadata.');
    }

    return [
        'current' => $current,
        'proposed' => [
            'remaining_abstract' => $remainingAbstract,
            'target_types' => array_values(array_map(static fn (mixed $targetType): string => (string) $targetType, $targetTypes)),
            'segments' => array_values(array_filter($segments, static fn (mixed $segment): bool => is_array($segment))),
        ],
        'confidence' => $confidence,
    ];
}
function descriptionSegmentationPreviewDescription(string $value): Description
{
    $resource = Resource::factory()->create();

    $description = Description::create([
        'resource_id' => $resource->id,
        'description_type_id' => descriptionSegmentationPreviewType('Abstract')->id,
        'value' => $value,
        'language' => 'en',
    ]);

    return $description->load('descriptionType');
}

it('builds a multi segment preview from structural description headings', function (): void {
    $overview = descriptionSegmentationPreviewText('This legacy abstract describes the dataset purpose, study area, observation period, scientific context, and reuse scope for curators.', 14);
    $methods = descriptionSegmentationPreviewText('Stations were installed, calibrated, sampled every minute, quality controlled, and processed with documented exclusion rules.', 8);
    $technical = descriptionSegmentationPreviewText('The archive contains CSV, NetCDF, log, and processing history files with coordinate reference metadata and software versions.', 8);
    $files = "- station_raw_2020.csv contains raw measurements and status flags.\n- station_processed_2020.nc contains processed variables and quality flags.\n- processing_history.txt records software versions and excluded intervals.";

    $description = descriptionSegmentationPreviewDescription(implode("\n\n", [
        $overview,
        'Methods:',
        $methods,
        'Technical information:',
        $technical,
        'Files:',
        $files,
    ]));

    $metadata = descriptionSegmentationPreviewMetadata($description);

    expect($metadata)->not->toBeNull()
        ->and($metadata['current']['description_type'])->toBe('Abstract')
        ->and($metadata['proposed']['remaining_abstract'])->toBe($overview)
        ->and($metadata['proposed']['target_types'])->toBe([
            'Methods',
            'TechnicalInfo',
            'TableOfContents',
        ])
        ->and($metadata['confidence']['level'])->toBe('medium')
        ->and($metadata['confidence']['score'])->toBe(0.65);

    $segments = $metadata['proposed']['segments'];

    expect($segments)->toHaveCount(3)
        ->and($segments[0]['description_type'])->toBe('Methods')
        ->and($segments[0]['value'])->toBe($methods)
        ->and($segments[0]['evidence_types'])->toContain(DescriptionSegmentationPolicy::EVIDENCE_HEADING)
        ->and($segments[1]['description_type'])->toBe('TechnicalInfo')
        ->and($segments[2]['description_type'])->toBe('TableOfContents')
        ->and($segments[2]['value'])->toContain('station_processed_2020.nc');
});

it('suppresses keyword-only text without structural evidence', function (): void {
    $description = descriptionSegmentationPreviewDescription(
        descriptionSegmentationPreviewText('This abstract mentions methods, software, formats, processing, stations, and sensor calibration inside normal prose without a labelled section boundary.', 45),
    );

    expect(app(DescriptionSegmentationPreviewBuilder::class)->buildForDescription($description))->toBeNull();
});

it('uses repeated file-like list structure as table of contents evidence', function (): void {
    $overview = descriptionSegmentationPreviewText('This abstract explains the database scope, scientific motivation, regional coverage, temporal coverage, and expected reuse context.', 14);
    $inventory = "- heatflow_points.csv lists point observations and quality flags.\n- heatflow_grid.nc stores the interpolated gridded product and uncertainty layer.\n- heatflow_map.kml provides map overlays for external GIS clients.";

    $description = descriptionSegmentationPreviewDescription($overview."\n\n".$inventory);

    $metadata = descriptionSegmentationPreviewMetadata($description);

    expect($metadata)->not->toBeNull()
        ->and($metadata['proposed']['remaining_abstract'])->toBe($overview)
        ->and($metadata['proposed']['segments'])->toHaveCount(1)
        ->and($metadata['proposed']['segments'][0]['description_type'])->toBe('TableOfContents')
        ->and($metadata['proposed']['segments'][0]['evidence_types'])->toBe([
            DescriptionSegmentationPolicy::EVIDENCE_LIST_STRUCTURE,
            DescriptionSegmentationPolicy::EVIDENCE_FILE_INVENTORY,
        ]);
});

it('marks series information as a low confidence segmentation target', function (): void {
    $overview = descriptionSegmentationPreviewText('This abstract describes a release collection, the spatial scope, temporal coverage, scientific objective, and intended reuse.', 14);
    $series = descriptionSegmentationPreviewText('The data series is released annually as part of a curated collection with stable methodology and versioned increments.', 8);
    $description = descriptionSegmentationPreviewDescription($overview."\n\nSeries information:\n".$series);

    $metadata = descriptionSegmentationPreviewMetadata($description);

    expect($metadata)->not->toBeNull()
        ->and($metadata['confidence']['level'])->toBe('low')
        ->and($metadata['confidence']['score'])->toBe(0.35)
        ->and($metadata['proposed']['segments'][0]['description_type'])->toBe('SeriesInformation')
        ->and($metadata['proposed']['segments'][0]['confidence'])->toBe('low');
});

it('suppresses previews that would leave a contextless abstract remainder', function (): void {
    $overview = 'Short dataset context.';
    $methods = descriptionSegmentationPreviewText('Stations were installed, calibrated, sampled every minute, quality controlled, and processed with documented exclusion rules.', 55);
    $description = descriptionSegmentationPreviewDescription($overview."\n\nMethods:\n".$methods);

    expect(app(DescriptionSegmentationPreviewBuilder::class)->buildForDescription($description))->toBeNull();
});

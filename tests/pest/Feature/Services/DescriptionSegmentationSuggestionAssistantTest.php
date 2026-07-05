<?php

declare(strict_types=1);

use App\Models\AssistantDismissed;
use App\Models\AssistantSuggestion;
use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\Resource;
use App\Models\User;
use App\Services\Assistance\AssistantManifest;
use App\Services\DescriptionSegmentation\DescriptionSegmentationDiscoveryService;
use Illuminate\Support\Facades\DB;
use Modules\Assistants\DescriptionSegmentation\Assistant;

function descriptionSegmentationAssistantType(string $slug, ?string $name = null): DescriptionType
{
    return DescriptionType::firstOrCreate(
        ['slug' => $slug],
        ['name' => $name ?? $slug, 'slug' => $slug, 'is_active' => true, 'is_elmo_active' => true],
    );
}

function descriptionSegmentationAssistantSeedTypes(): void
{
    descriptionSegmentationAssistantType('Abstract');
    descriptionSegmentationAssistantType('Methods');
    descriptionSegmentationAssistantType('TechnicalInfo', 'Technical Info');
    descriptionSegmentationAssistantType('TableOfContents', 'Table Of Contents');
    descriptionSegmentationAssistantType('SeriesInformation', 'Series Information');
}

function descriptionSegmentationAssistantText(string $seed, int $sentences = 12): string
{
    return trim(str_repeat($seed.' ', $sentences));
}

/**
 * @return array{current: array<string, mixed>, proposed: array{remaining_abstract: string, segments: list<array<string, mixed>>}}
 */
function descriptionSegmentationAssistantMetadata(AssistantSuggestion $suggestion): array
{
    $metadata = $suggestion->metadata;

    if (! is_array($metadata)) {
        throw new RuntimeException('Expected description segmentation suggestion metadata.');
    }

    $current = $metadata['current'] ?? null;
    $proposed = $metadata['proposed'] ?? null;

    if (! is_array($current) || ! is_array($proposed)) {
        throw new RuntimeException('Expected current and proposed description segmentation metadata.');
    }

    $remainingAbstract = $proposed['remaining_abstract'] ?? null;
    $segments = $proposed['segments'] ?? null;

    if (! is_string($remainingAbstract) || ! is_array($segments)) {
        throw new RuntimeException('Expected complete proposed description segmentation metadata.');
    }

    return [
        'current' => $current,
        'proposed' => [
            'remaining_abstract' => $remainingAbstract,
            'segments' => array_values(array_filter($segments, static fn (mixed $segment): bool => is_array($segment))),
        ],
    ];
}
function descriptionSegmentationAssistantSourceDescription(): Description
{
    descriptionSegmentationAssistantSeedTypes();

    $resource = Resource::factory()->withDoi('10.5880/test.2026.816')->create();
    $overview = descriptionSegmentationAssistantText('This legacy abstract describes the dataset purpose, study area, observation period, scientific context, and reuse scope.', 14);
    $methods = descriptionSegmentationAssistantText('Stations were installed, calibrated, sampled every minute, quality controlled, and processed with documented exclusion rules.', 8);
    $technical = descriptionSegmentationAssistantText('The archive contains CSV, NetCDF, log, and processing history files with coordinate reference metadata and software versions.', 8);

    $description = Description::create([
        'resource_id' => $resource->id,
        'description_type_id' => descriptionSegmentationAssistantType('Abstract')->id,
        'value' => implode("\n\n", [
            $overview,
            'Methods:',
            $methods,
            'Technical information:',
            $technical,
        ]),
        'language' => 'en',
    ]);

    return $description->load('descriptionType');
}

it('parses and autoloads the description segmentation assistant manifest', function (): void {
    $manifest = AssistantManifest::fromFile(
        base_path('modules/assistants/DescriptionSegmentation/manifest.json'),
    );

    expect($manifest->id)->toBe(DescriptionSegmentationDiscoveryService::ASSISTANT_ID)
        ->and($manifest->assistantClass)->toBe(Assistant::class)
        ->and(class_exists($manifest->assistantClass))->toBeTrue()
        ->and($manifest->routePrefix)->toBe('description-segmentation');
});

it('discovers and stores description segmentation suggestions with review metadata', function (): void {
    $description = descriptionSegmentationAssistantSourceDescription();
    $assistant = app(Assistant::class);

    $count = $assistant->runDiscovery(function (string $message): void {});
    $suggestion = AssistantSuggestion::query()->where('assistant_id', $assistant->getId())->sole();

    $metadata = descriptionSegmentationAssistantMetadata($suggestion);

    expect($count)->toBe(1)
        ->and($suggestion->resource_id)->toBe($description->resource_id)
        ->and($suggestion->target_type)->toBe(DescriptionSegmentationDiscoveryService::TARGET_TYPE)
        ->and($suggestion->target_id)->toBe($description->id)
        ->and($suggestion->suggested_label)->toBe('Split Abstract into Methods, Technical Info')
        ->and($suggestion->similarity_score)->toBe(0.65)
        ->and($metadata['current']['description_id'])->toBe($description->id)
        ->and($metadata['proposed']['segments'])->toHaveCount(2)
        ->and($metadata['proposed']['segments'][0]['description_type'])->toBe('Methods')
        ->and($metadata['proposed']['segments'][1]['description_type'])->toBe('TechnicalInfo');
});

it('does not eager load resource titles during discovery', function (): void {
    descriptionSegmentationAssistantSourceDescription();
    $assistant = app(Assistant::class);
    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $assistant->runDiscovery(function (string $message): void {});

    $titleQueries = array_values(array_filter($queries, static function (string $sql): bool {
        return (bool) preg_match('/\b(from|join)\s+[`"]?(titles|title_types)[`"]?/i', $sql);
    }));

    expect($titleQueries)->toBe([]);
});

it('refreshes an existing preview for the same source description', function (): void {
    $description = descriptionSegmentationAssistantSourceDescription();
    $assistant = app(Assistant::class);

    $assistant->runDiscovery(function (string $message): void {});
    $suggestion = AssistantSuggestion::query()->where('assistant_id', $assistant->getId())->sole();

    $description->forceFill([
        'value' => descriptionSegmentationAssistantText('This changed abstract has enough contextual overview text for the refreshed preview.', 16)
            ."\n\nMethods:\n"
            .descriptionSegmentationAssistantText('The changed methods section now describes sample preparation, processing, calibration, and quality control.', 8),
    ])->save();

    $count = $assistant->runDiscovery(function (string $message): void {});
    $suggestion->refresh();

    $metadata = descriptionSegmentationAssistantMetadata($suggestion);

    expect($count)->toBe(1)
        ->and(AssistantSuggestion::query()->where('assistant_id', $assistant->getId())->count())->toBe(1)
        ->and($suggestion->suggested_label)->toBe('Split Abstract into Methods')
        ->and($metadata['current']['value'])->toContain('This changed abstract')
        ->and($metadata['proposed']['segments'])->toHaveCount(1);
});

it('applies an accepted segmentation by updating the abstract and creating target descriptions', function (): void {
    $description = descriptionSegmentationAssistantSourceDescription();
    $assistant = app(Assistant::class);
    $assistant->runDiscovery(function (string $message): void {});

    $suggestion = AssistantSuggestion::query()->where('assistant_id', $assistant->getId())->sole();
    $metadata = descriptionSegmentationAssistantMetadata($suggestion);
    $remainingAbstract = $metadata['proposed']['remaining_abstract'];
    $methodText = $metadata['proposed']['segments'][0]['value'];
    $technicalText = $metadata['proposed']['segments'][1]['value'];

    $result = $assistant->acceptSuggestion($suggestion->id);
    $description->refresh();

    expect($result)->toMatchArray([
        'success' => true,
    ])
        ->and($description->value)->toBe($remainingAbstract)
        ->and($description->landing_page_html)->toBeNull()
        ->and(AssistantSuggestion::query()->whereKey($suggestion->id)->exists())->toBeFalse()
        ->and(Description::query()->where('resource_id', $description->resource_id)->count())->toBe(3)
        ->and(Description::query()
            ->where('resource_id', $description->resource_id)
            ->where('description_type_id', descriptionSegmentationAssistantType('Methods')->id)
            ->where('value', $methodText)
            ->exists())->toBeTrue()
        ->and(Description::query()
            ->where('resource_id', $description->resource_id)
            ->where('description_type_id', descriptionSegmentationAssistantType('TechnicalInfo')->id)
            ->where('value', $technicalText)
            ->exists())->toBeTrue();
});

it('keeps stale suggestions pending when the source abstract changed before acceptance', function (): void {
    $description = descriptionSegmentationAssistantSourceDescription();
    $assistant = app(Assistant::class);
    $assistant->runDiscovery(function (string $message): void {});

    $suggestion = AssistantSuggestion::query()->where('assistant_id', $assistant->getId())->sole();
    $description->forceFill([
        'value' => descriptionSegmentationAssistantText('A curator manually rewrote this Abstract before accepting the assistant preview.', 20),
    ])->save();

    $result = $assistant->acceptSuggestion($suggestion->id);

    expect($result)->toBe([
        'success' => false,
        'message' => 'Description segmentation suggestion is stale because the source Abstract text changed.',
    ])
        ->and(AssistantSuggestion::query()->whereKey($suggestion->id)->exists())->toBeTrue()
        ->and(Description::query()->where('resource_id', $description->resource_id)->count())->toBe(1);
});

it('records declined segmentations and does not rediscover the same preview', function (): void {
    $description = descriptionSegmentationAssistantSourceDescription();
    $assistant = app(Assistant::class);
    $assistant->runDiscovery(function (string $message): void {});

    $suggestion = AssistantSuggestion::query()->where('assistant_id', $assistant->getId())->sole();
    $user = User::factory()->create();

    $assistant->declineSuggestion($suggestion->id, $user, 'Keep legacy abstract as-is');

    expect(AssistantSuggestion::query()->whereKey($suggestion->id)->exists())->toBeFalse()
        ->and(AssistantDismissed::query()
            ->where('assistant_id', $assistant->getId())
            ->where('target_id', $description->id)
            ->where('dismissed_value', $suggestion->suggested_value)
            ->where('dismissed_by', $user->id)
            ->exists())->toBeTrue();

    $count = $assistant->runDiscovery(function (string $message): void {});

    expect($count)->toBe(0)
        ->and(AssistantSuggestion::query()->where('assistant_id', $assistant->getId())->exists())->toBeFalse();
});

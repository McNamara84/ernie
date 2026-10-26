<?php

declare(strict_types=1);

use App\Enums\Igsn\IgsnClassificationType;
use App\Models\IgsnClassification;
use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Services\LandingPageResourceTransformer;

uses()->group('mysql-sensitive');

it('exposes all stored Medusa legacy classifications from issue 1191', function (): void {
    $cases = [
        [
            'values' => ['rock:bedrock igneous', 'rock:bedrock metamorphic', 'rock:skeleton'],
            'type' => IgsnClassificationType::ROCK,
        ],
        [
            'values' => [
                'vegetation:bark',
                'vegetation:branch',
                'vegetation:leaves/needles',
                'vegetation:litter bag',
                'vegetation:stem',
                'vegetation:twig',
                'vegetation:wood',
            ],
            'type' => IgsnClassificationType::BIOLOGY,
        ],
    ];

    foreach ($cases as $case) {
        /** @var Resource $resource */
        $resource = Resource::factory()->create();
        IgsnMetadata::create(['resource_id' => $resource->id]);
        foreach ($case['values'] as $position => $value) {
            IgsnClassification::create([
                'resource_id' => $resource->id, 'value' => $value,
                'classification_type' => $case['type'], 'position' => $position,
            ]);
        }

        $classifications = IgsnClassification::query()
            ->whereBelongsTo($resource)
            ->orderBy('position')
            ->get();
        expect($classifications->pluck('value')->all())->toBe($case['values'])
            ->and($classifications->pluck('classification_type')->unique()->values()->all())
            ->toBe([$case['type']]);

        $transformer = new LandingPageResourceTransformer;
        $landingResource = Resource::with($transformer->requiredRelations())->findOrFail($resource->id);
        $landingData = $transformer->transform($landingResource);

        expect(array_column($landingData['igsn_classifications'], 'value'))->toBe($case['values'])
            ->and(array_unique(array_column($landingData['igsn_classifications'], 'classification_type')))
            ->toBe([$case['type']->value]);
    }
});

it('preserves and exposes the long Medusa user code from issue 1192', function (): void {
    /** @var Resource $resource */
    $resource = Resource::factory()->create();
    $metadata = IgsnMetadata::create(['resource_id' => $resource->id]);
    $userCode = 'COLD project / Climate Sensitivity of Glacial Landscape Dynamics / ERC-funded';
    $metadata->update([
        'user_code' => $userCode, 'sample_type' => 'Sediment', 'material' => 'Sediment',
        'sample_purpose' => 'Climate sensitivity research', 'collection_method' => 'Grab sampler',
    ]);
    IgsnClassification::create([
        'resource_id' => $resource->id, 'value' => 'sediment:other', 'position' => 0,
    ]);

    $persistedMetadata = $metadata->fresh();
    $classification = IgsnClassification::query()->whereBelongsTo($resource)->sole();
    expect($persistedMetadata->user_code)->toBe($userCode)
        ->and($persistedMetadata->sample_type)->toBe('Sediment')
        ->and($persistedMetadata->material)->toBe('Sediment')
        ->and($persistedMetadata->sample_purpose)->toBe('Climate sensitivity research')
        ->and($persistedMetadata->collection_method)->toBe('Grab sampler')
        ->and($classification->value)->toBe('sediment:other')
        ->and($classification->classification_type)->toBeNull();

    $transformer = new LandingPageResourceTransformer;
    $landingResource = Resource::with($transformer->requiredRelations())->findOrFail($resource->id);
    $landingData = $transformer->transform($landingResource);

    expect($landingData['igsn_metadata'])->toMatchArray([
        'user_code' => $userCode,
        'sample_type' => 'Sediment',
        'material' => 'Sediment',
        'sample_purpose' => 'Climate sensitivity research',
        'collection_method' => 'Grab sampler',
    ])->and($landingData['igsn_classifications'])->toBe([[
        'id' => $classification->id,
        'value' => 'sediment:other',
        'classification_type' => null,
    ]]);
});

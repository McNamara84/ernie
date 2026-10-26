<?php

declare(strict_types=1);

use App\Enums\Igsn\IgsnClassificationType;
use App\Models\IgsnClassification;
use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Services\LandingPageResourceTransformer;

it('exposes every stored legacy classification from issues 1200 and 1202', function (): void {
    $cases = [
        [
            'values' => ['Igneous>Felsic', 'rock', 'rock:core stone', 'rock:crump'],
            'type' => IgsnClassificationType::ROCK,
        ],
        [
            'values' => [
                'vegetation',
                'vegetation:leaf litter',
                'vegetation:other',
                'vegetation:other plant litter',
                'vegetation:whole plant',
                'vegetation:blossom',
                'vegetation:lichen',
                'vegetation:root',
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

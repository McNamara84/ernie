<?php

declare(strict_types=1);

use App\Enums\Igsn\IgsnClassificationType;
use App\Enums\Igsn\IgsnMaterial;
use App\Services\Igsn\IgsnClassificationVocabulary;
use App\Services\Igsn\IgsnVocabularyNormalizer;

covers(IgsnMaterial::class, IgsnClassificationVocabulary::class, IgsnVocabularyNormalizer::class);

it('defines every material value approved in issue 1111', function (): void {
    expect(array_column(IgsnMaterial::cases(), 'value'))->toBe([
        'Biology',
        'Gas',
        'Ice',
        'Liquid>aqueous',
        'Liquid>aqueous>porewater',
        'Liquid>organic',
        'Mineral',
        'NotApplicable',
        'Organic Material',
        'Other',
        'Particulate',
        'Rock',
        'Sediment',
        'Snow',
        'Soil',
        'Synthetic',
        'Tephra',
    ]);
});

it('canonicalizes material aliases and display labels', function (string $raw, ?string $canonical): void {
    $normalizer = new IgsnVocabularyNormalizer;

    expect($normalizer->normalizeMaterial($raw))->toBe($canonical);
})->with([
    [' rock ', 'Rock'],
    ['LIQUID>AQUEOUS', 'Liquid>aqueous'],
    ['Not applicable', 'NotApplicable'],
    ['NotApplicable', 'NotApplicable'],
    ['Organic   Material', 'Organic Material'],
    ['N/A', null],
    ['', null],
]);

it('uses a human-readable label for the compact not-applicable token', function (): void {
    expect(IgsnMaterial::NOT_APPLICABLE->label())->toBe('Not applicable');
});

it('rejects unsupported material values', function (): void {
    (new IgsnVocabularyNormalizer)->normalizeMaterial('Granite');
})->throws(InvalidArgumentException::class, 'Unsupported IGSN material: Granite');

it('loads all versioned material-specific classification values', function (): void {
    $vocabulary = new IgsnClassificationVocabulary;

    expect($vocabulary->values(IgsnClassificationType::ROCK))->toHaveCount(76)
        ->and($vocabulary->values(IgsnClassificationType::MINERAL))->toHaveCount(4176)
        ->and($vocabulary->values(IgsnClassificationType::BIOLOGY))->toHaveCount(24)
        ->and($vocabulary->contains(IgsnClassificationType::ROCK, 'Igneous>Volcanic'))->toBeTrue()
        ->and($vocabulary->contains(IgsnClassificationType::MINERAL, 'Quartz'))->toBeTrue()
        ->and($vocabulary->contains(IgsnClassificationType::BIOLOGY, 'whole plant'))->toBeTrue();
});

it('keeps the versioned classification catalogs structurally valid', function (): void {
    $rock = json_decode(file_get_contents(resource_path('data/igsn/classification-rock.json')), true, flags: JSON_THROW_ON_ERROR);
    $mineral = json_decode(file_get_contents(resource_path('data/igsn/classification-mineral.json')), true, flags: JSON_THROW_ON_ERROR);
    $biology = json_decode(file_get_contents(resource_path('data/igsn/classification-biology.json')), true, flags: JSON_THROW_ON_ERROR);

    foreach ([$rock['values'], $mineral['values'], array_column($biology['values'], 'value')] as $values) {
        $normalized = array_map(static fn (string $value): string => mb_strtolower(trim($value)), $values);

        expect($normalized)->not->toContain('')
            ->and(array_unique($normalized))->toHaveCount(count($normalized));
    }

    $rockLookup = array_fill_keys($rock['values'], true);
    foreach ($rock['values'] as $value) {
        if (str_contains($value, '>')) {
            expect($rockLookup)->toHaveKey(substr($value, 0, (int) strrpos($value, '>')));
        }
    }

    foreach ($biology['values'] as $entry) {
        expect($entry)->toHaveKeys(['value', 'definition', 'value_uri']);
        if ($entry['value'] !== 'Other') {
            expect($entry['definition'])->toBeString()->not->toBeEmpty()
                ->and($entry['value_uri'])->toStartWith('http://purl.obolibrary.org/obo/BTO_');
        }
    }
});

it('canonicalizes and deduplicates classifications for controlled materials', function (): void {
    $normalizer = new IgsnVocabularyNormalizer;

    expect($normalizer->normalizeClassifications('Rock', [
        ' igneous ',
        'IGNEOUS',
        'Igneous>Volcanic',
        'N/A',
    ]))->toBe(['Igneous', 'Igneous>Volcanic']);
});

it('rejects a classification outside the material-specific vocabulary', function (): void {
    (new IgsnVocabularyNormalizer)->normalizeClassifications('Rock', ['Quartz']);
})->throws(InvalidArgumentException::class, 'Unsupported IGSN rock classification: Quartz');

it('partitions legacy classifications into valid and rejected values', function (): void {
    expect((new IgsnVocabularyNormalizer)->partitionClassifications('Rock', [
        'igneous',
        'legacy rock term',
        'IGNEOUS',
    ]))->toBe([
        'values' => ['Igneous'],
        'rejected' => ['legacy rock term'],
    ]);
});

it('preserves normalized free classifications for materials without a classification catalog', function (): void {
    expect((new IgsnVocabularyNormalizer)->normalizeClassifications('Sediment', [
        ' Custom   class ',
        'custom class',
    ]))->toBe(['Custom class']);
});

it('derives the persisted classification type from material', function (): void {
    $normalizer = new IgsnVocabularyNormalizer;

    expect($normalizer->classificationType('Rock'))->toBe(IgsnClassificationType::ROCK)
        ->and($normalizer->classificationType('Mineral'))->toBe(IgsnClassificationType::MINERAL)
        ->and($normalizer->classificationType('Biology'))->toBe(IgsnClassificationType::BIOLOGY)
        ->and($normalizer->classificationType('Sediment'))->toBeNull();
});

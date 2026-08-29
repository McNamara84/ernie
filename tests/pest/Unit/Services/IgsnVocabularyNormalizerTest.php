<?php

declare(strict_types=1);

use App\Enums\Igsn\IgsnClassificationType;
use App\Enums\Igsn\IgsnMaterial;
use App\Services\Igsn\IgsnClassificationVocabularyService;
use App\Services\Igsn\IgsnVocabularyNormalizerService;

covers(IgsnMaterial::class, IgsnClassificationVocabularyService::class, IgsnVocabularyNormalizerService::class);

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
    $normalizer = new IgsnVocabularyNormalizerService;

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
    (new IgsnVocabularyNormalizerService)->normalizeMaterial('Granite');
})->throws(InvalidArgumentException::class, 'Unsupported IGSN material: Granite');

it('loads all versioned material-specific classification values', function (): void {
    $vocabulary = new IgsnClassificationVocabularyService;

    expect($vocabulary->values(IgsnClassificationType::ROCK))->toHaveCount(100)
        ->and($vocabulary->values(IgsnClassificationType::MINERAL))->toHaveCount(4172)
        ->and($vocabulary->values(IgsnClassificationType::BIOLOGY))->toHaveCount(39)
        ->and($vocabulary->contains(IgsnClassificationType::ROCK, 'Igneous>Volcanic'))->toBeTrue()
        ->and($vocabulary->contains(IgsnClassificationType::ROCK, 'Igneous>Felsic'))->toBeTrue()
        ->and($vocabulary->contains(IgsnClassificationType::ROCK, 'rock:bedrock igneous'))->toBeTrue()
        ->and($vocabulary->contains(IgsnClassificationType::ROCK, 'rock:core stone'))->toBeTrue()
        ->and($vocabulary->contains(IgsnClassificationType::MINERAL, 'Quartz'))->toBeTrue()
        ->and($vocabulary->contains(IgsnClassificationType::BIOLOGY, 'whole plant'))->toBeTrue()
        ->and($vocabulary->contains(IgsnClassificationType::BIOLOGY, 'vegetation:leaves/needles'))->toBeTrue()
        ->and($vocabulary->contains(IgsnClassificationType::BIOLOGY, 'vegetation:other plant litter'))->toBeTrue();
});

it('marks every issue 1210 ICDP classification as legacy', function (): void {
    $contents = file_get_contents(resource_path('data/igsn/classification-rock.json'));
    if ($contents === false) {
        throw new RuntimeException('Unable to read the rock classification catalog.');
    }

    $entries = json_decode($contents, true, flags: JSON_THROW_ON_ERROR)['values'];
    $byValue = [];
    foreach ($entries as $entry) {
        if (is_array($entry) && is_string($entry['value'] ?? null)) {
            $byValue[$entry['value']] = $entry;
        }
    }

    foreach ([
        'MYL',
        'PROTOMYL',
        'QUAT',
        'SCH',
        'UND',
        'VOL',
        'cataclastic rocks',
        'fault related rocks',
        'igneous rocks',
        'metamorphic rocks',
        'mylonitic rocks',
        'protomylonites',
        'quaternary deposits, metamorphic rocks',
        'sample',
        'sedimentary rocks',
        'undefined',
        'volcanic rocks',
    ] as $value) {
        expect($byValue)->toHaveKey($value)
            ->and($byValue[$value]['legacy'] ?? null)->toBeTrue();
    }
});

it('keeps the versioned classification catalogs structurally valid', function (): void {
    $rockContents = file_get_contents(resource_path('data/igsn/classification-rock.json'));
    $mineralContents = file_get_contents(resource_path('data/igsn/classification-mineral.json'));
    $biologyContents = file_get_contents(resource_path('data/igsn/classification-biology.json'));

    if ($rockContents === false || $mineralContents === false || $biologyContents === false) {
        throw new RuntimeException('Unable to read an IGSN classification catalog.');
    }

    $rock = json_decode($rockContents, true, flags: JSON_THROW_ON_ERROR);
    $mineral = json_decode($mineralContents, true, flags: JSON_THROW_ON_ERROR);
    $biology = json_decode($biologyContents, true, flags: JSON_THROW_ON_ERROR);

    $catalogValues = static fn (array $entries): array => array_map(
        static fn (mixed $entry): string => is_array($entry) ? (string) ($entry['value'] ?? '') : (string) $entry,
        $entries,
    );
    $rockValues = $catalogValues($rock['values']);
    $mineralValues = $catalogValues($mineral['values']);
    $biologyValues = $catalogValues($biology['values']);

    foreach ([$rockValues, $mineralValues, $biologyValues] as $values) {
        $normalized = array_map(static fn (string $value): string => mb_strtolower(trim($value)), $values);

        expect($normalized)->not->toContain('')
            ->and(array_unique($normalized))->toHaveCount(count($normalized));
    }

    $rockLookup = array_fill_keys($rockValues, true);
    foreach ($rockValues as $value) {
        if (str_contains($value, '>')) {
            expect($rockLookup)->toHaveKey(substr($value, 0, (int) strrpos($value, '>')));
        }
    }

    $legacyBiologyWithoutExactBto = [
        'vegetation',
        'vegetation:blossom',
        'vegetation:leaf litter',
        'vegetation:leaves/needles',
        'vegetation:lichen',
        'vegetation:litter bag',
        'vegetation:other',
        'vegetation:other plant litter',
    ];

    foreach ($biology['values'] as $entry) {
        expect($entry)->toHaveKeys(['value', 'definition', 'value_uri']);
        if ($entry['value'] !== 'Other') {
            expect($entry['definition'])->toBeString()->not->toBeEmpty();

            if (in_array($entry['value'], $legacyBiologyWithoutExactBto, true)) {
                expect($entry['value_uri'])->toBeNull()
                    ->and($entry['legacy'] ?? null)->toBeTrue();
            } else {
                expect($entry['value_uri'])->toStartWith('http://purl.obolibrary.org/obo/BTO_');
            }
        }
    }

    expect($mineral['values'])->not->toContain(
        'More Info',
        'Related',
        'Resources',
        'Subscribe to our newsletter:',
    );

    $biologyByValue = array_column($biology['values'], null, 'value');

    expect($biologyByValue['branch']['value_uri'])->toBe('http://purl.obolibrary.org/obo/BTO_0001300')
        ->and($biologyByValue['stem']['value_uri'])->toBe('http://purl.obolibrary.org/obo/BTO_0000142');
});

it('marks every issue 1191 classification as legacy and uses only exact BTO mappings', function (): void {
    $catalog = static function (string $name): array {
        $contents = file_get_contents(resource_path("data/igsn/classification-{$name}.json"));
        if ($contents === false) {
            throw new RuntimeException("Unable to read the {$name} classification catalog.");
        }

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR)['values'];
    };
    $entriesByValue = static function (array $entries): array {
        $result = [];
        foreach ($entries as $entry) {
            if (is_array($entry) && is_string($entry['value'] ?? null)) {
                $result[$entry['value']] = $entry;
            }
        }

        return $result;
    };

    $rock = $entriesByValue($catalog('rock'));
    $biology = $entriesByValue($catalog('biology'));

    foreach (['rock:bedrock igneous', 'rock:bedrock metamorphic', 'rock:skeleton'] as $value) {
        expect($rock)->toHaveKey($value)
            ->and($rock[$value]['legacy'] ?? null)->toBeTrue();
    }

    $expectedBiologyUris = [
        'vegetation:bark' => 'http://purl.obolibrary.org/obo/BTO_0001301',
        'vegetation:branch' => 'http://purl.obolibrary.org/obo/BTO_0001300',
        'vegetation:leaves/needles' => null,
        'vegetation:litter bag' => null,
        'vegetation:stem' => 'http://purl.obolibrary.org/obo/BTO_0000142',
        'vegetation:twig' => 'http://purl.obolibrary.org/obo/BTO_0001411',
        'vegetation:wood' => 'http://purl.obolibrary.org/obo/BTO_0005516',
    ];
    foreach ($expectedBiologyUris as $value => $uri) {
        expect($biology)->toHaveKey($value)
            ->and($biology[$value]['legacy'] ?? null)->toBeTrue()
            ->and($biology[$value]['definition'] ?? null)->toBeString()->not->toBeEmpty()
            ->and($biology[$value]['value_uri'] ?? null)->toBe($uri);
    }
});

it('marks every issue 1200 and 1202 classification as legacy and uses only exact BTO mappings', function (): void {
    $catalog = static function (string $name): array {
        $contents = file_get_contents(resource_path("data/igsn/classification-{$name}.json"));
        if ($contents === false) {
            throw new RuntimeException("Unable to read the {$name} classification catalog.");
        }

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR)['values'];
    };
    $entriesByValue = static function (array $entries): array {
        $result = [];
        foreach ($entries as $entry) {
            if (is_array($entry) && is_string($entry['value'] ?? null)) {
                $result[$entry['value']] = $entry;
            }
        }

        return $result;
    };

    $rock = $entriesByValue($catalog('rock'));
    $biology = $entriesByValue($catalog('biology'));

    foreach (['Igneous>Felsic', 'rock', 'rock:core stone', 'rock:crump'] as $value) {
        expect($rock)->toHaveKey($value)
            ->and($rock[$value]['legacy'] ?? null)->toBeTrue();
    }

    $expectedBiologyUris = [
        'vegetation' => null,
        'vegetation:leaf litter' => null,
        'vegetation:other' => null,
        'vegetation:other plant litter' => null,
        'vegetation:whole plant' => 'http://purl.obolibrary.org/obo/BTO_0001461',
        'vegetation:blossom' => null,
        'vegetation:lichen' => null,
        'vegetation:root' => 'http://purl.obolibrary.org/obo/BTO_0001188',
    ];
    foreach ($expectedBiologyUris as $value => $uri) {
        expect($biology)->toHaveKey($value)
            ->and($biology[$value]['legacy'] ?? null)->toBeTrue()
            ->and($biology[$value]['definition'] ?? null)->toBeString()->not->toBeEmpty()
            ->and($biology[$value]['value_uri'] ?? null)->toBe($uri);
    }
});

it('canonicalizes and deduplicates classifications for controlled materials', function (): void {
    $normalizer = new IgsnVocabularyNormalizerService;

    expect($normalizer->normalizeClassifications('Rock', [
        ' igneous ',
        'IGNEOUS',
        'Igneous>Volcanic',
        'N/A',
    ]))->toBe(['Igneous', 'Igneous>Volcanic']);
});

it('canonicalizes every issue 1191 legacy classification without changing its value', function (
    string $material,
    string $raw,
    string $canonical,
): void {
    expect((new IgsnVocabularyNormalizerService)->normalizeClassifications($material, [$raw]))
        ->toBe([$canonical]);
})->with([
    ['Rock', ' ROCK:BEDROCK   IGNEOUS ', 'rock:bedrock igneous'],
    ['Rock', 'ROCK:BEDROCK METAMORPHIC', 'rock:bedrock metamorphic'],
    ['Rock', 'ROCK:SKELETON', 'rock:skeleton'],
    ['Biology', 'VEGETATION:BARK', 'vegetation:bark'],
    ['Biology', 'VEGETATION:BRANCH', 'vegetation:branch'],
    ['Biology', 'VEGETATION:LEAVES/NEEDLES', 'vegetation:leaves/needles'],
    ['Biology', 'VEGETATION:LITTER BAG', 'vegetation:litter bag'],
    ['Biology', 'VEGETATION:STEM', 'vegetation:stem'],
    ['Biology', 'VEGETATION:TWIG', 'vegetation:twig'],
    ['Biology', 'VEGETATION:WOOD', 'vegetation:wood'],
]);

it('canonicalizes every issue 1200 and 1202 legacy classification without changing its value', function (
    string $material,
    string $raw,
    string $canonical,
): void {
    expect((new IgsnVocabularyNormalizerService)->normalizeClassifications($material, [$raw]))
        ->toBe([$canonical]);
})->with([
    ['Rock', ' IGNEOUS>FELSIC ', 'Igneous>Felsic'],
    ['Rock', ' ROCK ', 'rock'],
    ['Rock', 'ROCK:CORE   STONE', 'rock:core stone'],
    ['Rock', 'ROCK:CRUMP', 'rock:crump'],
    ['Biology', ' VEGETATION ', 'vegetation'],
    ['Biology', 'VEGETATION:LEAF   LITTER', 'vegetation:leaf litter'],
    ['Biology', 'VEGETATION:OTHER', 'vegetation:other'],
    ['Biology', 'VEGETATION:OTHER PLANT LITTER', 'vegetation:other plant litter'],
    ['Biology', 'VEGETATION:WHOLE PLANT', 'vegetation:whole plant'],
    ['Biology', 'VEGETATION:BLOSSOM', 'vegetation:blossom'],
    ['Biology', 'VEGETATION:LICHEN', 'vegetation:lichen'],
    ['Biology', 'VEGETATION:ROOT', 'vegetation:root'],
]);

it('canonicalizes every issue 1210 ICDP classification without changing its value', function (
    string $raw,
    string $canonical,
): void {
    expect((new IgsnVocabularyNormalizerService)->normalizeClassifications('Rock', [$raw]))
        ->toBe([$canonical]);
})->with([
    [' myl ', 'MYL'],
    ['protomyl', 'PROTOMYL'],
    ['quat', 'QUAT'],
    ['sch', 'SCH'],
    ['und', 'UND'],
    ['vol', 'VOL'],
    ['CATACLASTIC ROCKS', 'cataclastic rocks'],
    ['FAULT   RELATED ROCKS', 'fault related rocks'],
    ['IGNEOUS ROCKS', 'igneous rocks'],
    ['METAMORPHIC ROCKS', 'metamorphic rocks'],
    ['MYLONITIC ROCKS', 'mylonitic rocks'],
    ['PROTOMYLONITES', 'protomylonites'],
    ['QUATERNARY DEPOSITS, METAMORPHIC ROCKS', 'quaternary deposits, metamorphic rocks'],
    ['SAMPLE', 'sample'],
    ['SEDIMENTARY ROCKS', 'sedimentary rocks'],
    ['UNDEFINED', 'undefined'],
    ['VOLCANIC ROCKS', 'volcanic rocks'],
]);

it('rejects a classification outside the material-specific vocabulary', function (): void {
    (new IgsnVocabularyNormalizerService)->normalizeClassifications('Rock', ['Quartz']);
})->throws(InvalidArgumentException::class, 'Unsupported IGSN rock classification: Quartz');

it('partitions legacy classifications into valid and rejected values', function (): void {
    expect((new IgsnVocabularyNormalizerService)->partitionClassifications('Rock', [
        'igneous',
        'legacy rock term',
        'IGNEOUS',
    ]))->toBe([
        'values' => ['Igneous'],
        'rejected' => ['legacy rock term'],
    ]);
});

it('preserves normalized free classifications for materials without a classification catalog', function (): void {
    expect((new IgsnVocabularyNormalizerService)->normalizeClassifications('Sediment', [
        ' Custom   class ',
        'custom class',
    ]))->toBe(['Custom class']);
});

it('derives the persisted classification type from material', function (): void {
    $normalizer = new IgsnVocabularyNormalizerService;

    expect($normalizer->classificationType('Rock'))->toBe(IgsnClassificationType::ROCK)
        ->and($normalizer->classificationType('Mineral'))->toBe(IgsnClassificationType::MINERAL)
        ->and($normalizer->classificationType('Biology'))->toBe(IgsnClassificationType::BIOLOGY)
        ->and($normalizer->classificationType('Sediment'))->toBeNull();
});

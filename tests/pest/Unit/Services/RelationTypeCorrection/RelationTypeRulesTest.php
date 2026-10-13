<?php

declare(strict_types=1);

use App\Models\RelationType;
use App\Services\RelationTypeCorrection\RelationTypeRules;
use Database\Seeders\RelationTypeSeeder;

it('inventories every DataCite 4.7 relation type without approximations', function (): void {
    $this->seed(RelationTypeSeeder::class);
    expect(RelationTypeRules::vocabulary())->toHaveCount(39)
        ->and(array_diff(RelationType::pluck('slug')->all(), RelationTypeRules::vocabulary()))->toBe([]);
});

it('offers a structural direction rule for each registered asymmetric role', function (string $current): void {
    $inverse = RelationTypeRules::inverse($current);
    expect($inverse)->not->toBeNull()
        ->and(RelationTypeRules::inverse($inverse))->toBe($current)
        ->and(RelationTypeRules::conflict($current, $inverse, false)['id'])->toBe('reversed-structural-role')
        ->and(RelationTypeRules::conflict($current, $inverse, true))->toBeNull()
        ->and(RelationTypeRules::conflict($current, $current, false))->toBeNull();
})->with(RelationTypeRules::DIRECTIONAL);

it('preserves legitimate citations, translations, metadata and unrelated additional assertions', function (string $current, string $proposed): void {
    expect(RelationTypeRules::conflict($current, $proposed, false))->toBeNull();
})->with([
    ['Cites', 'IsCitedBy'], ['References', 'IsReferencedBy'], ['HasTranslation', 'IsTranslationOf'],
    ['HasMetadata', 'IsMetadataFor'], ['IsSupplementTo', 'IsSupplementedBy'], ['Reviews', 'IsReviewedBy'],
    ['IsDerivedFrom', 'Cites'], ['IsNewVersionOf', 'IsVersionOf'], ['IsVersionOf', 'HasPart'], ['IsIdenticalTo', 'IsPartOf'],
]);

it('only specializes an unqualified Other relation with exact evidence', function (): void {
    expect(RelationTypeRules::conflict('Other', 'IsPartOf', false)['id'])->toBe('explicit-relation')
        ->and(RelationTypeRules::conflict('Other', 'IsPartOf', true))->toBeNull()
        ->and(RelationTypeRules::conflict('HasPart', 'Other', false))->toBeNull();
});

it('does not invent inverse semantics', function (): void {
    expect(RelationTypeRules::inverse('Other'))->toBeNull()
        ->and(RelationTypeRules::inverse('IsPublishedIn'))->toBeNull()
        ->and(RelationTypeRules::inverse('IsIdenticalTo'))->toBe('IsIdenticalTo')
        ->and(RelationTypeRules::dataCite('unknown'))->toBeNull();
});

it('normalizes DOI forms but rejects arbitrary URLs and invalid input', function (string $value, ?string $expected): void {
    expect(RelationTypeRules::doi($value))->toBe($expected);
})->with([
    ['10.5880/ABC', '10.5880/abc'], [' HTTPS://DX.DOI.ORG/10.5880/AbC ', '10.5880/abc'],
    ['doi: 10.5880/ABC', '10.5880/abc'], ['https://evil.example/10.5880/abc', null], ['', null],
    ['10.12/abc', null], ['10.5880/has space', null],
]);

it('canonicalizes field and collection ordering for material fingerprints', function (): void {
    $a = ['value' => ['z' => 1, 'a' => 2], 'evidence' => [['type' => 'Cites'], ['type' => 'HasPart']]];
    $b = ['evidence' => [['type' => 'HasPart'], ['type' => 'Cites']], 'value' => ['a' => 2, 'z' => 1]];
    expect(RelationTypeRules::fingerprint($a))->toBe(RelationTypeRules::fingerprint($b))
        ->and(RelationTypeRules::fingerprint($a))->not->toBe(RelationTypeRules::fingerprint([...$a, 'new' => true]));
});

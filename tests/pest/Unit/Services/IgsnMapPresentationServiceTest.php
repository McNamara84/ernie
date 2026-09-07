<?php

declare(strict_types=1);

use App\Services\Igsn\IgsnMapPresentationService;

covers(IgsnMapPresentationService::class);

it('maps controlled materials to stable top-level categories', function (
    ?string $material,
    string $key,
    string $label,
    string $status,
    ?string $canonicalMaterial,
    ?string $materialLabel,
): void {
    expect((new IgsnMapPresentationService)->forMaterial($material))->toBe([
        'key' => $key,
        'label' => $label,
        'status' => $status,
        'material' => $canonicalMaterial,
        'materialLabel' => $materialLabel,
    ]);
})->with([
    'top-level rock' => ['Rock', 'rock', 'Rock', 'value', 'Rock', 'Rock'],
    'case-insensitive controlled value' => [' rock ', 'rock', 'Rock', 'value', 'Rock', 'Rock'],
    'hierarchical liquid' => ['Liquid>aqueous>porewater', 'liquid', 'Liquid', 'value', 'Liquid>aqueous>porewater', 'Liquid › aqueous › porewater'],
    'organic liquid hierarchy' => ['Liquid>organic', 'liquid', 'Liquid', 'value', 'Liquid>organic', 'Liquid › organic'],
    'biology' => ['Biology', 'biology', 'Biology', 'value', 'Biology', 'Biology'],
    'gas' => ['Gas', 'gas', 'Gas', 'value', 'Gas', 'Gas'],
    'ice' => ['Ice', 'ice', 'Ice', 'value', 'Ice', 'Ice'],
    'mineral' => ['Mineral', 'mineral', 'Mineral', 'value', 'Mineral', 'Mineral'],
    'organic material' => ['Organic Material', 'organic-material', 'Organic Material', 'value', 'Organic Material', 'Organic Material'],
    'particulate' => ['Particulate', 'particulate', 'Particulate', 'value', 'Particulate', 'Particulate'],
    'sediment' => ['Sediment', 'sediment', 'Sediment', 'value', 'Sediment', 'Sediment'],
    'snow' => ['Snow', 'snow', 'Snow', 'value', 'Snow', 'Snow'],
    'soil' => ['Soil', 'soil', 'Soil', 'value', 'Soil', 'Soil'],
    'synthetic' => ['Synthetic', 'synthetic', 'Synthetic', 'value', 'Synthetic', 'Synthetic'],
    'tephra' => ['Tephra', 'tephra', 'Tephra', 'value', 'Tephra', 'Tephra'],
    'other remains a real material' => ['Other', 'other', 'Other', 'value', 'Other', 'Other'],
    'not applicable remains explicit' => ['NotApplicable', 'not-applicable', 'Not applicable', 'not-applicable', 'NotApplicable', 'Not applicable'],
    'legacy not-applicable spelling is canonicalized' => ['not applicable', 'not-applicable', 'Not applicable', 'not-applicable', 'NotApplicable', 'Not applicable'],
    'null is missing' => [null, 'missing', 'No material provided', 'missing', null, null],
    'blank is missing' => ['   ', 'missing', 'No material provided', 'missing', null, null],
    'legacy n-a is missing' => ['N/A', 'missing', 'No material provided', 'missing', null, null],
    'unexpected value is bounded' => ['Uncontrolled>value', 'unrecognized', 'Unrecognized material', 'unrecognized', 'Uncontrolled>value', 'Uncontrolled › value'],
]);

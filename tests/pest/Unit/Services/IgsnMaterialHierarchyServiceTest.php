<?php

declare(strict_types=1);

use App\Services\Igsn\IgsnMaterialHierarchyService;

covers(IgsnMaterialHierarchyService::class);

beforeEach(function (): void {
    $this->service = new IgsnMaterialHierarchyService;
});

it('resolves exact materials and synthetic parents to canonical values', function (): void {
    expect($this->service->resolve(['Rock']))->toBe(['Rock'])
        ->and($this->service->resolve(['Liquid']))->toBe([
            'Liquid>aqueous',
            'Liquid>aqueous>porewater',
            'Liquid>organic',
        ])
        ->and($this->service->resolve(['Liquid>aqueous']))->toBe([
            'Liquid>aqueous',
            'Liquid>aqueous>porewater',
        ]);
});

it('deduplicates overlapping selections and rejects unknown nodes', function (): void {
    expect($this->service->resolve(['Liquid', 'Liquid>aqueous']))->toBe([
        'Liquid>aqueous',
        'Liquid>aqueous>porewater',
        'Liquid>organic',
    ])->and($this->service->resolve(['Unobtainium']))->toBeNull();
});

it('builds a pruned counted material hierarchy and retains selected zero-count nodes', function (): void {
    $tree = $this->service->buildTree([
        'Rock' => 7,
        'Liquid>aqueous' => 3,
        'Liquid>aqueous>porewater' => 2,
    ], ['Snow']);

    expect($tree)->toHaveCount(3)
        ->and($tree[0])->toMatchArray(['value' => 'Liquid', 'label' => 'Liquid', 'count' => 5])
        ->and($tree[0]['children'][0])->toMatchArray(['value' => 'Liquid>aqueous', 'label' => 'aqueous', 'count' => 5])
        ->and($tree[0]['children'][0]['children'][0])->toMatchArray([
            'value' => 'Liquid>aqueous>porewater',
            'label' => 'porewater',
            'count' => 2,
        ])
        ->and($tree[1])->toMatchArray(['value' => 'Rock', 'count' => 7])
        ->and($tree[2])->toMatchArray(['value' => 'Snow', 'count' => 0]);
});

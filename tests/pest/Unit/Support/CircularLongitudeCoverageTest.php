<?php

declare(strict_types=1);

use App\Support\CircularLongitudeCoverage;

covers(CircularLongitudeCoverage::class);

it('returns the minimum wrapped interval for dateline points', function (): void {
    $coverage = new CircularLongitudeCoverage;
    $coverage->add(179.0, 179.0);
    $coverage->add(-179.0, -179.0);

    expect($coverage->bounds())->toBe(['west' => 179.0, 'east' => -179.0]);
});

it('does not drop either half of a wrapped interval when another point is added', function (): void {
    expect(CircularLongitudeCoverage::merge(170.0, -170.0, 179.0, 179.0))
        ->toBe(['west' => 170.0, 'east' => -170.0]);
});

it('computes the global minimum from all coverage instead of pairwise extrema', function (): void {
    $coverage = new CircularLongitudeCoverage;
    foreach ([0.0, 10.0, 100.0, -160.0] as $longitude) {
        $coverage->add($longitude, $longitude);
    }

    expect($coverage->bounds())->toBe(['west' => 0.0, 'east' => -160.0]);
});

it('recognizes explicit whole-world coverage', function (): void {
    $coverage = new CircularLongitudeCoverage;
    $coverage->add(-180.0, 180.0);

    expect($coverage->bounds())->toBe(['west' => -180.0, 'east' => 180.0]);
});

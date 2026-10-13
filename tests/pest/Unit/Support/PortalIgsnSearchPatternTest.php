<?php

declare(strict_types=1);

use App\Support\PortalIgsnSearchPattern;

covers(PortalIgsnSearchPattern::class);

it('uses * for ordered zero-or-more-character matches', function (): void {
    $pattern = new PortalIgsnSearchPattern('Geo*1*2');

    expect($pattern->likePattern())->toBe('%geo%1%2%')
        ->and($pattern->matches('GEO-12'))->toBeTrue()
        ->and($pattern->matches('prefix geo a1 b2 suffix'))->toBeTrue()
        ->and($pattern->matches('Geo 2 1'))->toBeFalse();
});

it('treats SQL wildcard characters as literal text', function (): void {
    $pattern = new PortalIgsnSearchPattern('%_!');

    expect($pattern->likePattern())->toBe('%!%!_!!%')
        ->and($pattern->matches('Sample %_! 12'))->toBeTrue()
        ->and($pattern->matches('Sample ABC 12'))->toBeFalse();
});

it('recognizes wildcard-only searches without manufacturing match annotations', function (): void {
    expect((new PortalIgsnSearchPattern('***'))->isMatchAll())->toBeTrue()
        ->and((new PortalIgsnSearchPattern('* *'))->isMatchAll())->toBeFalse()
        ->and((new PortalIgsnSearchPattern('Geo*'))->isMatchAll())->toBeFalse();
});

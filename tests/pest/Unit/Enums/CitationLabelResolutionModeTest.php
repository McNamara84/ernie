<?php

declare(strict_types=1);

use App\Enums\CitationLabelResolutionMode;

covers(CitationLabelResolutionMode::class);

it('treats required preparation as satisfying both citation resolution modes', function (): void {
    expect(CitationLabelResolutionMode::REQUIRED->satisfies(CitationLabelResolutionMode::REQUIRED))->toBeTrue()
        ->and(CitationLabelResolutionMode::REQUIRED->satisfies(CitationLabelResolutionMode::BEST_EFFORT))->toBeTrue();
});

it('does not treat best-effort preparation as satisfying required mode', function (): void {
    expect(CitationLabelResolutionMode::BEST_EFFORT->satisfies(CitationLabelResolutionMode::BEST_EFFORT))->toBeTrue()
        ->and(CitationLabelResolutionMode::BEST_EFFORT->satisfies(CitationLabelResolutionMode::REQUIRED))->toBeFalse();
});

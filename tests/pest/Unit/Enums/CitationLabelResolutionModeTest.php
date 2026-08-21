<?php

declare(strict_types=1);

use App\Enums\CitationLabelResolutionMode;

covers(CitationLabelResolutionMode::class);

it('treats exhaustive preparation as satisfying both citation resolution modes', function (): void {
    expect(CitationLabelResolutionMode::EXHAUSTIVE->satisfies(CitationLabelResolutionMode::EXHAUSTIVE))->toBeTrue()
        ->and(CitationLabelResolutionMode::EXHAUSTIVE->satisfies(CitationLabelResolutionMode::BEST_EFFORT))->toBeTrue();
});

it('does not treat best-effort preparation as satisfying exhaustive mode', function (): void {
    expect(CitationLabelResolutionMode::BEST_EFFORT->satisfies(CitationLabelResolutionMode::BEST_EFFORT))->toBeTrue()
        ->and(CitationLabelResolutionMode::BEST_EFFORT->satisfies(CitationLabelResolutionMode::EXHAUSTIVE))->toBeFalse();
});

<?php

declare(strict_types=1);

use App\Enums\FeedbackCategory;

covers(FeedbackCategory::class);

it('provides stable values and human-readable labels', function (): void {
    expect(array_map(static fn (FeedbackCategory $category): string => $category->value, FeedbackCategory::cases()))
        ->toBe(['problem', 'idea', 'praise', 'other'])
        ->and(FeedbackCategory::PROBLEM->label())->toBe('Problem')
        ->and(FeedbackCategory::IDEA->label())->toBe('Idea')
        ->and(FeedbackCategory::PRAISE->label())->toBe('Praise')
        ->and(FeedbackCategory::OTHER->label())->toBe('Other');
});

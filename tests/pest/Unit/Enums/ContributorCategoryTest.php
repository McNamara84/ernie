<?php

declare(strict_types=1);

use App\Enums\ContributorCategory;

covers(ContributorCategory::class);

describe('ContributorCategory', function (): void {
    test('has correct values', function (): void {
        expect(ContributorCategory::PERSON->value)->toBe('person');
        expect(ContributorCategory::INSTITUTION->value)->toBe('institution');
        expect(ContributorCategory::BOTH->value)->toBe('both');
    });

    test('can be created from value', function (): void {
        expect(ContributorCategory::from('person'))->toBe(ContributorCategory::PERSON);
        expect(ContributorCategory::from('institution'))->toBe(ContributorCategory::INSTITUTION);
        expect(ContributorCategory::from('both'))->toBe(ContributorCategory::BOTH);
    });

    test('has exactly three cases', function (): void {
        expect(ContributorCategory::cases())->toHaveCount(3);
    });
});

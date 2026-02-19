<?php

declare(strict_types=1);

use App\Support\BooleanNormalizer;

covers(BooleanNormalizer::class);

describe('BooleanNormalizer::isTrue()', function () {
    it('returns true for boolean true')
        ->expect(fn () => BooleanNormalizer::isTrue(true))
        ->toBeTrue();

    it('returns false for boolean false')
        ->expect(fn () => BooleanNormalizer::isTrue(false))
        ->toBeFalse();

    it('returns true for string true values', function (string $value) {
        expect(BooleanNormalizer::isTrue($value))->toBeTrue();
    })->with(['1', 'true', 'on', 'yes', 'TRUE', 'True', 'ON', 'YES', 'On', 'Yes']);

    it('returns false for string false values', function (string $value) {
        expect(BooleanNormalizer::isTrue($value))->toBeFalse();
    })->with(['0', 'false', 'off', 'no', 'FALSE', 'nope', 'random', '']);

    it('returns true for integer 1')
        ->expect(fn () => BooleanNormalizer::isTrue(1))
        ->toBeTrue();

    it('returns false for integer 0')
        ->expect(fn () => BooleanNormalizer::isTrue(0))
        ->toBeFalse();

    it('returns false for other integers', function (int $value) {
        expect(BooleanNormalizer::isTrue($value))->toBeFalse();
    })->with([2, -1, 100, 42]);

    it('returns true for float 1.0')
        ->expect(fn () => BooleanNormalizer::isTrue(1.0))
        ->toBeTrue();

    it('returns false for other floats', function (float $value) {
        expect(BooleanNormalizer::isTrue($value))->toBeFalse();
    })->with([0.0, 2.0, 0.5, -1.0]);

    it('returns false for null')
        ->expect(fn () => BooleanNormalizer::isTrue(null))
        ->toBeFalse();

    it('returns false for array')
        ->expect(fn () => BooleanNormalizer::isTrue([]))
        ->toBeFalse();

    it('handles strings with whitespace', function () {
        expect(BooleanNormalizer::isTrue('  true  '))->toBeTrue();
        expect(BooleanNormalizer::isTrue('  yes  '))->toBeTrue();
        expect(BooleanNormalizer::isTrue('  1  '))->toBeTrue();
    });

    it('handles Stringable objects', function () {
        $stringable = new class implements Stringable
        {
            public function __toString(): string
            {
                return 'true';
            }
        };

        expect(BooleanNormalizer::isTrue($stringable))->toBeTrue();
    });
});

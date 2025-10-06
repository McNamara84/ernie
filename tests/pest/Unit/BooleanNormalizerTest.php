<?php

use App\Support\BooleanNormalizer;

it('detects truthy boolean values', function (mixed $value): void {
    expect(BooleanNormalizer::isTrue($value))->toBeTrue();
})->with([
    true,
    'true',
    'TRUE',
    '  true  ',
    '1',
    1,
    1.0,
    'ON',
    'Yes',
]);

it('detects falsey boolean values', function (mixed $value): void {
    expect(BooleanNormalizer::isTrue($value))->toBeFalse();
})->with([
    false,
    null,
    '',
    'false',
    'no',
    '0',
    0,
    0.0,
    new class {
        public function __toString(): string
        {
            return 'false';
        }
    },
]);

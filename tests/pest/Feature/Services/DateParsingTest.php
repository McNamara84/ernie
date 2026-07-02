<?php

use App\Services\DataCiteToResourceTransformer;

/**
 * Helper function to invoke the private parseDate method via reflection.
 */
function invokeParseDate(?string $date, bool $isEndDate = false): ?string
{
    $transformer = new DataCiteToResourceTransformer;
    $method = new ReflectionMethod($transformer, 'parseDate');

    return $method->invoke($transformer, $date, $isEndDate);
}

describe('DataCite date parsing without invented precision', function () {
    it('preserves year-only values for both start and end dates', function () {
        expect(invokeParseDate('2020', false))->toBe('2020')
            ->and(invokeParseDate('2020', true))->toBe('2020');
    });

    it('preserves year-month values for both start and end dates', function () {
        expect(invokeParseDate('2020-03', false))->toBe('2020-03')
            ->and(invokeParseDate('2020-03', true))->toBe('2020-03')
            ->and(invokeParseDate('2020-12', true))->toBe('2020-12');
    });

    it('returns full dates unchanged regardless of isEndDate', function () {
        expect(invokeParseDate('2020-03-15', false))->toBe('2020-03-15')
            ->and(invokeParseDate('2020-03-15', true))->toBe('2020-03-15');
    });

    it('preserves valid ISO datetime values when imported through the DataCite API', function () {
        expect(invokeParseDate('2023-05-15T09:35+02:00', false))->toBe('2023-05-15T09:35+02:00')
            ->and(invokeParseDate('2023-05-15 09:35:20Z', true))->toBe('2023-05-15 09:35:20Z');
    });

    it('returns null for empty input', function () {
        expect(invokeParseDate(null, false))->toBeNull()
            ->and(invokeParseDate(null, true))->toBeNull()
            ->and(invokeParseDate('', false))->toBeNull()
            ->and(invokeParseDate('', true))->toBeNull();
    });

    it('trims whitespace without changing precision', function () {
        expect(invokeParseDate('  2020  ', false))->toBe('2020')
            ->and(invokeParseDate('  2020-03  ', true))->toBe('2020-03');
    });

    it('rejects invalid months and invalid calendar dates instead of correcting them', function () {
        expect(invokeParseDate('2020-13', false))->toBeNull()
            ->and(invokeParseDate('2020-00', false))->toBeNull()
            ->and(invokeParseDate('2024-02-30', false))->toBeNull()
            ->and(invokeParseDate('2024-02-31', false))->toBeNull()
            ->and(invokeParseDate('2024-04-31', false))->toBeNull()
            ->and(invokeParseDate('2024-09-31', false))->toBeNull()
            ->and(invokeParseDate('2023-02-29', false))->toBeNull()
            ->and(invokeParseDate('2024-02-29', false))->toBe('2024-02-29');
    });
});

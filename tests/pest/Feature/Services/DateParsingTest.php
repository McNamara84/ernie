<?php

use App\Services\DataCiteToResourceTransformer;

/**
 * Helper function to invoke the private parseDate method via reflection.
 */
function invokeParseDate(?string $date): ?string
{
    $transformer = new DataCiteToResourceTransformer;
    $method = new ReflectionMethod($transformer, 'parseDate');

    return $method->invoke($transformer, $date);
}

describe('DataCite date parsing without invented precision', function () {
    it('preserves year-only values', function () {
        expect(invokeParseDate('2020'))->toBe('2020');
    });

    it('preserves year-month values', function () {
        expect(invokeParseDate('2020-03'))->toBe('2020-03')
            ->and(invokeParseDate('2020-12'))->toBe('2020-12');
    });

    it('returns full dates unchanged', function () {
        expect(invokeParseDate('2020-03-15'))->toBe('2020-03-15');
    });

    it('preserves valid ISO datetime values when imported through the DataCite API', function () {
        expect(invokeParseDate('2023-05-15T09:35+02:00'))->toBe('2023-05-15T09:35+02:00')
            ->and(invokeParseDate('2023-05-15 09:35:20Z'))->toBe('2023-05-15 09:35:20Z');
    });

    it('returns null for empty input', function () {
        expect(invokeParseDate(null))->toBeNull()
            ->and(invokeParseDate(''))->toBeNull();
    });

    it('trims whitespace without changing precision', function () {
        expect(invokeParseDate('  2020  '))->toBe('2020')
            ->and(invokeParseDate('  2020-03  '))->toBe('2020-03');
    });

    it('rejects invalid months and invalid calendar dates instead of correcting them', function () {
        expect(invokeParseDate('2020-13'))->toBeNull()
            ->and(invokeParseDate('2020-00'))->toBeNull()
            ->and(invokeParseDate('2024-02-30'))->toBeNull()
            ->and(invokeParseDate('2024-02-31'))->toBeNull()
            ->and(invokeParseDate('2024-04-31'))->toBeNull()
            ->and(invokeParseDate('2024-09-31'))->toBeNull()
            ->and(invokeParseDate('2023-02-29'))->toBeNull()
            ->and(invokeParseDate('2024-02-29'))->toBe('2024-02-29');
    });
});

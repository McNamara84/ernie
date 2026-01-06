<?php

use App\Services\DataCiteToResourceTransformer;

/**
 * Helper function to invoke the private parseDate method via reflection.
 *
 * @param  string|null  $date  The date string to parse
 * @param  bool  $isEndDate  Whether this is an end date
 * @return string|null The parsed date
 */
function invokeParseDate(?string $date, bool $isEndDate = false): ?string
{
    $transformer = new DataCiteToResourceTransformer();
    $method = new ReflectionMethod($transformer, 'parseDate');
    $method->setAccessible(true);

    return $method->invoke($transformer, $date, $isEndDate);
}

describe('Date Parsing with isEndDate parameter', function () {
    describe('Year-only format (YYYY)', function () {
        it('parses start date as January 1st', function () {
            expect(invokeParseDate('2020', false))->toBe('2020-01-01');
        });

        it('parses end date as December 31st', function () {
            expect(invokeParseDate('2020', true))->toBe('2020-12-31');
        });
    });

    describe('Year-month format (YYYY-MM)', function () {
        it('parses start date as first day of month', function () {
            expect(invokeParseDate('2020-03', false))->toBe('2020-03-01');
            expect(invokeParseDate('2020-06', false))->toBe('2020-06-01');
        });

        it('parses end date as last day of month', function () {
            expect(invokeParseDate('2020-03', true))->toBe('2020-03-31');
            expect(invokeParseDate('2020-06', true))->toBe('2020-06-30');
        });

        it('handles February correctly in leap year', function () {
            expect(invokeParseDate('2020-02', true))->toBe('2020-02-29');
        });

        it('handles February correctly in non-leap year', function () {
            expect(invokeParseDate('2021-02', true))->toBe('2021-02-28');
        });

        it('handles months with 31 days', function () {
            expect(invokeParseDate('2020-01', true))->toBe('2020-01-31');
            expect(invokeParseDate('2020-05', true))->toBe('2020-05-31');
            expect(invokeParseDate('2020-07', true))->toBe('2020-07-31');
            expect(invokeParseDate('2020-08', true))->toBe('2020-08-31');
            expect(invokeParseDate('2020-10', true))->toBe('2020-10-31');
            expect(invokeParseDate('2020-12', true))->toBe('2020-12-31');
        });

        it('handles months with 30 days', function () {
            expect(invokeParseDate('2020-04', true))->toBe('2020-04-30');
            expect(invokeParseDate('2020-06', true))->toBe('2020-06-30');
            expect(invokeParseDate('2020-09', true))->toBe('2020-09-30');
            expect(invokeParseDate('2020-11', true))->toBe('2020-11-30');
        });
    });

    describe('Full date format (YYYY-MM-DD)', function () {
        it('returns date unchanged regardless of isEndDate', function () {
            expect(invokeParseDate('2020-03-15', false))->toBe('2020-03-15');
            expect(invokeParseDate('2020-03-15', true))->toBe('2020-03-15');
        });
    });

    describe('Edge cases', function () {
        it('returns null for null input', function () {
            expect(invokeParseDate(null, false))->toBeNull();
            expect(invokeParseDate(null, true))->toBeNull();
        });

        it('returns null for empty string', function () {
            expect(invokeParseDate('', false))->toBeNull();
            expect(invokeParseDate('', true))->toBeNull();
        });

        it('handles whitespace-padded dates', function () {
            expect(invokeParseDate('  2020  ', false))->toBe('2020-01-01');
            expect(invokeParseDate('  2020-03  ', true))->toBe('2020-03-31');
        });

        it('rejects invalid months', function () {
            expect(invokeParseDate('2020-13', false))->toBeNull();
            expect(invokeParseDate('2020-00', false))->toBeNull();
        });
    });
});

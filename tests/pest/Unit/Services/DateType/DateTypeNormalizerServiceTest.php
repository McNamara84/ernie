<?php

declare(strict_types=1);

use App\Services\DateType\DateTypeNormalizerService;

covers(DateTypeNormalizerService::class);

it('keeps valid DataCite date values', function () {
    expect(DateTypeNormalizerService::normalize('2016'))->toBe('2016')
        ->and(DateTypeNormalizerService::normalize(' 2016 '))->toBe('2016')
        ->and(DateTypeNormalizerService::normalize('2016-07'))->toBe('2016-07')
        ->and(DateTypeNormalizerService::normalize(' 2016-07 '))->toBe('2016-07')
        ->and(DateTypeNormalizerService::normalize('2016-07-04'))->toBe('2016-07-04')
        ->and(DateTypeNormalizerService::normalize(' 2016-07-04 '))->toBe('2016-07-04')
        ->and(DateTypeNormalizerService::normalize('2024-02-29'))->toBe('2024-02-29')
        ->and(DateTypeNormalizerService::normalize('2016-07-04T12:30'))->toBe('2016-07-04T12:30')
        ->and(DateTypeNormalizerService::normalize(' 2016-07-04T12:30 '))->toBe('2016-07-04T12:30')
        ->and(DateTypeNormalizerService::normalize('2016-07-04T12:30:00Z'))->toBe('2016-07-04T12:30:00Z')
        ->and(DateTypeNormalizerService::normalize(' 2016-07-04T12:30:00Z '))->toBe('2016-07-04T12:30:00Z');
});

it('normalizes non DataCite date formats', function () {
    expect(DateTypeNormalizerService::normalize('03.07.2016'))->toBe('2016-07-03')
        ->and(DateTypeNormalizerService::normalize('03.07.16'))->toBe('2016-07-03')
        ->and(DateTypeNormalizerService::normalize('2016.07.03'))->toBe('2016-07-03')
        ->and(DateTypeNormalizerService::normalize('3.7.2016'))->toBe('2016-07-03')
        ->and(DateTypeNormalizerService::normalize('2016.7.3'))->toBe('2016-07-03')
        ->and(DateTypeNormalizerService::normalize('3/7/2016'))->toBe('2016-07-03')
        ->and(DateTypeNormalizerService::normalize('2016/7/3'))->toBe('2016-07-03')
        ->and(DateTypeNormalizerService::normalize('03/07/2016'))->toBe('2016-07-03')
        ->and(DateTypeNormalizerService::normalize('2016/07/03'))->toBe('2016-07-03')
        ->and(DateTypeNormalizerService::normalize('2016-7-3'))->toBe('2016-07-03');
});


it('normalizes complete date ranges', function () {
    expect(DateTypeNormalizerService::normalize('2010/2016'))->toBe('2010/2016')
        ->and(DateTypeNormalizerService::normalize('2010-05/2016-06'))->toBe('2010-05/2016-06')
        ->and(DateTypeNormalizerService::normalize('2016-01-22/2016-04-12'))->toBe('2016-01-22/2016-04-12')
        ->and(DateTypeNormalizerService::normalize('2010-01-22/2016-07-04T12:30:00Z'))->toBe('2010-01-22/2016-07-04T12:30:00Z')
        ->and(DateTypeNormalizerService::normalize('2010-01-22T12:30:00Z/2016-07-04'))->toBe('2010-01-22T12:30:00Z/2016-07-04')
        ->and(DateTypeNormalizerService::normalize('2010-01-22T12:30:00Z/2016-07-04T15:30:00Z'))->toBe('2010-01-22T12:30:00Z/2016-07-04T15:30:00Z');
});

it('rejects invalid or unsupported date values', function () {
    expect(DateTypeNormalizerService::normalize(null))->toBeNull()
        ->and(DateTypeNormalizerService::normalize(''))->toBeNull()
        ->and(DateTypeNormalizerService::normalize('abc'))->toBeNull()
        ->and(DateTypeNormalizerService::normalize('2016-13'))->toBeNull()
        ->and(DateTypeNormalizerService::normalize('2016-02-31'))->toBeNull()
        ->and(DateTypeNormalizerService::normalize('/2016-12-31'))->toBeNull()
        ->and(DateTypeNormalizerService::normalize('/2016-12'))->toBeNull()
        ->and(DateTypeNormalizerService::normalize('/2016'))->toBeNull()
        ->and(DateTypeNormalizerService::normalize('2016/'))->toBeNull()
        ->and(DateTypeNormalizerService::normalize('2016-12/'))->toBeNull()
        ->and(DateTypeNormalizerService::normalize('2016-12-31/'))->toBeNull()
        ->and(DateTypeNormalizerService::normalize('2026-02-29'))->toBeNull()
        ->and(DateTypeNormalizerService::normalize('2016-00'))->toBeNull()
        ->and(DateTypeNormalizerService::normalize('2016-12-32'))->toBeNull()
        ->and(DateTypeNormalizerService::normalize('/2'))->toBeNull()
        ->and(DateTypeNormalizerService::normalize('Before 2016-12-12'))->toBeNull();
});


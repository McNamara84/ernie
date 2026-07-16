<?php

declare(strict_types=1);

use App\Services\DataCite\DataCiteDateFormatService;

describe('DataCiteDateFormatService', function () {
    it('accepts DataCite publication years', function (string $year) {
        expect(DataCiteDateFormatService::isPublicationYear($year))->toBeTrue();
    })->with([
        'year 0000' => '0000',
        'historic year' => '1957',
        'current year' => '2026',
        'future year' => '9999',
    ]);

    it('rejects invalid publication years', function (string $year) {
        expect(DataCiteDateFormatService::isPublicationYear($year))->toBeFalse();
    })->with([
        'too short' => '999',
        'too long' => '10000',
        'signed' => '-0054',
        'month precision' => '2026-07',
        'whitespace' => ' 2026',
        'non numeric' => '20A6',
    ]);

    it('accepts W3CDTF dates and RKMS ISO 8601 ranges', function (string $date) {
        expect(DataCiteDateFormatService::isDate($date))->toBeTrue();
    })->with([
        'year' => '2026',
        'negative year' => '-0054',
        'year month' => '2026-07',
        'complete date' => '2024-02-29',
        'hours and minutes' => '2026-07-16T12:30Z',
        'seconds' => '2026-07-16T12:30:45+02:00',
        'fractional seconds' => '2026-07-16T12:30:45.123-05:30',
        'maximum positive timezone offset' => '2026-07-16T12:30+14:00',
        'maximum negative timezone offset' => '2026-07-16T12:30-14:00',
        'timezone offset below maximum with minutes' => '2026-07-16T12:30+13:59',
        'closed year range' => '1997/1998',
        'closed month range' => '1997-07/1997-08',
        'closed date range' => '2004-03-02/2005-06-02',
        'mixed precision range' => '1997/1998-07-16',
        'open end range' => '1997-07-16/',
        'open start range' => '/1997-07-16T19:30+10:00',
    ]);

    it('rejects malformed or impossible DataCite dates', function (string $date) {
        expect(DataCiteDateFormatService::isDate($date))->toBeFalse();
    })->with([
        'empty' => '',
        'both range ends empty' => '/',
        'too many range separators' => '2020/2021/2022',
        'un-padded month' => '2026-7',
        'invalid month' => '2026-13',
        'invalid day' => '2026-04-31',
        'invalid non-leap day' => '2023-02-29',
        'time without timezone' => '2026-07-16T12:30:45',
        'invalid hour' => '2026-07-16T24:00Z',
        'invalid minute' => '2026-07-16T23:60Z',
        'invalid second' => '2026-07-16T23:59:60Z',
        'timezone hour above maximum' => '2026-07-16T12:30+15:00',
        'timezone minutes above maximum boundary' => '2026-07-16T12:30+14:01',
        'negative timezone above maximum boundary' => '2026-07-16T12:30-14:30',
        'extreme timezone offset' => '2026-07-16T12:30+23:00',
        'invalid timezone hour' => '2026-07-16T12:30+24:00',
        'space separator' => '2026-07-16 12:30Z',
        'surrounding whitespace' => ' 2026-07-16',
    ]);
});

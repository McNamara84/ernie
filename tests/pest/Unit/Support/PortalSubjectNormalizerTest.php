<?php

declare(strict_types=1);

use App\Support\PortalSubjectNormalizer;
use Illuminate\Support\Facades\DB;

covers(PortalSubjectNormalizer::class);

describe('PortalSubjectNormalizer::normalizeControlledSubjectValue()', function () {
    it('normalizes legacy encoded breadcrumb separators case-insensitively', function () {
        expect(PortalSubjectNormalizer::normalizeControlledSubjectValue(
            '  EARTH SCIENCE &GT; SOLID EARTH &AMP;GT SEISMOLOGY  ',
        ))->toBe('EARTH SCIENCE > SOLID EARTH > SEISMOLOGY');
    });
});

describe('PortalSubjectNormalizer::normalizedControlledSubjectValueSql()', function () {
    it('uses CHAR() on sqlite-compatible drivers', function () {
        $sql = PortalSubjectNormalizer::normalizedControlledSubjectValueSql('value', 'sqlite');

        expect($sql)
            ->toContain('CHAR(13)')
            ->toContain('CHAR(10)')
            ->toContain('CHAR(9)')
            ->not->toContain('CHR(13)');
    });

    it('normalizes legacy encoded breadcrumb separators on sqlite with php parity', function () {
        $sql = PortalSubjectNormalizer::normalizedControlledSubjectValueSql('?', 'sqlite');
        $row = DB::selectOne(
            "SELECT {$sql} AS normalized",
            ['  EARTH SCIENCE &GT; SOLID EARTH &AMP;GT SEISMOLOGY  '],
        );

        expect($row)->not->toBeNull();
        expect(is_object($row))->toBeTrue();
        expect($row->normalized ?? null)->toBe('earth science > solid earth > seismology');
    });

    it('uses CHR() on pgsql', function () {
        $sql = PortalSubjectNormalizer::normalizedControlledSubjectValueSql('value', 'pgsql');

        expect($sql)
            ->toContain('CHR(13)')
            ->toContain('CHR(10)')
            ->toContain('CHR(9)')
            ->not->toContain('CHAR(13)');
    });
});
<?php

declare(strict_types=1);

use App\Support\PortalSubjectNormalizer;

covers(PortalSubjectNormalizer::class);

describe('PortalSubjectNormalizer::normalizedControlledSubjectValueSql()', function () {
    it('uses CHAR() on sqlite-compatible drivers', function () {
        $sql = PortalSubjectNormalizer::normalizedControlledSubjectValueSql('value', 'sqlite');

        expect($sql)
            ->toContain('CHAR(13)')
            ->toContain('CHAR(10)')
            ->toContain('CHAR(9)')
            ->not->toContain('CHR(13)');
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
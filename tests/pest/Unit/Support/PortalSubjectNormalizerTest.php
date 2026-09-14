<?php

declare(strict_types=1);

use App\Support\LegacyMslScheme;
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

describe('PortalSubjectNormalizer::normalizeScheme()', function () {
    it('normalizes all exact legacy EPOS WP16 schemes to the MSL presentation group', function (): void {
        foreach (LegacyMslScheme::schemes() as $scheme) {
            expect(PortalSubjectNormalizer::normalizeScheme($scheme))->toBe('EPOS MSL vocabulary');
        }
    });

    it('does not claim similarly named unknown schemes as MSL', function (): void {
        expect(PortalSubjectNormalizer::normalizeScheme('EPOS WP16 Analogue Unknown'))
            ->toBe('EPOS WP16 Analogue Unknown');
    });

    it('normalizes exact legacy EPOS WP16 schemes in SQL with PHP parity', function (): void {
        $sql = PortalSubjectNormalizer::normalizedSchemeSql('scheme');
        $row = DB::selectOne(
            "SELECT {$sql} AS normalized FROM (SELECT ? AS scheme)",
            ['EPOS WP16 Rock Physics Process/Hazard'],
        );

        expect($row?->normalized)->toBe('epos msl vocabulary');
    });
});

describe('PortalSubjectNormalizer legacy MSL URI aliases', function () {
    it('only aliases explicitly mapped source scheme and URI identities', function (): void {
        $currentUri = 'https://epos-msl.uu.nl/voc/materials/1.3/igneous_rock_-_intrusive-acidic_intrusive-granite';

        expect(PortalSubjectNormalizer::currentMslNodeUriForLegacyUri(
            'EPOS WP16 Analogue Material',
            'http://epos/WP16Vocabulary/AnalogueMaterial/Rock/Granite',
        ))->toBe($currentUri)
            ->and(PortalSubjectNormalizer::currentMslNodeUriForLegacyUri(
                'EPOS WP16 Rock Physics Material',
                'http://epos/WP16Vocabulary/RockPhysicsMaterial/Rock/Granite',
            ))->toBeNull()
            ->and(PortalSubjectNormalizer::currentMslNodeUriForLegacyUri(
                'EPOS WP16 Analogue Material',
                'https://legacy.example/unmapped/granite',
            ))->toBeNull()
            ->and(PortalSubjectNormalizer::legacyMslUriAliasesForCurrentNodeUris([$currentUri]))
            ->toBe([[
                'scheme' => 'epos wp16 analogue material',
                'value_uri' => 'http://epos/WP16Vocabulary/AnalogueMaterial/Rock/Granite',
            ]]);
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

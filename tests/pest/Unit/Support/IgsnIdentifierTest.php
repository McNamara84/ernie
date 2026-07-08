<?php

declare(strict_types=1);

use App\Support\IgsnIdentifier;
use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    Config::set('datacite.production.igsn_prefix', '10.60510');
});

describe('IgsnIdentifier', function (): void {
    it('normalizes supported IGSN input formats to lowercase DOI form', function (): void {
        expect(IgsnIdentifier::normalizeInputToDoi(' ICDP5052EUYY001 '))->toBe('10.60510/icdp5052euyy001')
            ->and(IgsnIdentifier::normalizeInputToDoi('10.60510/ICDP5052EUYY001'))->toBe('10.60510/icdp5052euyy001')
            ->and(IgsnIdentifier::normalizeInputToDoi('https://doi.org/10.60510/ICDP5052EUYY001'))->toBe('10.60510/icdp5052euyy001')
            ->and(IgsnIdentifier::normalizeInputToDoi('https://dx.doi.org/10.60510/ICDP5052EUYY001'))->toBe('10.60510/icdp5052euyy001');
    });

    it('rejects empty, non-string, invalid and foreign DOI inputs', function (): void {
        expect(IgsnIdentifier::normalizeInputToDoi(null))->toBeNull()
            ->and(IgsnIdentifier::normalizeInputToDoi(['ICDP5052EUYY001']))->toBeNull()
            ->and(IgsnIdentifier::normalizeInputToDoi('   '))->toBeNull()
            ->and(IgsnIdentifier::normalizeInputToDoi('not an igsn'))->toBeNull()
            ->and(IgsnIdentifier::normalizeInputToDoi('10.99999/ICDP5052EUYY001'))->toBeNull();
    });

    it('supports numeric handles and explicit custom prefixes', function (): void {
        expect(IgsnIdentifier::normalizeInputToDoi(12345))->toBe('10.60510/12345')
            ->and(IgsnIdentifier::normalizeInputToDoi('ABC001', ' 10.12345 '))->toBe('10.12345/abc001')
            ->and(IgsnIdentifier::doiFromHandle(' ABC001 ', '10.12345'))->toBe('10.12345/abc001');
    });

    it('extracts uppercase handles from configured-prefix DOIs', function (): void {
        expect(IgsnIdentifier::handleFromDoi('10.60510/icdp5052euyy001'))->toBe('ICDP5052EUYY001')
            ->and(IgsnIdentifier::handleFromDoi('10.99999/icdp5052euyy001'))->toBeNull();
    });

    it('validates handle syntax after trimming', function (): void {
        expect(IgsnIdentifier::isValidHandle(' ABC-001.2_3 '))->toBeTrue()
            ->and(IgsnIdentifier::isValidHandle('-ABC'))->toBeFalse()
            ->and(IgsnIdentifier::isValidHandle(str_repeat('A', 201)))->toBeFalse();
    });
});

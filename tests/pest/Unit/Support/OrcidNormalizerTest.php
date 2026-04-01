<?php

declare(strict_types=1);

use App\Support\OrcidNormalizer;

covers(OrcidNormalizer::class);

describe('extractBareId', function (): void {
    it('returns bare ORCID unchanged', function (): void {
        expect(OrcidNormalizer::extractBareId('0000-0002-1825-0097'))->toBe('0000-0002-1825-0097');
    });

    it('strips https://orcid.org/ prefix', function (): void {
        expect(OrcidNormalizer::extractBareId('https://orcid.org/0000-0002-1825-0097'))->toBe('0000-0002-1825-0097');
    });

    it('strips http://orcid.org/ prefix', function (): void {
        expect(OrcidNormalizer::extractBareId('http://orcid.org/0000-0002-1825-0097'))->toBe('0000-0002-1825-0097');
    });

    it('strips https://www.orcid.org/ prefix', function (): void {
        expect(OrcidNormalizer::extractBareId('https://www.orcid.org/0000-0002-1825-0097'))->toBe('0000-0002-1825-0097');
    });

    it('strips http://www.orcid.org/ prefix', function (): void {
        expect(OrcidNormalizer::extractBareId('http://www.orcid.org/0000-0002-1825-0097'))->toBe('0000-0002-1825-0097');
    });

    it('handles case-insensitive prefixes', function (): void {
        expect(OrcidNormalizer::extractBareId('HTTPS://ORCID.ORG/0000-0002-1825-0097'))->toBe('0000-0002-1825-0097');
    });

    it('trims whitespace', function (): void {
        expect(OrcidNormalizer::extractBareId('  0000-0002-1825-0097  '))->toBe('0000-0002-1825-0097');
    });
});

describe('toUrl', function (): void {
    it('converts bare ORCID to canonical URL', function (): void {
        expect(OrcidNormalizer::toUrl('0000-0002-1825-0097'))->toBe('https://orcid.org/0000-0002-1825-0097');
    });

    it('normalizes www variant to canonical URL', function (): void {
        expect(OrcidNormalizer::toUrl('https://www.orcid.org/0000-0002-1825-0097'))->toBe('https://orcid.org/0000-0002-1825-0097');
    });

    it('normalizes http variant to canonical https URL', function (): void {
        expect(OrcidNormalizer::toUrl('http://orcid.org/0000-0002-1825-0097'))->toBe('https://orcid.org/0000-0002-1825-0097');
    });
});

describe('isValidFormat', function (): void {
    it('accepts valid bare ORCID', function (): void {
        expect(OrcidNormalizer::isValidFormat('0000-0002-1825-0097'))->toBeTrue();
    });

    it('accepts ORCID with X check digit', function (): void {
        expect(OrcidNormalizer::isValidFormat('0000-0001-2345-678X'))->toBeTrue();
    });

    it('accepts ORCID with lowercase x check digit', function (): void {
        expect(OrcidNormalizer::isValidFormat('0000-0001-2345-678x'))->toBeTrue();
    });

    it('accepts ORCID as URL', function (): void {
        expect(OrcidNormalizer::isValidFormat('https://orcid.org/0000-0002-1825-0097'))->toBeTrue();
    });

    it('accepts ORCID as www URL', function (): void {
        expect(OrcidNormalizer::isValidFormat('https://www.orcid.org/0000-0002-1825-0097'))->toBeTrue();
    });

    it('rejects malformed strings', function (): void {
        expect(OrcidNormalizer::isValidFormat('not-an-orcid'))->toBeFalse();
        expect(OrcidNormalizer::isValidFormat('1234'))->toBeFalse();
        expect(OrcidNormalizer::isValidFormat(''))->toBeFalse();
    });
});

describe('isValidChecksum', function (): void {
    it('accepts valid checksum', function (): void {
        expect(OrcidNormalizer::isValidChecksum('0000-0002-1825-0097'))->toBeTrue();
    });

    it('rejects invalid checksum', function (): void {
        expect(OrcidNormalizer::isValidChecksum('0000-0002-1825-0000'))->toBeFalse();
    });

    it('validates checksum from URL format', function (): void {
        expect(OrcidNormalizer::isValidChecksum('https://www.orcid.org/0000-0002-1825-0097'))->toBeTrue();
    });

    it('rejects non-ORCID strings', function (): void {
        expect(OrcidNormalizer::isValidChecksum('not-valid'))->toBeFalse();
    });
});

describe('isValid', function (): void {
    it('accepts valid ORCID', function (): void {
        expect(OrcidNormalizer::isValid('0000-0002-1825-0097'))->toBeTrue();
    });

    it('accepts valid ORCID URL', function (): void {
        expect(OrcidNormalizer::isValid('https://orcid.org/0000-0002-1825-0097'))->toBeTrue();
    });

    it('accepts valid www ORCID URL', function (): void {
        expect(OrcidNormalizer::isValid('https://www.orcid.org/0000-0002-1825-0097'))->toBeTrue();
    });

    it('rejects valid format with bad checksum', function (): void {
        expect(OrcidNormalizer::isValid('0000-0002-1825-0000'))->toBeFalse();
    });

    it('rejects malformed string', function (): void {
        expect(OrcidNormalizer::isValid('not-an-orcid'))->toBeFalse();
    });
});

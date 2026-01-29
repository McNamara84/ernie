<?php

declare(strict_types=1);

use App\Support\FunderIdentifierTypeDetector;

describe('FunderIdentifierTypeDetector', function () {
    describe('detect()', function () {
        it('returns null for null input', function () {
            expect(FunderIdentifierTypeDetector::detect(null))->toBeNull();
        });

        it('returns null for empty string', function () {
            expect(FunderIdentifierTypeDetector::detect(''))->toBeNull();
        });

        it('returns null for whitespace-only string', function () {
            expect(FunderIdentifierTypeDetector::detect('   '))->toBeNull();
        });

        describe('ROR detection', function () {
            it('detects ROR from full URL with https', function () {
                expect(FunderIdentifierTypeDetector::detect('https://ror.org/02t274463'))
                    ->toBe('ROR');
            });

            it('detects ROR from URL without scheme', function () {
                expect(FunderIdentifierTypeDetector::detect('ror.org/02t274463'))
                    ->toBe('ROR');
            });

            it('detects ROR from URL with www', function () {
                expect(FunderIdentifierTypeDetector::detect('https://www.ror.org/02t274463'))
                    ->toBe('ROR');
            });

            it('detects ROR from URL with http (not https)', function () {
                expect(FunderIdentifierTypeDetector::detect('http://ror.org/018mejw64'))
                    ->toBe('ROR');
            });

            it('detects ROR with mixed case', function () {
                expect(FunderIdentifierTypeDetector::detect('HTTPS://ROR.ORG/02t274463'))
                    ->toBe('ROR');
            });
        });

        describe('Crossref Funder ID detection', function () {
            it('detects Crossref Funder ID from full URL', function () {
                expect(FunderIdentifierTypeDetector::detect('https://doi.org/10.13039/501100000780'))
                    ->toBe('Crossref Funder ID');
            });

            it('detects Crossref Funder ID without scheme', function () {
                expect(FunderIdentifierTypeDetector::detect('doi.org/10.13039/100000001'))
                    ->toBe('Crossref Funder ID');
            });

            it('detects Crossref Funder ID with www', function () {
                expect(FunderIdentifierTypeDetector::detect('https://www.doi.org/10.13039/501100000780'))
                    ->toBe('Crossref Funder ID');
            });

            it('does not detect regular DOI as Crossref Funder ID', function () {
                expect(FunderIdentifierTypeDetector::detect('https://doi.org/10.5880/GFZ.1.2.2024.001'))
                    ->toBe('Other');
            });

            it('does not detect DOI with different prefix as Crossref Funder ID', function () {
                expect(FunderIdentifierTypeDetector::detect('https://doi.org/10.1234/example'))
                    ->toBe('Other');
            });
        });

        describe('ISNI detection', function () {
            it('detects ISNI from URL', function () {
                expect(FunderIdentifierTypeDetector::detect('https://isni.org/isni/0000000121032683'))
                    ->toBe('ISNI');
            });

            it('detects ISNI from URL without scheme', function () {
                expect(FunderIdentifierTypeDetector::detect('isni.org/isni/0000000121032683'))
                    ->toBe('ISNI');
            });

            it('detects ISNI from formatted string with spaces', function () {
                expect(FunderIdentifierTypeDetector::detect('0000 0001 2162 673X'))
                    ->toBe('ISNI');
            });

            it('detects ISNI from raw 16-character string', function () {
                expect(FunderIdentifierTypeDetector::detect('000000012162673X'))
                    ->toBe('ISNI');
            });

            it('detects ISNI with lowercase x', function () {
                expect(FunderIdentifierTypeDetector::detect('0000 0001 2162 673x'))
                    ->toBe('ISNI');
            });

            it('detects ISNI with hyphens', function () {
                expect(FunderIdentifierTypeDetector::detect('0000-0001-2162-673X'))
                    ->toBe('ISNI');
            });

            it('detects ISNI ending with digit (not X)', function () {
                expect(FunderIdentifierTypeDetector::detect('0000000121626730'))
                    ->toBe('ISNI');
            });
        });

        describe('GRID detection', function () {
            it('detects GRID from full URL', function () {
                expect(FunderIdentifierTypeDetector::detect('https://www.grid.ac/institutes/grid.123456.7'))
                    ->toBe('GRID');
            });

            it('detects GRID without www', function () {
                expect(FunderIdentifierTypeDetector::detect('https://grid.ac/institutes/grid.4991.5'))
                    ->toBe('GRID');
            });

            it('detects GRID without scheme', function () {
                expect(FunderIdentifierTypeDetector::detect('grid.ac/institutes/grid.12345.a'))
                    ->toBe('GRID');
            });
        });

        describe('Other (fallback)', function () {
            it('returns Other for unknown URL', function () {
                expect(FunderIdentifierTypeDetector::detect('https://example.com/funder/123'))
                    ->toBe('Other');
            });

            it('returns Other for plain text identifier', function () {
                expect(FunderIdentifierTypeDetector::detect('FUNDER-12345'))
                    ->toBe('Other');
            });

            it('returns Other for random string', function () {
                expect(FunderIdentifierTypeDetector::detect('some random identifier'))
                    ->toBe('Other');
            });

            it('returns Other for partial ISNI (too short)', function () {
                expect(FunderIdentifierTypeDetector::detect('0000 0001 2162'))
                    ->toBe('Other');
            });

            it('returns Other for numeric string that is not ISNI length', function () {
                expect(FunderIdentifierTypeDetector::detect('12345678901234567890'))
                    ->toBe('Other');
            });
        });
    });

    describe('constants', function () {
        it('has correct TYPE_ROR constant', function () {
            expect(FunderIdentifierTypeDetector::TYPE_ROR)->toBe('ROR');
        });

        it('has correct TYPE_CROSSREF constant', function () {
            expect(FunderIdentifierTypeDetector::TYPE_CROSSREF)->toBe('Crossref Funder ID');
        });

        it('has correct TYPE_ISNI constant', function () {
            expect(FunderIdentifierTypeDetector::TYPE_ISNI)->toBe('ISNI');
        });

        it('has correct TYPE_GRID constant', function () {
            expect(FunderIdentifierTypeDetector::TYPE_GRID)->toBe('GRID');
        });

        it('has correct TYPE_OTHER constant', function () {
            expect(FunderIdentifierTypeDetector::TYPE_OTHER)->toBe('Other');
        });
    });
});

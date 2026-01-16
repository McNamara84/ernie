import { describe, expect, it } from 'vitest';

import { detectIdentifierType, normalizeIdentifier } from '@/lib/identifier-type-detection';

describe('detectIdentifierType', () => {
    describe('DOI Detection', () => {
        describe('bare DOI format (10.prefix/suffix)', () => {
            it('detects standard DOI with 4-digit registrant code', () => {
                expect(detectIdentifierType('10.5880/fidgeo.2025.072')).toBe('DOI');
            });

            it('detects DOI with alphanumeric suffix', () => {
                expect(detectIdentifierType('10.5880/digis.2025.005')).toBe('DOI');
            });

            it('detects DOI with uppercase characters in suffix', () => {
                expect(detectIdentifierType('10.5880/GFZ.DMJQ.2025.005')).toBe('DOI');
            });

            it('detects DOI with mixed case and hyphens', () => {
                expect(detectIdentifierType('10.5194/egusphere-egu25-20132')).toBe('DOI');
            });

            it('detects DOI with journal-style naming', () => {
                expect(detectIdentifierType('10.1371/journal.pbio.0020449')).toBe('DOI');
            });

            it('detects DOI with year-based suffix', () => {
                expect(detectIdentifierType('10.1029/2015EO022207')).toBe('DOI');
            });

            it('detects DOI with 5-digit registrant code', () => {
                expect(detectIdentifierType('10.12345/example.2025')).toBe('DOI');
            });

            it('detects DOI with long registrant code', () => {
                expect(detectIdentifierType('10.123456789/suffix')).toBe('DOI');
            });
        });

        describe('DOI with https://doi.org/ prefix', () => {
            it('detects DOI URL with standard format', () => {
                expect(detectIdentifierType('https://doi.org/10.5880/fidgeo.2026.001')).toBe('DOI');
            });

            it('detects DOI URL with complex suffix', () => {
                expect(detectIdentifierType('https://doi.org/10.1029/2015EO022207')).toBe('DOI');
            });

            it('detects DOI URL with http:// prefix', () => {
                expect(detectIdentifierType('http://doi.org/10.5880/test.2025.001')).toBe('DOI');
            });

            it('detects DOI URL case-insensitively', () => {
                expect(detectIdentifierType('HTTPS://DOI.ORG/10.5880/test.2025.001')).toBe('DOI');
            });
        });

        describe('DOI with https://dx.doi.org/ prefix', () => {
            it('detects legacy dx.doi.org URLs', () => {
                expect(detectIdentifierType('https://dx.doi.org/10.5880/fidgeo.2025.072')).toBe('DOI');
            });

            it('detects http dx.doi.org URLs', () => {
                expect(detectIdentifierType('http://dx.doi.org/10.1371/journal.pbio.0020449')).toBe('DOI');
            });
        });

        describe('DOI with doi: prefix', () => {
            it('detects DOI with lowercase doi: prefix', () => {
                expect(detectIdentifierType('doi:10.1371/journal.pbio.0020449')).toBe('DOI');
            });

            it('detects DOI with uppercase DOI: prefix', () => {
                expect(detectIdentifierType('DOI:10.5880/fidgeo.2025.072')).toBe('DOI');
            });

            it('detects DOI with mixed case Doi: prefix', () => {
                expect(detectIdentifierType('Doi:10.5194/egusphere-egu25-20132')).toBe('DOI');
            });
        });

        describe('DOI edge cases and special characters', () => {
            it('detects DOI with parentheses in suffix', () => {
                expect(detectIdentifierType('10.1000/xyz(2023)001')).toBe('DOI');
            });

            it('detects DOI with underscores', () => {
                expect(detectIdentifierType('10.5880/fidgeo_special_2025')).toBe('DOI');
            });

            it('detects DOI with colons in suffix', () => {
                expect(detectIdentifierType('10.5880/gfz:data:2025')).toBe('DOI');
            });

            it('detects DOI with semicolons in suffix', () => {
                expect(detectIdentifierType('10.1234/test;version=1')).toBe('DOI');
            });

            it('detects DOI with hash in suffix', () => {
                expect(detectIdentifierType('10.1234/test#section')).toBe('DOI');
            });

            it('detects DOI with question mark in suffix', () => {
                expect(detectIdentifierType('10.1234/test?query=value')).toBe('DOI');
            });

            it('handles DOI with leading/trailing whitespace', () => {
                expect(detectIdentifierType('  10.5880/fidgeo.2025.072  ')).toBe('DOI');
            });

            it('handles DOI URL with leading/trailing whitespace', () => {
                expect(detectIdentifierType('  https://doi.org/10.5880/fidgeo.2025.072  ')).toBe('DOI');
            });
        });

        describe('real-world DOI examples from user requirements', () => {
            // These are the specific examples provided by the user
            const realWorldDois = [
                { input: '10.5880/fidgeo.2025.072', description: 'FID GEO DOI' },
                { input: 'https://doi.org/10.5880/fidgeo.2026.001', description: 'FID GEO DOI with URL' },
                { input: '10.5880/digis.2025.005', description: 'DIGIS DOI' },
                { input: 'https://doi.org/10.1029/2015EO022207', description: 'AGU publication DOI with URL' },
                { input: '10.5880/GFZ.DMJQ.2025.005', description: 'GFZ DOI with uppercase' },
                { input: '10.5194/egusphere-egu25-20132', description: 'EGU abstract DOI' },
                { input: 'doi:10.1371/journal.pbio.0020449', description: 'PLOS Biology DOI with prefix' },
                { input: '10.1371/journal.pbio.0020449', description: 'PLOS Biology bare DOI' },
            ];

            realWorldDois.forEach(({ input, description }) => {
                it(`detects ${description}: ${input}`, () => {
                    expect(detectIdentifierType(input)).toBe('DOI');
                });
            });
        });

        describe('DOI should NOT be detected for non-DOI identifiers', () => {
            it('should not detect plain URLs as DOI', () => {
                expect(detectIdentifierType('https://example.com/path')).not.toBe('DOI');
            });

            it('should not detect handles as DOI', () => {
                expect(detectIdentifierType('11234/56789')).not.toBe('DOI');
            });

            it('should not detect handle URLs as DOI', () => {
                expect(detectIdentifierType('https://hdl.handle.net/11234/56789')).not.toBe('DOI');
            });

            it('should not detect text with spaces as DOI', () => {
                expect(detectIdentifierType('10.5880 with spaces')).not.toBe('DOI');
            });
        });
    });
});

describe('normalizeIdentifier', () => {
    describe('DOI normalization', () => {
        it('removes https://doi.org/ prefix', () => {
            expect(normalizeIdentifier('https://doi.org/10.5880/fidgeo.2025.072', 'DOI')).toBe(
                '10.5880/fidgeo.2025.072',
            );
        });

        it('removes http://doi.org/ prefix', () => {
            expect(normalizeIdentifier('http://doi.org/10.5880/fidgeo.2025.072', 'DOI')).toBe(
                '10.5880/fidgeo.2025.072',
            );
        });

        it('removes https://dx.doi.org/ prefix', () => {
            expect(normalizeIdentifier('https://dx.doi.org/10.5880/fidgeo.2025.072', 'DOI')).toBe(
                '10.5880/fidgeo.2025.072',
            );
        });

        it('removes doi: prefix', () => {
            expect(normalizeIdentifier('doi:10.1371/journal.pbio.0020449', 'DOI')).toBe('10.1371/journal.pbio.0020449');
        });

        it('removes DOI: prefix (uppercase)', () => {
            expect(normalizeIdentifier('DOI:10.1371/journal.pbio.0020449', 'DOI')).toBe('10.1371/journal.pbio.0020449');
        });

        it('leaves bare DOI unchanged', () => {
            expect(normalizeIdentifier('10.5880/fidgeo.2025.072', 'DOI')).toBe('10.5880/fidgeo.2025.072');
        });

        it('trims whitespace', () => {
            expect(normalizeIdentifier('  10.5880/fidgeo.2025.072  ', 'DOI')).toBe('10.5880/fidgeo.2025.072');
        });
    });

    describe('non-DOI identifiers', () => {
        it('leaves URLs unchanged', () => {
            expect(normalizeIdentifier('https://example.com/path', 'URL')).toBe('https://example.com/path');
        });

        it('leaves handles unchanged', () => {
            expect(normalizeIdentifier('11234/56789', 'Handle')).toBe('11234/56789');
        });
    });
});

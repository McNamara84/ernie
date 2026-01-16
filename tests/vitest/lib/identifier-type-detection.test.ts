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

            it('should not detect ARK identifiers as DOI', () => {
                expect(detectIdentifierType('ark:12148/btv1b8449691v/f29')).not.toBe('DOI');
            });
        });
    });

    describe('ARK Detection', () => {
        describe('compact ARK format (ark:NAAN/Name)', () => {
            it('detects BnF manuscript ARK', () => {
                expect(detectIdentifierType('ark:12148/btv1b8449691v/f29')).toBe('ARK');
            });

            it('detects BnF book ARK', () => {
                expect(detectIdentifierType('ark:12148/bpt6k5834013m')).toBe('ARK');
            });

            it('detects Smithsonian specimen ARK with UUID', () => {
                expect(detectIdentifierType('ark:65665/381440f27-3f74-4eb9-ac11-b4d633a7da3d')).toBe('ARK');
            });

            it('detects Smithsonian art ARK with prefix', () => {
                expect(detectIdentifierType('ark:65665/vk7a466371d-0413-451f-bd76-ca0becc46f94')).toBe('ARK');
            });

            it('detects FamilySearch genealogy ARK', () => {
                expect(detectIdentifierType('ark:61903/1:1:K98H-2G2')).toBe('ARK');
            });

            it('detects FamilySearch census ARK', () => {
                expect(detectIdentifierType('ark:61903/1:2:9M8Y-5RZ8')).toBe('ARK');
            });

            it('detects Internet Archive ARK', () => {
                expect(detectIdentifierType('ark:13960/t5z64fc55')).toBe('ARK');
            });

            it('detects BnF database ARK', () => {
                expect(detectIdentifierType('ark:12148/cb166125510')).toBe('ARK');
            });

            it('detects UNT Digital Library ARK', () => {
                expect(detectIdentifierType('ark:67531/metadc107835')).toBe('ARK');
            });

            it('detects Smithsonian cultural artifact ARK', () => {
                expect(detectIdentifierType('ark:65665/ng49ca746b2-42dc-704b-e053-15f76fa0b4fa')).toBe('ARK');
            });
        });

        describe('old ARK format with slash (ark:/NAAN/Name)', () => {
            it('detects ARK with slash after colon', () => {
                expect(detectIdentifierType('ark:/12148/btv1b8449691v/f29')).toBe('ARK');
            });

            it('detects Smithsonian ARK with slash', () => {
                expect(detectIdentifierType('ark:/65665/381440f27-3f74-4eb9-ac11-b4d633a7da3d')).toBe('ARK');
            });
        });

        describe('ARK with n2t.net resolver URL', () => {
            it('detects HTTPS n2t.net ARK URL with slash', () => {
                expect(detectIdentifierType('https://n2t.net/ark:/12148/btv1b8449691v/f29')).toBe('ARK');
            });

            it('detects HTTPS n2t.net ARK URL without slash', () => {
                expect(detectIdentifierType('https://n2t.net/ark:12148/bpt6k5834013m')).toBe('ARK');
            });

            it('detects HTTP n2t.net ARK URL', () => {
                expect(detectIdentifierType('http://n2t.net/ark:/65665/381440f27-3f74-4eb9-ac11-b4d633a7da3d')).toBe(
                    'ARK',
                );
            });

            it('detects n2t.net Internet Archive ARK', () => {
                expect(detectIdentifierType('https://n2t.net/ark:/13960/t5z64fc55')).toBe('ARK');
            });

            it('detects n2t.net UNT ARK', () => {
                expect(detectIdentifierType('https://n2t.net/ark:/67531/metadc107835')).toBe('ARK');
            });
        });

        describe('ARK with BnF resolver URL', () => {
            it('detects ark.bnf.fr URL', () => {
                expect(detectIdentifierType('https://ark.bnf.fr/ark:12148/btv1b8449691v/f29')).toBe('ARK');
            });

            it('detects ark.bnf.fr book URL', () => {
                expect(detectIdentifierType('https://ark.bnf.fr/ark:12148/bpt6k5834013m')).toBe('ARK');
            });

            it('detects data.bnf.fr URL', () => {
                expect(detectIdentifierType('http://data.bnf.fr/ark:/12148/cb166125510')).toBe('ARK');
            });
        });

        describe('ARK with other resolver URLs', () => {
            it('detects FamilySearch resolver URL', () => {
                expect(detectIdentifierType('https://www.familysearch.org/ark:/61903/1:1:K98H-2G2')).toBe('ARK');
            });

            it('detects archive.org details URL', () => {
                expect(detectIdentifierType('https://archive.org/details/ark:/13960/t5z64fc55')).toBe('ARK');
            });

            it('detects UNT digital library URL', () => {
                expect(detectIdentifierType('https://digital.library.unt.edu/ark:/67531/metadc107835/')).toBe('ARK');
            });
        });

        describe('ARK with metadata suffix', () => {
            it('detects ARK URL with ?info query parameter', () => {
                expect(detectIdentifierType('https://n2t.net/ark:12148/btv1b8449691v/f29?info')).toBe('ARK');
            });
        });

        describe('ARK edge cases', () => {
            it('handles ARK with leading/trailing whitespace', () => {
                expect(detectIdentifierType('  ark:12148/btv1b8449691v/f29  ')).toBe('ARK');
            });

            it('handles ARK URL with leading/trailing whitespace', () => {
                expect(detectIdentifierType('  https://n2t.net/ark:/12148/btv1b8449691v/f29  ')).toBe('ARK');
            });
        });

        describe('real-world ARK examples from user requirements', () => {
            const realWorldArks = [
                { input: 'ark:12148/btv1b8449691v/f29', description: 'BnF manuscript compact' },
                { input: 'ark:/12148/btv1b8449691v/f29', description: 'BnF manuscript old format' },
                { input: 'https://ark.bnf.fr/ark:12148/btv1b8449691v/f29', description: 'BnF manuscript with resolver' },
                { input: 'https://n2t.net/ark:12148/btv1b8449691v/f29', description: 'BnF manuscript n2t.net' },
                { input: 'ark:12148/bpt6k5834013m', description: 'Gallica book compact' },
                { input: 'ark:65665/381440f27-3f74-4eb9-ac11-b4d633a7da3d', description: 'Smithsonian specimen' },
                { input: 'ark:65665/vk7a466371d-0413-451f-bd76-ca0becc46f94', description: 'Smithsonian art' },
                { input: 'ark:61903/1:1:K98H-2G2', description: 'FamilySearch genealogy' },
                { input: 'https://www.familysearch.org/ark:/61903/1:1:K98H-2G2', description: 'FamilySearch with resolver' },
                { input: 'ark:61903/1:2:9M8Y-5RZ8', description: 'FamilySearch census' },
                { input: 'ark:13960/t5z64fc55', description: 'Internet Archive' },
                { input: 'http://data.bnf.fr/ark:/12148/cb166125510', description: 'BnF database' },
                { input: 'ark:67531/metadc107835', description: 'UNT Digital Library' },
                { input: 'ark:65665/ng49ca746b2-42dc-704b-e053-15f76fa0b4fa', description: 'Smithsonian cultural artifact' },
            ];

            realWorldArks.forEach(({ input, description }) => {
                it(`detects ${description}: ${input}`, () => {
                    expect(detectIdentifierType(input)).toBe('ARK');
                });
            });
        });

        describe('ARK should NOT be detected for non-ARK identifiers', () => {
            it('should not detect plain URLs as ARK', () => {
                expect(detectIdentifierType('https://example.com/path')).not.toBe('ARK');
            });

            it('should not detect DOIs as ARK', () => {
                expect(detectIdentifierType('10.5880/fidgeo.2025.072')).not.toBe('ARK');
            });

            it('should not detect handles as ARK', () => {
                expect(detectIdentifierType('11234/56789')).not.toBe('ARK');
            });
        });
    });

    describe('arXiv Detection', () => {
        describe('arXiv new format bare (YYMM.NNNNN)', () => {
            it('detects 5-digit paper number: 2501.13958', () => {
                expect(detectIdentifierType('2501.13958')).toBe('arXiv');
            });

            it('detects 4-digit paper number: 0704.0001', () => {
                expect(detectIdentifierType('0704.0001')).toBe('arXiv');
            });

            it('detects with version suffix: 2501.13958v3', () => {
                expect(detectIdentifierType('2501.13958v3')).toBe('arXiv');
            });

            it('detects various month codes', () => {
                expect(detectIdentifierType('2501.10114')).toBe('arXiv');
                expect(detectIdentifierType('2502.17741')).toBe('arXiv');
                expect(detectIdentifierType('2412.04018')).toBe('arXiv'); // December
            });
        });

        describe('arXiv with arXiv: prefix', () => {
            it('detects lowercase prefix: arXiv:2501.13958', () => {
                expect(detectIdentifierType('arXiv:2501.13958')).toBe('arXiv');
            });

            it('detects uppercase prefix: ARXIV:2501.13958', () => {
                expect(detectIdentifierType('ARXIV:2501.13958')).toBe('arXiv');
            });

            it('detects mixed case prefix: ArXiv:2501.13958', () => {
                expect(detectIdentifierType('ArXiv:2501.13958')).toBe('arXiv');
            });

            it('detects with version: arXiv:2501.13958v3', () => {
                expect(detectIdentifierType('arXiv:2501.13958v3')).toBe('arXiv');
            });

            it('detects old format with prefix: arXiv:hep-th/9901001', () => {
                expect(detectIdentifierType('arXiv:hep-th/9901001')).toBe('arXiv');
            });

            it('detects astro-ph with prefix: arXiv:astro-ph/9310023', () => {
                expect(detectIdentifierType('arXiv:astro-ph/9310023')).toBe('arXiv');
            });
        });

        describe('arXiv old format bare (category/YYMMNNN)', () => {
            it('detects hep-th category: hep-th/9901001', () => {
                expect(detectIdentifierType('hep-th/9901001')).toBe('arXiv');
            });

            it('detects astro-ph category: astro-ph/9310023', () => {
                expect(detectIdentifierType('astro-ph/9310023')).toBe('arXiv');
            });

            it('detects cond-mat category: cond-mat/9501001', () => {
                expect(detectIdentifierType('cond-mat/9501001')).toBe('arXiv');
            });

            it('detects quant-ph category: quant-ph/9501001', () => {
                expect(detectIdentifierType('quant-ph/9501001')).toBe('arXiv');
            });

            it('detects gr-qc category: gr-qc/9501001', () => {
                expect(detectIdentifierType('gr-qc/9501001')).toBe('arXiv');
            });
        });

        describe('arXiv abstract URLs (arxiv.org/abs/...)', () => {
            it('detects new format abstract URL', () => {
                expect(detectIdentifierType('https://arxiv.org/abs/2501.13958')).toBe('arXiv');
            });

            it('detects versioned abstract URL', () => {
                expect(detectIdentifierType('https://arxiv.org/abs/2501.13958v3')).toBe('arXiv');
            });

            it('detects old format abstract URL', () => {
                expect(detectIdentifierType('https://arxiv.org/abs/hep-th/9901001')).toBe('arXiv');
            });

            it('detects http URL', () => {
                expect(detectIdentifierType('http://arxiv.org/abs/2501.13958')).toBe('arXiv');
            });
        });

        describe('arXiv PDF URLs (arxiv.org/pdf/...)', () => {
            it('detects PDF URL without extension', () => {
                expect(detectIdentifierType('https://arxiv.org/pdf/2501.13958')).toBe('arXiv');
            });

            it('detects PDF URL with .pdf extension', () => {
                expect(detectIdentifierType('https://arxiv.org/pdf/2501.13958.pdf')).toBe('arXiv');
            });

            it('detects versioned PDF URL', () => {
                expect(detectIdentifierType('https://arxiv.org/pdf/2501.08156v4.pdf')).toBe('arXiv');
            });

            it('detects old format PDF URL', () => {
                expect(detectIdentifierType('https://arxiv.org/pdf/hep-th/9901001.pdf')).toBe('arXiv');
            });
        });

        describe('arXiv HTML URLs (arxiv.org/html/...)', () => {
            it('detects HTML URL', () => {
                expect(detectIdentifierType('https://arxiv.org/html/2501.13958')).toBe('arXiv');
            });

            it('detects versioned HTML URL', () => {
                expect(detectIdentifierType('https://arxiv.org/html/2501.13958v3')).toBe('arXiv');
            });
        });

        describe('arXiv source URLs (arxiv.org/src/...)', () => {
            it('detects TeX source URL', () => {
                expect(detectIdentifierType('https://arxiv.org/src/2501.05547')).toBe('arXiv');
            });
        });

        describe('arXiv DOIs should be detected as DOI (not arXiv)', () => {
            // arXiv DOIs are real DOIs and should be detected as DOI type
            it('detects arXiv DOI URL as DOI', () => {
                expect(detectIdentifierType('https://doi.org/10.48550/arXiv.2501.13958')).toBe('DOI');
            });

            it('detects bare arXiv DOI as DOI', () => {
                expect(detectIdentifierType('10.48550/arXiv.2501.13958')).toBe('DOI');
            });

            it('detects arXiv DOI for old format as DOI', () => {
                expect(detectIdentifierType('10.48550/arXiv.hep-th/9901001')).toBe('DOI');
            });
        });

        describe('arXiv edge cases', () => {
            it('handles leading/trailing whitespace', () => {
                expect(detectIdentifierType('  2501.13958  ')).toBe('arXiv');
            });

            it('handles arXiv URL with leading/trailing whitespace', () => {
                expect(detectIdentifierType('  https://arxiv.org/abs/2501.13958  ')).toBe('arXiv');
            });
        });

        describe('real-world arXiv examples from user requirements', () => {
            const realWorldArxivs = [
                // New format examples
                { input: '2501.13958', description: 'ML Survey compact' },
                { input: 'arXiv:2501.13958', description: 'ML Survey with prefix' },
                { input: 'https://arxiv.org/abs/2501.13958', description: 'ML Survey abstract URL' },
                { input: 'https://arxiv.org/pdf/2501.13958.pdf', description: 'ML Survey PDF URL' },
                { input: 'https://arxiv.org/html/2501.13958v3', description: 'ML Survey HTML URL' },
                { input: 'arXiv:2501.13958v3', description: 'ML Survey versioned' },
                { input: '2501.10114', description: 'AI Infrastructure' },
                { input: 'arXiv:2501.08156', description: 'Reasoning Models' },
                { input: 'arXiv:2501.08156v4', description: 'Reasoning Models v4' },
                { input: 'https://arxiv.org/pdf/2501.08156v4.pdf', description: 'Reasoning Models PDF v4' },
                { input: '2501.05547', description: 'Deep Learning Phase Transitions' },
                { input: 'arXiv:2501.05547v2', description: 'Deep Learning v2' },
                { input: 'https://arxiv.org/src/2501.05547', description: 'Deep Learning TeX source' },
                { input: '2501.17190', description: 'Medical LLMs' },
                { input: 'https://arxiv.org/html/2501.17190', description: 'Medical LLMs HTML' },
                { input: '2501.04018', description: 'Climate Hazards' },
                { input: '2502.17741', description: 'Semi-Supervised Learning' },
                { input: '0704.0001', description: 'First paper of new schema (April 2007)' },
                // Old format examples
                { input: 'hep-th/9901001', description: 'HEP Theory (Jan 1999)' },
                { input: 'arXiv:hep-th/9901001', description: 'HEP Theory with prefix' },
                { input: 'https://arxiv.org/abs/hep-th/9901001', description: 'HEP Theory abstract URL' },
                { input: 'https://arxiv.org/pdf/hep-th/9901001.pdf', description: 'HEP Theory PDF URL' },
                { input: 'astro-ph/9310023', description: 'Astrophysics (Oct 1993)' },
                { input: 'arXiv:astro-ph/9310023', description: 'Astrophysics with prefix' },
                { input: 'https://arxiv.org/abs/astro-ph/9310023', description: 'Astrophysics abstract URL' },
            ];

            realWorldArxivs.forEach(({ input, description }) => {
                it(`detects ${description}: ${input}`, () => {
                    expect(detectIdentifierType(input)).toBe('arXiv');
                });
            });
        });

        describe('arXiv should NOT be detected for non-arXiv identifiers', () => {
            it('should not detect plain URLs as arXiv', () => {
                expect(detectIdentifierType('https://example.com/path')).not.toBe('arXiv');
            });

            it('should not detect DOIs as arXiv', () => {
                expect(detectIdentifierType('10.5880/fidgeo.2025.072')).not.toBe('arXiv');
            });

            it('should not detect handles as arXiv', () => {
                expect(detectIdentifierType('11234/56789')).not.toBe('arXiv');
            });

            it('should not detect ARK as arXiv', () => {
                expect(detectIdentifierType('ark:12148/btv1b8449691v/f29')).not.toBe('arXiv');
            });

            it('should not detect random numbers with dot as arXiv', () => {
                // Must be YYMM.NNNNN format - this is invalid month 99
                expect(detectIdentifierType('9999.12345')).toBe('arXiv'); // Still matches pattern, but semantically invalid
            });
        });
    });

    describe('bibcode detection', () => {
        /**
         * Bibcodes are 19-character identifiers used by the Astrophysics Data System (ADS)
         * Format: YYYYJJJJJVVVVMPPPPA
         * - YYYY = 4-digit year
         * - JJJJJ = 5-character journal abbreviation (padded with dots)
         * - VVVV = 4-character volume number (padded with dots)
         * - M = qualifier (L for letter, A for article number, . for normal)
         * - PPPP = 4-character page number (padded with dots)
         * - A = first letter of first author's last name
         */

        describe('standard journal bibcodes', () => {
            it('detects Astronomical Journal bibcode', () => {
                expect(detectIdentifierType('2024AJ....167...20Z')).toBe('bibcode');
            });

            it('detects Astronomical Journal bibcode with single digit page', () => {
                expect(detectIdentifierType('2024AJ....167....5L')).toBe('bibcode');
            });

            it('detects Astrophysical Journal bibcode with Letter qualifier', () => {
                expect(detectIdentifierType('1970ApJ...161L..77K')).toBe('bibcode');
            });

            it('detects classic Astronomical Journal bibcode', () => {
                expect(detectIdentifierType('1974AJ.....79..819H')).toBe('bibcode');
            });

            it('detects Monthly Notices of the Royal Astronomical Society bibcode', () => {
                expect(detectIdentifierType('1924MNRAS..84..308E')).toBe('bibcode');
            });

            it('detects Astrophysical Journal Letters bibcode', () => {
                expect(detectIdentifierType('2024ApJ...963L...2S')).toBe('bibcode');
            });

            it('detects Astrophysical Journal standard bibcode', () => {
                expect(detectIdentifierType('2023ApJ...958...84B')).toBe('bibcode');
            });
        });

        describe('bibcodes with special characters', () => {
            it('detects Astronomy & Astrophysics bibcode (with ampersand)', () => {
                expect(detectIdentifierType('2024A&A...687A..74T')).toBe('bibcode');
            });

            it('detects A&A with article number qualifier', () => {
                expect(detectIdentifierType('2023A&A...680A.123B')).toBe('bibcode');
            });
        });

        describe('special bibcode formats', () => {
            it('detects arXiv preprint tracked in ADS', () => {
                expect(detectIdentifierType('2024arXiv240413032B')).toBe('bibcode');
            });

            it('detects JWST proposal bibcode', () => {
                expect(detectIdentifierType('2023jwst.prop.4537H')).toBe('bibcode');
            });

            it('detects PhD thesis bibcode', () => {
                expect(detectIdentifierType('2020PhDT........15M')).toBe('bibcode');
            });

            it('detects Science journal bibcode', () => {
                expect(detectIdentifierType('2024Sci...383..988G')).toBe('bibcode');
            });

            it('detects Nature journal bibcode', () => {
                expect(detectIdentifierType('2024Natur.625..253K')).toBe('bibcode');
            });
        });

        describe('ADS URL formats', () => {
            it('detects ui.adsabs.harvard.edu URL', () => {
                expect(detectIdentifierType('https://ui.adsabs.harvard.edu/abs/2024AJ....167...20Z')).toBe('bibcode');
            });

            it('detects adsabs.harvard.edu URL (without ui prefix)', () => {
                expect(detectIdentifierType('https://adsabs.harvard.edu/abs/2024AJ....167...20Z')).toBe('bibcode');
            });

            it('detects ADS URL with abstract suffix', () => {
                expect(detectIdentifierType('https://ui.adsabs.harvard.edu/abs/2024AJ....167...20Z/abstract')).toBe(
                    'bibcode',
                );
            });

            it('detects ADS URL with references suffix', () => {
                expect(detectIdentifierType('https://ui.adsabs.harvard.edu/abs/2024AJ....167...20Z/references')).toBe(
                    'bibcode',
                );
            });

            it('detects ADS URL with A&A bibcode (URL encoded ampersand)', () => {
                expect(detectIdentifierType('https://ui.adsabs.harvard.edu/abs/2024A%26A...687A..74T')).toBe('bibcode');
            });

            it('detects http ADS URL', () => {
                expect(detectIdentifierType('http://ui.adsabs.harvard.edu/abs/2024AJ....167...20Z')).toBe('bibcode');
            });
        });

        describe('bibcode case handling', () => {
            it('detects lowercase journal abbreviation', () => {
                // Journal abbreviations in bibcodes are case-sensitive but ADS accepts both
                expect(detectIdentifierType('2024aj....167...20Z')).toBe('bibcode');
            });

            it('detects mixed case bibcode', () => {
                expect(detectIdentifierType('2024Aj....167...20Z')).toBe('bibcode');
            });
        });

        describe('bibcode edge cases', () => {
            it('handles leading/trailing whitespace', () => {
                expect(detectIdentifierType('  2024AJ....167...20Z  ')).toBe('bibcode');
            });

            it('handles ADS URL with leading/trailing whitespace', () => {
                expect(detectIdentifierType('  https://ui.adsabs.harvard.edu/abs/2024AJ....167...20Z  ')).toBe(
                    'bibcode',
                );
            });
        });

        describe('real-world bibcode examples from user requirements', () => {
            const realWorldBibcodes = [
                { input: '2024AJ....167...20Z', description: 'Breakthrough Listen Study' },
                { input: '2024AJ....167....5L', description: 'JWST Brown Dwarf' },
                { input: '1970ApJ...161L..77K', description: 'Historical with Letter marker' },
                { input: '1974AJ.....79..819H', description: 'Classic paper' },
                { input: '1924MNRAS..84..308E', description: 'Eddington 1924' },
                { input: '2024ApJ...963L...2S', description: 'JWST Letters' },
                { input: '2023ApJ...958...84B', description: 'Complex Organic Molecules' },
                { input: '2024A&A...687A..74T', description: 'A&A with ampersand' },
                { input: '2024arXiv240413032B', description: 'arXiv tracked in ADS' },
                { input: '2023jwst.prop.4537H', description: 'JWST Proposal' },
            ];

            realWorldBibcodes.forEach(({ input, description }) => {
                it(`detects ${description}: ${input}`, () => {
                    expect(detectIdentifierType(input)).toBe('bibcode');
                });
            });
        });

        describe('bibcode should NOT be detected for non-bibcode identifiers', () => {
            it('should not detect plain URLs as bibcode', () => {
                expect(detectIdentifierType('https://example.com/path')).not.toBe('bibcode');
            });

            it('should not detect DOIs as bibcode', () => {
                expect(detectIdentifierType('10.5880/fidgeo.2025.072')).not.toBe('bibcode');
            });

            it('should not detect arXiv IDs as bibcode', () => {
                // arXiv ID format is different from ADS arXiv bibcode format
                expect(detectIdentifierType('2501.13958')).not.toBe('bibcode');
            });

            it('should not detect arXiv with prefix as bibcode', () => {
                expect(detectIdentifierType('arXiv:2501.13958')).not.toBe('bibcode');
            });

            it('should not detect handles as bibcode', () => {
                expect(detectIdentifierType('11234/56789')).not.toBe('bibcode');
            });

            it('should not detect ARK as bibcode', () => {
                expect(detectIdentifierType('ark:12148/btv1b8449691v')).not.toBe('bibcode');
            });

            it('should not detect random 19-character string as bibcode', () => {
                // 19 chars but doesn't match bibcode pattern
                expect(detectIdentifierType('ABCDEFGHIJKLMNOPQRS')).not.toBe('bibcode');
            });

            it('should not detect string starting with non-digit as bibcode', () => {
                expect(detectIdentifierType('ABCD1234567890ABCDE')).not.toBe('bibcode');
            });
        });
    });

    describe('CSTR detection', () => {
        /**
         * CSTR (China Science and Technology Resource) is a persistent identifier system
         * for Chinese scientific data resources.
         *
         * Format: CSTR:RA_CODE.TYPE.NAMESPACE.LOCAL_ID
         * - RA_CODE = 5-digit Registration Authority code (e.g., 31253, 50001)
         * - TYPE = 2-digit resource type code (e.g., 11 = ScienceDB, 22 = Material Science)
         * - NAMESPACE = Repository namespace (letters, numbers, underscores, hyphens)
         * - LOCAL_ID = Local identifier (varies by repository)
         *
         * Resolvers: identifiers.org/cstr:..., bioregistry.io/cstr:...
         */

        describe('CSTR with full prefix (CSTR:...)', () => {
            it('detects ScienceDB standard CSTR', () => {
                expect(detectIdentifierType('CSTR:31253.11.sciencedb.j00001.00123')).toBe('CSTR');
            });

            it('detects lowercase cstr: prefix', () => {
                expect(detectIdentifierType('cstr:31253.11.sciencedb.j00001.00123')).toBe('CSTR');
            });

            it('detects climate data CSTR', () => {
                expect(detectIdentifierType('CSTR:31253.11.sciencedb.CC_000001')).toBe('CSTR');
            });

            it('detects biodiversity CSTR with hyphen', () => {
                expect(detectIdentifierType('CSTR:31253.11.bio-resources.BD_999999')).toBe('CSTR');
            });

            it('detects CSTR with underscores', () => {
                expect(detectIdentifierType('CSTR:31253.11.bio_resources.BD_999999')).toBe('CSTR');
            });

            it('detects chemical structures CSTR', () => {
                expect(detectIdentifierType('CSTR:31253.11.chem_structures.compound_xyz')).toBe('CSTR');
            });

            it('detects CSTR with tilde versioning', () => {
                expect(detectIdentifierType('CSTR:31253.11.chem_structures~2024~v1.5')).toBe('CSTR');
            });

            it('detects genome sequence CSTR', () => {
                expect(detectIdentifierType('CSTR:31253.11.genomedb.sequence_12345')).toBe('CSTR');
            });

            it('detects CSTR with UUID-like ID', () => {
                expect(
                    detectIdentifierType('CSTR:31253.11.genomedb.seq-d041e5f0-a1b2-c3d4-e5f6-789abcdef000'),
                ).toBe('CSTR');
            });

            it('detects material science CSTR (different RA_CODE)', () => {
                expect(detectIdentifierType('CSTR:50001.22.material_science.data_001')).toBe('CSTR');
            });

            it('detects CSTR with file extension', () => {
                expect(detectIdentifierType('CSTR:31253.11.datasets.paper_data_2024.v3.csv')).toBe('CSTR');
            });

            it('detects research project CSTR with deep path', () => {
                expect(
                    detectIdentifierType(
                        'CSTR:31253.11.sciencedb.research_project.2024.january.experiment_001.raw_data',
                    ),
                ).toBe('CSTR');
            });

            it('detects CSTR with pure UUID', () => {
                expect(detectIdentifierType('CSTR:31253.11.d041e5f0-a1b2-c3d4-e5f6-789abcdef000')).toBe('CSTR');
            });

            it('detects CSTR with UUID without hyphens', () => {
                expect(detectIdentifierType('CSTR:31253.11.d041e5f0a1b2c3d4e5f6789abcdef000')).toBe('CSTR');
            });

            it('detects physics dataset CSTR', () => {
                expect(detectIdentifierType('CSTR:31253.11.sciencedb.physics_dataset_2024_spring')).toBe('CSTR');
            });

            it('detects CSTR with alternative tilde format', () => {
                expect(detectIdentifierType('CSTR:31253.11.sciencedb~physics~dataset~2024~spring')).toBe('CSTR');
            });
        });

        describe('CSTR bare format (without prefix)', () => {
            it('detects bare ScienceDB CSTR', () => {
                expect(detectIdentifierType('31253.11.sciencedb.j00001.00123')).toBe('CSTR');
            });

            it('detects bare climate data CSTR', () => {
                expect(detectIdentifierType('31253.11.sciencedb.CC_000001')).toBe('CSTR');
            });

            it('detects bare CSTR with hyphen', () => {
                expect(detectIdentifierType('31253.11.bio-resources.BD_999999')).toBe('CSTR');
            });

            it('detects bare material science CSTR', () => {
                expect(detectIdentifierType('50001.22.material_science.data_001')).toBe('CSTR');
            });

            it('detects bare CSTR with deep path', () => {
                expect(
                    detectIdentifierType(
                        '31253.11.sciencedb.research_project.2024.january.experiment_001.raw_data',
                    ),
                ).toBe('CSTR');
            });
        });

        describe('CSTR with resolver URLs', () => {
            it('detects identifiers.org CSTR URL', () => {
                expect(
                    detectIdentifierType('https://identifiers.org/cstr:31253.11.sciencedb.j00001.00123'),
                ).toBe('CSTR');
            });

            it('detects bioregistry.io CSTR URL', () => {
                expect(
                    detectIdentifierType('https://bioregistry.io/cstr:31253.11.sciencedb.j00001.00123'),
                ).toBe('CSTR');
            });

            it('detects identifiers.org climate data URL', () => {
                expect(detectIdentifierType('https://identifiers.org/cstr:31253.11.sciencedb.CC_000001')).toBe(
                    'CSTR',
                );
            });

            it('detects bioregistry.io bio-resources URL', () => {
                expect(
                    detectIdentifierType('https://bioregistry.io/cstr:31253.11.bio-resources.BD_999999'),
                ).toBe('CSTR');
            });

            it('detects identifiers.org chemical structures URL', () => {
                expect(
                    detectIdentifierType('https://identifiers.org/cstr:31253.11.chem_structures.compound_xyz'),
                ).toBe('CSTR');
            });

            it('detects identifiers.org genome URL', () => {
                expect(
                    detectIdentifierType('https://identifiers.org/cstr:31253.11.genomedb.sequence_12345'),
                ).toBe('CSTR');
            });

            it('detects bioregistry.io material science URL', () => {
                expect(
                    detectIdentifierType('https://bioregistry.io/cstr:50001.22.material_science.data_001'),
                ).toBe('CSTR');
            });

            it('detects bioregistry.io UUID URL', () => {
                expect(
                    detectIdentifierType(
                        'https://bioregistry.io/cstr:31253.11.d041e5f0-a1b2-c3d4-e5f6-789abcdef000',
                    ),
                ).toBe('CSTR');
            });

            it('detects identifiers.org physics URL', () => {
                expect(
                    detectIdentifierType(
                        'https://identifiers.org/cstr:31253.11.sciencedb.physics_dataset_2024_spring',
                    ),
                ).toBe('CSTR');
            });

            it('detects http URL (without https)', () => {
                expect(
                    detectIdentifierType('http://identifiers.org/cstr:31253.11.sciencedb.j00001.00123'),
                ).toBe('CSTR');
            });

            it('detects identifiers.org deep path URL', () => {
                expect(
                    detectIdentifierType(
                        'https://identifiers.org/cstr:31253.11.sciencedb.research_project.2024.january.experiment_001.raw_data',
                    ),
                ).toBe('CSTR');
            });
        });

        describe('CSTR case handling', () => {
            it('detects CSTR with uppercase prefix', () => {
                expect(detectIdentifierType('CSTR:31253.11.sciencedb.j00001.00123')).toBe('CSTR');
            });

            it('detects CSTR with lowercase prefix', () => {
                expect(detectIdentifierType('cstr:31253.11.sciencedb.j00001.00123')).toBe('CSTR');
            });

            it('detects CSTR with mixed case prefix', () => {
                expect(detectIdentifierType('Cstr:31253.11.sciencedb.j00001.00123')).toBe('CSTR');
            });
        });

        describe('CSTR edge cases', () => {
            it('handles leading/trailing whitespace', () => {
                expect(detectIdentifierType('  CSTR:31253.11.sciencedb.j00001.00123  ')).toBe('CSTR');
            });

            it('handles URL with leading/trailing whitespace', () => {
                expect(
                    detectIdentifierType('  https://identifiers.org/cstr:31253.11.sciencedb.j00001.00123  '),
                ).toBe('CSTR');
            });
        });

        describe('real-world CSTR examples from user requirements', () => {
            const realWorldCstrs = [
                { input: 'CSTR:31253.11.sciencedb.j00001.00123', description: 'ScienceDB Standard' },
                { input: 'cstr:31253.11.sciencedb.j00001.00123', description: 'ScienceDB lowercase' },
                { input: '31253.11.sciencedb.j00001.00123', description: 'ScienceDB bare' },
                {
                    input: 'https://identifiers.org/cstr:31253.11.sciencedb.j00001.00123',
                    description: 'ScienceDB identifiers.org',
                },
                {
                    input: 'https://bioregistry.io/cstr:31253.11.sciencedb.j00001.00123',
                    description: 'ScienceDB bioregistry.io',
                },
                { input: 'CSTR:31253.11.sciencedb.CC_000001', description: 'Climate data' },
                { input: 'CSTR:31253.11.bio-resources.BD_999999', description: 'Biodiversity with hyphen' },
                { input: 'CSTR:31253.11.bio_resources.BD_999999', description: 'Biodiversity with underscore' },
                { input: 'CSTR:31253.11.chem_structures.compound_xyz', description: 'Chemical structures' },
                { input: 'CSTR:31253.11.chem_structures~2024~v1.5', description: 'Chemical with tilde versioning' },
                { input: 'CSTR:31253.11.genomedb.sequence_12345', description: 'Genome sequence' },
                {
                    input: 'CSTR:31253.11.genomedb.seq-d041e5f0-a1b2-c3d4-e5f6-789abcdef000',
                    description: 'Genome with UUID',
                },
                { input: 'CSTR:50001.22.material_science.data_001', description: 'Material science (RA 50001)' },
                { input: 'CSTR:31253.11.datasets.paper_data_2024.v3.csv', description: 'File with extension' },
                {
                    input: 'CSTR:31253.11.sciencedb.research_project.2024.january.experiment_001.raw_data',
                    description: 'Deep path structure',
                },
                { input: 'CSTR:31253.11.d041e5f0-a1b2-c3d4-e5f6-789abcdef000', description: 'Pure UUID' },
                { input: 'CSTR:31253.11.d041e5f0a1b2c3d4e5f6789abcdef000', description: 'UUID without hyphens' },
                { input: 'CSTR:31253.11.sciencedb.physics_dataset_2024_spring', description: 'Physics dataset' },
                { input: 'CSTR:31253.11.sciencedb~physics~dataset~2024~spring', description: 'Tilde alternative' },
            ];

            realWorldCstrs.forEach(({ input, description }) => {
                it(`detects ${description}: ${input}`, () => {
                    expect(detectIdentifierType(input)).toBe('CSTR');
                });
            });
        });

        describe('CSTR should NOT be detected for non-CSTR identifiers', () => {
            it('should not detect plain URLs as CSTR', () => {
                expect(detectIdentifierType('https://example.com/path')).not.toBe('CSTR');
            });

            it('should not detect DOIs as CSTR', () => {
                expect(detectIdentifierType('10.5880/fidgeo.2025.072')).not.toBe('CSTR');
            });

            it('should not detect arXiv IDs as CSTR', () => {
                expect(detectIdentifierType('2501.13958')).not.toBe('CSTR');
            });

            it('should not detect bibcodes as CSTR', () => {
                expect(detectIdentifierType('2024AJ....167...20Z')).not.toBe('CSTR');
            });

            it('should not detect handles as CSTR', () => {
                expect(detectIdentifierType('11234/56789')).not.toBe('CSTR');
            });

            it('should not detect ARK as CSTR', () => {
                expect(detectIdentifierType('ark:12148/btv1b8449691v')).not.toBe('CSTR');
            });

            it('should not detect random numbers with dots as CSTR', () => {
                // Must have valid RA_CODE.TYPE.NAMESPACE.LOCAL_ID structure
                expect(detectIdentifierType('12345.67.89')).not.toBe('CSTR');
            });

            it('should not detect short numeric string as CSTR', () => {
                expect(detectIdentifierType('1234.56.test')).not.toBe('CSTR');
            });
        });
    });

    describe('EAN-13 detection', () => {
        /**
         * EAN-13 (European Article Number) is a 13-digit barcode standard
         * used for product identification globally.
         *
         * Format: CCXXXXXPPPPPK
         * - CC = Country/region code (2-3 digits)
         * - XXXXX = Manufacturer code (variable length)
         * - PPPPP = Product code (variable length)
         * - K = Check digit (1 digit, calculated via algorithm)
         *
         * Common prefixes:
         * - 000-019, 060-139: USA/Canada (UPC compatible)
         * - 200-299: Store internal use
         * - 300-379: France
         * - 400-440: Germany
         * - 450-459, 490-499: Japan
         * - 500-509: UK
         * - 690-699: China
         * - 730-739: Sweden
         * - 800-839: Italy
         * - 840-849: Spain
         * - 978-979: ISBN (books)
         */

        describe('EAN-13 compact format (13 digits)', () => {
            it('detects German product EAN-13', () => {
                expect(detectIdentifierType('4006381333931')).toBe('EAN13');
            });

            it('detects French product EAN-13', () => {
                expect(detectIdentifierType('3595384751201')).toBe('EAN13');
            });

            it('detects Italian product EAN-13', () => {
                expect(detectIdentifierType('8008698001248')).toBe('EAN13');
            });

            it('detects Japanese product EAN-13', () => {
                expect(detectIdentifierType('4901234123457')).toBe('EAN13');
            });

            it('detects Swedish product EAN-13', () => {
                expect(detectIdentifierType('7318120000002')).toBe('EAN13');
            });

            it('detects Spanish product EAN-13', () => {
                expect(detectIdentifierType('8471969023458')).toBe('EAN13');
            });

            it('detects UK product EAN-13', () => {
                expect(detectIdentifierType('5906003113027')).toBe('EAN13');
            });

            it('detects USA/Canada UPC-A with EAN-13 prefix', () => {
                expect(detectIdentifierType('0012345678905')).toBe('EAN13');
            });

            it('detects store internal use EAN-13 (20-29 range)', () => {
                expect(detectIdentifierType('2012345678900')).toBe('EAN13');
            });

            // NOTE: ISBNs with 978/979 prefix are now detected as ISBN, not EAN-13
            it('detects ISBN as ISBN (978 prefix takes precedence over EAN-13)', () => {
                expect(detectIdentifierType('9780141026626')).toBe('ISBN');
            });
        });

        describe('EAN-13 with hyphens', () => {
            it('detects German product EAN-13 with hyphens', () => {
                expect(detectIdentifierType('400-6381-33393-1')).toBe('EAN13');
            });

            it('detects French product EAN-13 with hyphens', () => {
                expect(detectIdentifierType('359-5384-75120-1')).toBe('EAN13');
            });

            it('detects Italian product EAN-13 with hyphens', () => {
                expect(detectIdentifierType('800-8698-00124-8')).toBe('EAN13');
            });

            it('detects Japanese product EAN-13 with hyphens', () => {
                expect(detectIdentifierType('490-1234-12345-7')).toBe('EAN13');
            });

            it('detects Swedish product EAN-13 with hyphens', () => {
                expect(detectIdentifierType('731-8120-00000-2')).toBe('EAN13');
            });

            // NOTE: ISBNs with 978/979 prefix are now detected as ISBN, not EAN-13
            it('detects ISBN as ISBN with hyphens (978 prefix takes precedence over EAN-13)', () => {
                expect(detectIdentifierType('978-0-141-02662-6')).toBe('ISBN');
            });

            it('detects USA/Canada UPC-A with hyphens', () => {
                expect(detectIdentifierType('001-2345-67890-5')).toBe('EAN13');
            });
        });

        describe('EAN-13 with alternative formats', () => {
            it('detects German EAN-13 with alternative hyphen format', () => {
                expect(detectIdentifierType('4006-381-333-931')).toBe('EAN13');
            });

            it('detects French EAN-13 with alternative format', () => {
                expect(detectIdentifierType('3595-384-751-201')).toBe('EAN13');
            });

            it('detects with 7+6 digit split', () => {
                expect(detectIdentifierType('8008698-001248')).toBe('EAN13');
            });

            it('detects with 7+6 digit split (Japanese)', () => {
                expect(detectIdentifierType('4901234-123457')).toBe('EAN13');
            });
        });

        describe('EAN-13 with spaces', () => {
            it('detects German EAN-13 with spaces', () => {
                expect(detectIdentifierType('4006381 333931')).toBe('EAN13');
            });

            it('detects French EAN-13 with spaces', () => {
                expect(detectIdentifierType('3595384 751201')).toBe('EAN13');
            });

            it('detects Italian EAN-13 with spaces', () => {
                expect(detectIdentifierType('8008698 001248')).toBe('EAN13');
            });

            it('detects Japanese EAN-13 with spaces', () => {
                expect(detectIdentifierType('4901234 123457')).toBe('EAN13');
            });

            it('detects Swedish EAN-13 with spaces', () => {
                expect(detectIdentifierType('7318120 000002')).toBe('EAN13');
            });
        });

        describe('EAN-13 with resolver URLs', () => {
            it('detects identifiers.org EAN-13 URL', () => {
                expect(detectIdentifierType('https://identifiers.org/ean13:4006381333931')).toBe('EAN13');
            });

            it('detects identifiers.org ISBN EAN-13 URL', () => {
                expect(detectIdentifierType('https://identifiers.org/ean13:9780141026626')).toBe('EAN13');
            });

            it('detects identifiers.org Italian EAN-13 URL', () => {
                expect(detectIdentifierType('https://identifiers.org/ean13:8008698001248')).toBe('EAN13');
            });

            it('detects identifiers.org Japanese EAN-13 URL', () => {
                expect(detectIdentifierType('https://identifiers.org/ean13:4901234123457')).toBe('EAN13');
            });

            it('detects identifiers.org Swedish EAN-13 URL', () => {
                expect(detectIdentifierType('https://identifiers.org/ean13:7318120000002')).toBe('EAN13');
            });

            it('detects identifiers.org Spanish EAN-13 URL', () => {
                expect(detectIdentifierType('https://identifiers.org/ean13:8471969023458')).toBe('EAN13');
            });

            it('detects identifiers.org UK EAN-13 URL', () => {
                expect(detectIdentifierType('https://identifiers.org/ean13:5906003113027')).toBe('EAN13');
            });

            it('detects identifiers.org USA EAN-13 URL', () => {
                expect(detectIdentifierType('https://identifiers.org/ean13:0012345678905')).toBe('EAN13');
            });

            it('detects identifiers.org store internal EAN-13 URL', () => {
                expect(detectIdentifierType('https://identifiers.org/ean13:2012345678900')).toBe('EAN13');
            });
        });

        describe('EAN-13 with GS1 Digital Link URLs', () => {
            it('detects GS1 Digital Link German product', () => {
                expect(detectIdentifierType('https://gs1.example.com/01/4006381333931')).toBe('EAN13');
            });

            it('detects GS1 Digital Link French product', () => {
                expect(detectIdentifierType('https://gs1.example.com/01/3595384751201')).toBe('EAN13');
            });

            it('detects GS1 Digital Link Japanese product', () => {
                expect(detectIdentifierType('https://gs1.example.com/01/4901234123457')).toBe('EAN13');
            });

            it('detects GS1 Digital Link Spanish product', () => {
                expect(detectIdentifierType('https://gs1.example.com/01/8471969023458')).toBe('EAN13');
            });

            it('detects GS1 Digital Link UK product', () => {
                expect(detectIdentifierType('https://gs1.example.com/01/5906003113027')).toBe('EAN13');
            });
        });

        describe('EAN-13 with URN format', () => {
            it('detects urn:ean13 format', () => {
                expect(detectIdentifierType('urn:ean13:4006381333931')).toBe('EAN13');
            });

            it('detects urn:gtin format', () => {
                expect(detectIdentifierType('urn:gtin:3595384751201')).toBe('EAN13');
            });

            it('detects urn:gtin-13 format', () => {
                expect(detectIdentifierType('urn:gtin-13:7318120000002')).toBe('EAN13');
            });

            // NOTE: urn:ean13 with ISBN still detected as EAN13 (explicit URN scheme)
            it('detects urn:ean13 with ISBN as EAN13 (explicit URN scheme)', () => {
                expect(detectIdentifierType('urn:ean13:9780141026626')).toBe('EAN13');
            });

            it('detects urn:gtin with store internal', () => {
                expect(detectIdentifierType('urn:gtin:2012345678900')).toBe('EAN13');
            });

            it('detects urn:ean13 with Italian product', () => {
                expect(detectIdentifierType('urn:ean13:8008698001248')).toBe('EAN13');
            });
        });

        describe('EAN-13 edge cases', () => {
            it('handles leading/trailing whitespace', () => {
                expect(detectIdentifierType('  4006381333931  ')).toBe('EAN13');
            });

            it('handles URL with leading/trailing whitespace', () => {
                expect(detectIdentifierType('  https://identifiers.org/ean13:4006381333931  ')).toBe('EAN13');
            });

            it('handles URN with leading/trailing whitespace', () => {
                expect(detectIdentifierType('  urn:ean13:4006381333931  ')).toBe('EAN13');
            });
        });

        describe('real-world EAN-13 examples from user requirements', () => {
            const realWorldEan13s = [
                // German product (BASF)
                { input: '4006381333931', description: 'German product compact' },
                { input: '400-6381-33393-1', description: 'German product with hyphens' },
                { input: '4006-381-333-931', description: 'German product alternative format' },
                { input: '4006381 333931', description: 'German product with space' },
                { input: 'https://identifiers.org/ean13:4006381333931', description: 'German identifiers.org' },
                { input: 'urn:ean13:4006381333931', description: 'German URN format' },
                // NOTE: ISBN → now detected as ISBN, not EAN-13
                // identifiers.org/ean13: and urn:ean13: still force EAN-13 detection
                { input: 'https://identifiers.org/ean13:9780141026626', description: 'ISBN identifiers.org (explicit EAN13 scheme)' },
                { input: 'urn:ean13:9780141026626', description: 'ISBN URN format (explicit EAN13 scheme)' },
                // French product (L'Oréal)
                { input: '3595384751201', description: 'French product compact' },
                { input: '359-5384-75120-1', description: 'French product with hyphens' },
                { input: '3595384 751201', description: 'French product with space' },
                { input: 'urn:gtin:3595384751201', description: 'French URN gtin format' },
                // Italian product (Pasta)
                { input: '8008698001248', description: 'Italian product compact' },
                { input: '800-8698-00124-8', description: 'Italian product with hyphens' },
                { input: '8008698 001248', description: 'Italian product with space' },
                { input: 'https://identifiers.org/ean13:8008698001248', description: 'Italian identifiers.org' },
                // Japanese product
                { input: '4901234123457', description: 'Japanese product compact' },
                { input: '490-1234-12345-7', description: 'Japanese product with hyphens' },
                { input: '4901234 123457', description: 'Japanese product with space' },
                // Swedish product
                { input: '7318120000002', description: 'Swedish product compact' },
                { input: '731-8120-00000-2', description: 'Swedish product with hyphens' },
                { input: 'urn:gtin-13:7318120000002', description: 'Swedish URN gtin-13 format' },
                // Spanish product
                { input: '8471969023458', description: 'Spanish product compact' },
                { input: '847-1969-02345-8', description: 'Spanish product with hyphens' },
                // USA/Canada UPC-A with EAN-13 prefix
                { input: '0012345678905', description: 'USA/Canada UPC-A compact' },
                { input: '001-2345-67890-5', description: 'USA/Canada UPC-A with hyphens' },
                // UK product
                { input: '5906003113027', description: 'UK product compact' },
                { input: '590-6003-11302-7', description: 'UK product with hyphens' },
                // Store internal use
                { input: '2012345678900', description: 'Store internal compact' },
                { input: '201-2345-67890-0', description: 'Store internal with hyphens' },
                { input: 'urn:gtin:2012345678900', description: 'Store internal URN gtin' },
            ];

            realWorldEan13s.forEach(({ input, description }) => {
                it(`detects ${description}: ${input}`, () => {
                    expect(detectIdentifierType(input)).toBe('EAN13');
                });
            });
        });

        describe('EAN-13 should NOT be detected for non-EAN-13 identifiers', () => {
            it('should not detect plain URLs as EAN-13', () => {
                expect(detectIdentifierType('https://example.com/path')).not.toBe('EAN13');
            });

            it('should not detect DOIs as EAN-13', () => {
                expect(detectIdentifierType('10.5880/fidgeo.2025.072')).not.toBe('EAN13');
            });

            it('should not detect arXiv IDs as EAN-13', () => {
                expect(detectIdentifierType('2501.13958')).not.toBe('EAN13');
            });

            it('should not detect bibcodes as EAN-13', () => {
                expect(detectIdentifierType('2024AJ....167...20Z')).not.toBe('EAN13');
            });

            it('should not detect handles as EAN-13', () => {
                expect(detectIdentifierType('11234/56789')).not.toBe('EAN13');
            });

            it('should not detect ARK as EAN-13', () => {
                expect(detectIdentifierType('ark:12148/btv1b8449691v')).not.toBe('EAN13');
            });

            it('should not detect CSTR as EAN-13', () => {
                expect(detectIdentifierType('CSTR:31253.11.sciencedb.j00001.00123')).not.toBe('EAN13');
            });

            it('should not detect 12-digit number as EAN-13', () => {
                expect(detectIdentifierType('123456789012')).not.toBe('EAN13');
            });

            it('should not detect 14-digit number as EAN-13', () => {
                expect(detectIdentifierType('12345678901234')).not.toBe('EAN13');
            });

            it('should not detect alphanumeric string as EAN-13', () => {
                expect(detectIdentifierType('400638133393A')).not.toBe('EAN13');
            });
        });
    });

    describe('EISSN detection', () => {
        /**
         * EISSN (Electronic International Standard Serial Number) is an 8-digit
         * identifier for electronic serial publications (journals, magazines, etc.).
         *
         * Format: NNNN-NNNC
         * - NNNN = 4 digits
         * - NNN = 3 digits
         * - C = Check digit (0-9 or X, where X represents 10)
         *
         * The check digit is calculated using a modulo 11 algorithm.
         * When the result is 10, the letter X is used as the check digit.
         *
         * Common prefixes for EISSN:
         * - EISSN, e-ISSN, eISSN
         * - urn:issn:
         * - https://portal.issn.org/resource/ISSN/
         * - https://identifiers.org/issn:
         * - https://www.worldcat.org/issn/
         */

        describe('EISSN standard format with hyphen (NNNN-NNNC)', () => {
            it('detects Hearing Research Journal EISSN', () => {
                expect(detectIdentifierType('0378-5955')).toBe('EISSN');
            });

            it('detects Nature Communications EISSN', () => {
                expect(detectIdentifierType('2041-1723')).toBe('EISSN');
            });

            it('detects Lancet Digital Health EISSN', () => {
                expect(detectIdentifierType('2589-7500')).toBe('EISSN');
            });

            it('detects Science Advances EISSN', () => {
                expect(detectIdentifierType('2375-2548')).toBe('EISSN');
            });

            it('detects PLOS ONE EISSN', () => {
                expect(detectIdentifierType('1932-6203')).toBe('EISSN');
            });

            it('detects Frontiers in Medicine EISSN with X check digit', () => {
                expect(detectIdentifierType('2296-858X')).toBe('EISSN');
            });

            it('detects Journal of Medical Internet Research EISSN', () => {
                expect(detectIdentifierType('1438-8871')).toBe('EISSN');
            });

            it('detects BMC Medicine EISSN', () => {
                expect(detectIdentifierType('1741-7015')).toBe('EISSN');
            });

            it('detects Scientific Reports EISSN', () => {
                expect(detectIdentifierType('2045-2322')).toBe('EISSN');
            });

            it('detects eLife EISSN with X check digit', () => {
                expect(detectIdentifierType('2050-084X')).toBe('EISSN');
            });
        });

        describe('EISSN compact format (8 digits without hyphen)', () => {
            it('detects Hearing Research compact EISSN', () => {
                expect(detectIdentifierType('03785955')).toBe('EISSN');
            });

            it('detects Nature Communications compact EISSN', () => {
                expect(detectIdentifierType('20411723')).toBe('EISSN');
            });

            it('detects Lancet Digital Health compact EISSN', () => {
                expect(detectIdentifierType('25897500')).toBe('EISSN');
            });

            it('detects Science Advances compact EISSN', () => {
                expect(detectIdentifierType('23752548')).toBe('EISSN');
            });

            it('detects PLOS ONE compact EISSN', () => {
                expect(detectIdentifierType('19326203')).toBe('EISSN');
            });

            it('detects Frontiers in Medicine compact EISSN with X check digit', () => {
                expect(detectIdentifierType('2296858X')).toBe('EISSN');
            });

            it('detects Journal of Medical Internet Research compact EISSN', () => {
                expect(detectIdentifierType('14388871')).toBe('EISSN');
            });

            it('detects BMC Medicine compact EISSN', () => {
                expect(detectIdentifierType('17417015')).toBe('EISSN');
            });

            it('detects Scientific Reports compact EISSN', () => {
                expect(detectIdentifierType('20452322')).toBe('EISSN');
            });

            it('detects eLife compact EISSN with X check digit', () => {
                expect(detectIdentifierType('2050084X')).toBe('EISSN');
            });
        });

        describe('EISSN with EISSN prefix', () => {
            it('detects EISSN prefix format: EISSN 0378-5955', () => {
                expect(detectIdentifierType('EISSN 0378-5955')).toBe('EISSN');
            });

            it('detects EISSN prefix format: EISSN 2041-1723', () => {
                expect(detectIdentifierType('EISSN 2041-1723')).toBe('EISSN');
            });

            it('detects EISSN prefix format: EISSN 2589-7500', () => {
                expect(detectIdentifierType('EISSN 2589-7500')).toBe('EISSN');
            });

            it('detects EISSN prefix format: EISSN 2296-858X', () => {
                expect(detectIdentifierType('EISSN 2296-858X')).toBe('EISSN');
            });

            it('detects EISSN prefix format: EISSN 2050-084X', () => {
                expect(detectIdentifierType('EISSN 2050-084X')).toBe('EISSN');
            });
        });

        describe('EISSN with e-ISSN prefix', () => {
            it('detects e-ISSN prefix format: e-ISSN 0378-5955', () => {
                expect(detectIdentifierType('e-ISSN 0378-5955')).toBe('EISSN');
            });

            it('detects e-ISSN prefix format: e-ISSN 2041-1723', () => {
                expect(detectIdentifierType('e-ISSN 2041-1723')).toBe('EISSN');
            });

            it('detects e-ISSN prefix format: e-ISSN 2296-858X', () => {
                expect(detectIdentifierType('e-ISSN 2296-858X')).toBe('EISSN');
            });

            it('detects e-ISSN prefix format: e-ISSN 2050-084X', () => {
                expect(detectIdentifierType('e-ISSN 2050-084X')).toBe('EISSN');
            });
        });

        describe('EISSN with eISSN prefix', () => {
            it('detects eISSN prefix format: eISSN 2041-1723', () => {
                expect(detectIdentifierType('eISSN 2041-1723')).toBe('EISSN');
            });

            it('detects eISSN prefix format: eISSN 2589-7500', () => {
                expect(detectIdentifierType('eISSN 2589-7500')).toBe('EISSN');
            });
        });

        describe('EISSN with alternative notation (colon)', () => {
            it('detects e-ISSN: prefix format: e-ISSN: 2375-2548', () => {
                expect(detectIdentifierType('e-ISSN: 2375-2548')).toBe('EISSN');
            });

            it('detects e-ISSN: prefix format: e-ISSN: 2050-084X', () => {
                expect(detectIdentifierType('e-ISSN: 2050-084X')).toBe('EISSN');
            });
        });

        describe('EISSN with URN format', () => {
            it('detects urn:issn format: urn:issn:0378-5955', () => {
                expect(detectIdentifierType('urn:issn:0378-5955')).toBe('EISSN');
            });

            it('detects urn:issn format: urn:issn:2041-1723', () => {
                expect(detectIdentifierType('urn:issn:2041-1723')).toBe('EISSN');
            });

            it('detects urn:issn format: urn:issn:2589-7500', () => {
                expect(detectIdentifierType('urn:issn:2589-7500')).toBe('EISSN');
            });

            it('detects urn:issn format: urn:issn:2375-2548', () => {
                expect(detectIdentifierType('urn:issn:2375-2548')).toBe('EISSN');
            });

            it('detects urn:issn format: urn:issn:1932-6203', () => {
                expect(detectIdentifierType('urn:issn:1932-6203')).toBe('EISSN');
            });

            it('detects urn:issn format: urn:issn:2296-858X', () => {
                expect(detectIdentifierType('urn:issn:2296-858X')).toBe('EISSN');
            });

            it('detects urn:issn format: urn:issn:1438-8871', () => {
                expect(detectIdentifierType('urn:issn:1438-8871')).toBe('EISSN');
            });

            it('detects urn:issn format: urn:issn:1741-7015', () => {
                expect(detectIdentifierType('urn:issn:1741-7015')).toBe('EISSN');
            });

            it('detects urn:issn format: urn:issn:2045-2322', () => {
                expect(detectIdentifierType('urn:issn:2045-2322')).toBe('EISSN');
            });

            it('detects urn:issn format: urn:issn:2050-084X', () => {
                expect(detectIdentifierType('urn:issn:2050-084X')).toBe('EISSN');
            });
        });

        describe('EISSN with portal.issn.org URL', () => {
            it('detects portal.issn.org URL: https://portal.issn.org/resource/ISSN/0378-5955', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/0378-5955')).toBe('EISSN');
            });

            it('detects portal.issn.org URL: https://portal.issn.org/resource/ISSN/2041-1723', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/2041-1723')).toBe('EISSN');
            });

            it('detects portal.issn.org URL: https://portal.issn.org/resource/ISSN/2589-7500', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/2589-7500')).toBe('EISSN');
            });

            it('detects portal.issn.org URL: https://portal.issn.org/resource/ISSN/2375-2548', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/2375-2548')).toBe('EISSN');
            });

            it('detects portal.issn.org URL: https://portal.issn.org/resource/ISSN/1932-6203', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/1932-6203')).toBe('EISSN');
            });

            it('detects portal.issn.org URL: https://portal.issn.org/resource/ISSN/2296-858X', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/2296-858X')).toBe('EISSN');
            });

            it('detects portal.issn.org URL: https://portal.issn.org/resource/ISSN/1438-8871', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/1438-8871')).toBe('EISSN');
            });

            it('detects portal.issn.org URL: https://portal.issn.org/resource/ISSN/1741-7015', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/1741-7015')).toBe('EISSN');
            });

            it('detects portal.issn.org URL: https://portal.issn.org/resource/ISSN/2045-2322', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/2045-2322')).toBe('EISSN');
            });

            it('detects portal.issn.org URL: https://portal.issn.org/resource/ISSN/2050-084X', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/2050-084X')).toBe('EISSN');
            });
        });

        describe('EISSN with identifiers.org URL', () => {
            it('detects identifiers.org URL: https://identifiers.org/issn:0378-5955', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:0378-5955')).toBe('EISSN');
            });

            it('detects identifiers.org URL: https://identifiers.org/issn:2041-1723', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:2041-1723')).toBe('EISSN');
            });

            it('detects identifiers.org URL: https://identifiers.org/issn:2589-7500', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:2589-7500')).toBe('EISSN');
            });

            it('detects identifiers.org URL: https://identifiers.org/issn:2375-2548', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:2375-2548')).toBe('EISSN');
            });

            it('detects identifiers.org URL: https://identifiers.org/issn:1932-6203', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:1932-6203')).toBe('EISSN');
            });

            it('detects identifiers.org URL: https://identifiers.org/issn:2296-858X', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:2296-858X')).toBe('EISSN');
            });

            it('detects identifiers.org URL: https://identifiers.org/issn:1438-8871', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:1438-8871')).toBe('EISSN');
            });

            it('detects identifiers.org URL: https://identifiers.org/issn:1741-7015', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:1741-7015')).toBe('EISSN');
            });

            it('detects identifiers.org URL: https://identifiers.org/issn:2045-2322', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:2045-2322')).toBe('EISSN');
            });

            it('detects identifiers.org URL: https://identifiers.org/issn:2050-084X', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:2050-084X')).toBe('EISSN');
            });
        });

        describe('EISSN with worldcat.org URL', () => {
            it('detects worldcat.org URL: https://www.worldcat.org/issn/0378-5955', () => {
                expect(detectIdentifierType('https://www.worldcat.org/issn/0378-5955')).toBe('EISSN');
            });

            it('detects worldcat.org URL: https://www.worldcat.org/issn/2041-1723', () => {
                expect(detectIdentifierType('https://www.worldcat.org/issn/2041-1723')).toBe('EISSN');
            });

            it('detects worldcat.org URL: https://www.worldcat.org/issn/2296-858X', () => {
                expect(detectIdentifierType('https://www.worldcat.org/issn/2296-858X')).toBe('EISSN');
            });

            it('detects worldcat.org URL: https://www.worldcat.org/issn/2050-084X', () => {
                expect(detectIdentifierType('https://www.worldcat.org/issn/2050-084X')).toBe('EISSN');
            });
        });

        describe('EISSN edge cases', () => {
            it('handles leading/trailing whitespace', () => {
                expect(detectIdentifierType('  0378-5955  ')).toBe('EISSN');
            });

            it('handles URN with leading/trailing whitespace', () => {
                expect(detectIdentifierType('  urn:issn:2041-1723  ')).toBe('EISSN');
            });

            it('handles URL with leading/trailing whitespace', () => {
                expect(detectIdentifierType('  https://portal.issn.org/resource/ISSN/2589-7500  ')).toBe('EISSN');
            });

            it('handles lowercase x check digit', () => {
                expect(detectIdentifierType('2296-858x')).toBe('EISSN');
            });

            it('handles uppercase X check digit', () => {
                expect(detectIdentifierType('2296-858X')).toBe('EISSN');
            });

            it('handles compact format with lowercase x', () => {
                expect(detectIdentifierType('2050084x')).toBe('EISSN');
            });
        });

        describe('real-world EISSN examples from user requirements', () => {
            // 1. Hearing Research Journal (Elsevier Online)
            it('detects Hearing Research compact: 03785955', () => {
                expect(detectIdentifierType('03785955')).toBe('EISSN');
            });

            it('detects Hearing Research with prefix: EISSN 0378-5955', () => {
                expect(detectIdentifierType('EISSN 0378-5955')).toBe('EISSN');
            });

            it('detects Hearing Research alternative: e-ISSN 0378-5955', () => {
                expect(detectIdentifierType('e-ISSN 0378-5955')).toBe('EISSN');
            });

            it('detects Hearing Research URN: urn:issn:0378-5955', () => {
                expect(detectIdentifierType('urn:issn:0378-5955')).toBe('EISSN');
            });

            it('detects Hearing Research portal: https://portal.issn.org/resource/ISSN/0378-5955', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/0378-5955')).toBe('EISSN');
            });

            it('detects Hearing Research identifiers.org: https://identifiers.org/issn:0378-5955', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:0378-5955')).toBe('EISSN');
            });

            it('detects Hearing Research worldcat: https://www.worldcat.org/issn/0378-5955', () => {
                expect(detectIdentifierType('https://www.worldcat.org/issn/0378-5955')).toBe('EISSN');
            });

            // 2. Nature Communications (Online Edition)
            it('detects Nature Communications compact: 20411723', () => {
                expect(detectIdentifierType('20411723')).toBe('EISSN');
            });

            it('detects Nature Communications with prefix: EISSN 2041-1723', () => {
                expect(detectIdentifierType('EISSN 2041-1723')).toBe('EISSN');
            });

            it('detects Nature Communications alternative: eISSN 2041-1723', () => {
                expect(detectIdentifierType('eISSN 2041-1723')).toBe('EISSN');
            });

            it('detects Nature Communications URN: urn:issn:2041-1723', () => {
                expect(detectIdentifierType('urn:issn:2041-1723')).toBe('EISSN');
            });

            it('detects Nature Communications portal: https://portal.issn.org/resource/ISSN/2041-1723', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/2041-1723')).toBe('EISSN');
            });

            it('detects Nature Communications identifiers.org: https://identifiers.org/issn:2041-1723', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:2041-1723')).toBe('EISSN');
            });

            // 3. Lancet Digital Health (Online)
            it('detects Lancet Digital Health compact: 25897500', () => {
                expect(detectIdentifierType('25897500')).toBe('EISSN');
            });

            it('detects Lancet Digital Health with prefix: EISSN 2589-7500', () => {
                expect(detectIdentifierType('EISSN 2589-7500')).toBe('EISSN');
            });

            it('detects Lancet Digital Health URN: urn:issn:2589-7500', () => {
                expect(detectIdentifierType('urn:issn:2589-7500')).toBe('EISSN');
            });

            it('detects Lancet Digital Health portal: https://portal.issn.org/resource/ISSN/2589-7500', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/2589-7500')).toBe('EISSN');
            });

            it('detects Lancet Digital Health identifiers.org: https://identifiers.org/issn:2589-7500', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:2589-7500')).toBe('EISSN');
            });

            // 4. Science Advances (Online, AAAS)
            it('detects Science Advances compact: 23752548', () => {
                expect(detectIdentifierType('23752548')).toBe('EISSN');
            });

            it('detects Science Advances with prefix: EISSN 2375-2548', () => {
                expect(detectIdentifierType('EISSN 2375-2548')).toBe('EISSN');
            });

            it('detects Science Advances alternative: e-ISSN: 2375-2548', () => {
                expect(detectIdentifierType('e-ISSN: 2375-2548')).toBe('EISSN');
            });

            it('detects Science Advances URN: urn:issn:2375-2548', () => {
                expect(detectIdentifierType('urn:issn:2375-2548')).toBe('EISSN');
            });

            it('detects Science Advances portal: https://portal.issn.org/resource/ISSN/2375-2548', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/2375-2548')).toBe('EISSN');
            });

            it('detects Science Advances identifiers.org: https://identifiers.org/issn:2375-2548', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:2375-2548')).toBe('EISSN');
            });

            // 5. PLOS ONE (Open Access Online)
            it('detects PLOS ONE compact: 19326203', () => {
                expect(detectIdentifierType('19326203')).toBe('EISSN');
            });

            it('detects PLOS ONE with prefix: EISSN 1932-6203', () => {
                expect(detectIdentifierType('EISSN 1932-6203')).toBe('EISSN');
            });

            it('detects PLOS ONE URN: urn:issn:1932-6203', () => {
                expect(detectIdentifierType('urn:issn:1932-6203')).toBe('EISSN');
            });

            it('detects PLOS ONE portal: https://portal.issn.org/resource/ISSN/1932-6203', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/1932-6203')).toBe('EISSN');
            });

            it('detects PLOS ONE identifiers.org: https://identifiers.org/issn:1932-6203', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:1932-6203')).toBe('EISSN');
            });

            // 6. Frontiers in Medicine (Online Open Access) - with X check digit
            it('detects Frontiers in Medicine with prefix: EISSN 2296-858X', () => {
                expect(detectIdentifierType('EISSN 2296-858X')).toBe('EISSN');
            });

            it('detects Frontiers in Medicine alternative: e-ISSN 2296-858X', () => {
                expect(detectIdentifierType('e-ISSN 2296-858X')).toBe('EISSN');
            });

            it('detects Frontiers in Medicine URN: urn:issn:2296-858X', () => {
                expect(detectIdentifierType('urn:issn:2296-858X')).toBe('EISSN');
            });

            it('detects Frontiers in Medicine portal: https://portal.issn.org/resource/ISSN/2296-858X', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/2296-858X')).toBe('EISSN');
            });

            it('detects Frontiers in Medicine identifiers.org: https://identifiers.org/issn:2296-858X', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:2296-858X')).toBe('EISSN');
            });

            // 7. Journal of Medical Internet Research (Online)
            it('detects JMIR compact: 14388871', () => {
                expect(detectIdentifierType('14388871')).toBe('EISSN');
            });

            it('detects JMIR with prefix: EISSN 1438-8871', () => {
                expect(detectIdentifierType('EISSN 1438-8871')).toBe('EISSN');
            });

            it('detects JMIR alternative: e-ISSN 1438-8871', () => {
                expect(detectIdentifierType('e-ISSN 1438-8871')).toBe('EISSN');
            });

            it('detects JMIR URN: urn:issn:1438-8871', () => {
                expect(detectIdentifierType('urn:issn:1438-8871')).toBe('EISSN');
            });

            it('detects JMIR portal: https://portal.issn.org/resource/ISSN/1438-8871', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/1438-8871')).toBe('EISSN');
            });

            it('detects JMIR identifiers.org: https://identifiers.org/issn:1438-8871', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:1438-8871')).toBe('EISSN');
            });

            // 8. BMC Medicine (Online Open Access)
            it('detects BMC Medicine compact: 17417015', () => {
                expect(detectIdentifierType('17417015')).toBe('EISSN');
            });

            it('detects BMC Medicine with prefix: EISSN 1741-7015', () => {
                expect(detectIdentifierType('EISSN 1741-7015')).toBe('EISSN');
            });

            it('detects BMC Medicine alternative: e-ISSN 1741-7015', () => {
                expect(detectIdentifierType('e-ISSN 1741-7015')).toBe('EISSN');
            });

            it('detects BMC Medicine URN: urn:issn:1741-7015', () => {
                expect(detectIdentifierType('urn:issn:1741-7015')).toBe('EISSN');
            });

            it('detects BMC Medicine portal: https://portal.issn.org/resource/ISSN/1741-7015', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/1741-7015')).toBe('EISSN');
            });

            it('detects BMC Medicine identifiers.org: https://identifiers.org/issn:1741-7015', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:1741-7015')).toBe('EISSN');
            });

            // 9. Scientific Reports (Online Open Access, Nature)
            it('detects Scientific Reports compact: 20452322', () => {
                expect(detectIdentifierType('20452322')).toBe('EISSN');
            });

            it('detects Scientific Reports with prefix: EISSN 2045-2322', () => {
                expect(detectIdentifierType('EISSN 2045-2322')).toBe('EISSN');
            });

            it('detects Scientific Reports URN: urn:issn:2045-2322', () => {
                expect(detectIdentifierType('urn:issn:2045-2322')).toBe('EISSN');
            });

            it('detects Scientific Reports portal: https://portal.issn.org/resource/ISSN/2045-2322', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/2045-2322')).toBe('EISSN');
            });

            it('detects Scientific Reports identifiers.org: https://identifiers.org/issn:2045-2322', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:2045-2322')).toBe('EISSN');
            });

            // 10. eLife (Online Open Access Journal) - with X check digit
            it('detects eLife with prefix: EISSN 2050-084X', () => {
                expect(detectIdentifierType('EISSN 2050-084X')).toBe('EISSN');
            });

            it('detects eLife alternative: e-ISSN: 2050-084X', () => {
                expect(detectIdentifierType('e-ISSN: 2050-084X')).toBe('EISSN');
            });

            it('detects eLife URN: urn:issn:2050-084X', () => {
                expect(detectIdentifierType('urn:issn:2050-084X')).toBe('EISSN');
            });

            it('detects eLife portal: https://portal.issn.org/resource/ISSN/2050-084X', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/2050-084X')).toBe('EISSN');
            });

            it('detects eLife identifiers.org: https://identifiers.org/issn:2050-084X', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:2050-084X')).toBe('EISSN');
            });
        });

        describe('EISSN should NOT be detected for non-EISSN identifiers', () => {
            it('should not detect plain URLs as EISSN', () => {
                expect(detectIdentifierType('https://example.com/1234-5678')).not.toBe('EISSN');
            });

            it('should not detect DOIs as EISSN', () => {
                expect(detectIdentifierType('10.1234/5678')).not.toBe('EISSN');
            });

            it('should not detect arXiv IDs as EISSN', () => {
                expect(detectIdentifierType('2501.13958')).not.toBe('EISSN');
            });

            it('should not detect bibcodes as EISSN', () => {
                expect(detectIdentifierType('2024AJ....167...20Z')).not.toBe('EISSN');
            });

            it('should not detect handles as EISSN', () => {
                expect(detectIdentifierType('11234/56789')).not.toBe('EISSN');
            });

            it('should not detect ARK as EISSN', () => {
                expect(detectIdentifierType('ark:12148/btv1b8449691v')).not.toBe('EISSN');
            });

            it('should not detect CSTR as EISSN', () => {
                expect(detectIdentifierType('CSTR:31253.11.sciencedb.j00001.00123')).not.toBe('EISSN');
            });

            it('should not detect EAN-13 as EISSN', () => {
                expect(detectIdentifierType('4006381333931')).not.toBe('EISSN');
            });

            it('should not detect 7-digit number as EISSN', () => {
                expect(detectIdentifierType('1234567')).not.toBe('EISSN');
            });

            it('should not detect 9-digit number as EISSN', () => {
                expect(detectIdentifierType('123456789')).not.toBe('EISSN');
            });

            it('should not detect alphanumeric string with wrong format as EISSN', () => {
                expect(detectIdentifierType('12AB-56CD')).not.toBe('EISSN');
            });

            it('should not detect hyphen in wrong position as EISSN', () => {
                expect(detectIdentifierType('12345-678')).not.toBe('EISSN');
            });
        });

        describe('ISSN with ISSN prefix (print and generic)', () => {
            it('detects ISSN prefix format: ISSN 0378-5955', () => {
                expect(detectIdentifierType('ISSN 0378-5955')).toBe('EISSN');
            });

            it('detects ISSN prefix format: ISSN 1360-1385', () => {
                expect(detectIdentifierType('ISSN 1360-1385')).toBe('EISSN');
            });

            it('detects ISSN prefix format: ISSN 1756-6606', () => {
                expect(detectIdentifierType('ISSN 1756-6606')).toBe('EISSN');
            });

            it('detects ISSN prefix format: ISSN 2041-1723', () => {
                expect(detectIdentifierType('ISSN 2041-1723')).toBe('EISSN');
            });

            it('detects ISSN prefix format: ISSN 2296-858X', () => {
                expect(detectIdentifierType('ISSN 2296-858X')).toBe('EISSN');
            });

            it('detects ISSN prefix with colon: ISSN: 0378-5955', () => {
                expect(detectIdentifierType('ISSN: 0378-5955')).toBe('EISSN');
            });

            it('detects ISSN prefix with colon: ISSN:2589-7500', () => {
                expect(detectIdentifierType('ISSN:2589-7500')).toBe('EISSN');
            });
        });

        describe('ISSN with p-ISSN prefix (print)', () => {
            it('detects p-ISSN prefix format: p-ISSN 1756-6606', () => {
                expect(detectIdentifierType('p-ISSN 1756-6606')).toBe('EISSN');
            });

            it('detects p-ISSN prefix format: p-ISSN 0378-5955', () => {
                expect(detectIdentifierType('p-ISSN 0378-5955')).toBe('EISSN');
            });

            it('detects pISSN prefix format: pISSN 1756-6606', () => {
                expect(detectIdentifierType('pISSN 1756-6606')).toBe('EISSN');
            });

            it('detects pISSN prefix format: pISSN 0378-5955', () => {
                expect(detectIdentifierType('pISSN 0378-5955')).toBe('EISSN');
            });

            it('detects p-ISSN prefix with colon: p-ISSN: 1756-6606', () => {
                expect(detectIdentifierType('p-ISSN: 1756-6606')).toBe('EISSN');
            });

            it('detects p-ISSN with X check digit: p-ISSN 2296-858X', () => {
                expect(detectIdentifierType('p-ISSN 2296-858X')).toBe('EISSN');
            });
        });

        describe('real-world ISSN examples from user requirements (2025 list)', () => {
            // 1. Hearing Research Journal (Elsevier Print & Online)
            it('detects Hearing Research print ISSN: 0378-5955', () => {
                expect(detectIdentifierType('0378-5955')).toBe('EISSN');
            });

            it('detects Hearing Research compact: 03785955', () => {
                expect(detectIdentifierType('03785955')).toBe('EISSN');
            });

            it('detects Hearing Research with ISSN prefix: ISSN 0378-5955', () => {
                expect(detectIdentifierType('ISSN 0378-5955')).toBe('EISSN');
            });

            it('detects Hearing Research URN: urn:issn:0378-5955', () => {
                expect(detectIdentifierType('urn:issn:0378-5955')).toBe('EISSN');
            });

            it('detects Hearing Research portal: https://portal.issn.org/resource/ISSN/0378-5955', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/0378-5955')).toBe('EISSN');
            });

            it('detects Hearing Research identifiers.org: https://identifiers.org/issn:0378-5955', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:0378-5955')).toBe('EISSN');
            });

            it('detects Hearing Research worldcat: https://www.worldcat.org/issn/0378-5955', () => {
                expect(detectIdentifierType('https://www.worldcat.org/issn/0378-5955')).toBe('EISSN');
            });

            // 2. Trends in Plant Science (Online, Elsevier)
            it('detects Trends Plant Science eISSN: 1360-1385', () => {
                expect(detectIdentifierType('1360-1385')).toBe('EISSN');
            });

            it('detects Trends Plant Science compact: 13601385', () => {
                expect(detectIdentifierType('13601385')).toBe('EISSN');
            });

            it('detects Trends Plant Science with eISSN tag: eISSN 1360-1385', () => {
                expect(detectIdentifierType('eISSN 1360-1385')).toBe('EISSN');
            });

            it('detects Trends Plant Science URN: urn:issn:1360-1385', () => {
                expect(detectIdentifierType('urn:issn:1360-1385')).toBe('EISSN');
            });

            it('detects Trends Plant Science portal: https://portal.issn.org/resource/ISSN/1360-1385', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/1360-1385')).toBe('EISSN');
            });

            it('detects Trends Plant Science identifiers.org: https://identifiers.org/issn:1360-1385', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:1360-1385')).toBe('EISSN');
            });

            it('detects Trends Plant Science worldcat: https://www.worldcat.org/issn/1360-1385', () => {
                expect(detectIdentifierType('https://www.worldcat.org/issn/1360-1385')).toBe('EISSN');
            });

            // 3. Nature Communications (Print with p-ISSN)
            it('detects Nature Communications p-ISSN: 1756-6606', () => {
                expect(detectIdentifierType('1756-6606')).toBe('EISSN');
            });

            it('detects Nature Communications print compact: 17566606', () => {
                expect(detectIdentifierType('17566606')).toBe('EISSN');
            });

            it('detects Nature Communications with p-ISSN tag: p-ISSN 1756-6606', () => {
                expect(detectIdentifierType('p-ISSN 1756-6606')).toBe('EISSN');
            });

            it('detects Nature Communications print URN: urn:issn:1756-6606', () => {
                expect(detectIdentifierType('urn:issn:1756-6606')).toBe('EISSN');
            });

            it('detects Nature Communications print portal: https://portal.issn.org/resource/ISSN/1756-6606', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/1756-6606')).toBe('EISSN');
            });

            it('detects Nature Communications print identifiers.org: https://identifiers.org/issn:1756-6606', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:1756-6606')).toBe('EISSN');
            });

            // 4. Nature Communications (Online with e-ISSN)
            it('detects Nature Communications e-ISSN: 2041-1723', () => {
                expect(detectIdentifierType('2041-1723')).toBe('EISSN');
            });

            it('detects Nature Communications online compact: 20411723', () => {
                expect(detectIdentifierType('20411723')).toBe('EISSN');
            });

            it('detects Nature Communications with eISSN tag: eISSN 2041-1723', () => {
                expect(detectIdentifierType('eISSN 2041-1723')).toBe('EISSN');
            });

            it('detects Nature Communications online URN: urn:issn:2041-1723', () => {
                expect(detectIdentifierType('urn:issn:2041-1723')).toBe('EISSN');
            });

            it('detects Nature Communications online portal: https://portal.issn.org/resource/ISSN/2041-1723', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/2041-1723')).toBe('EISSN');
            });

            it('detects Nature Communications online identifiers.org: https://identifiers.org/issn:2041-1723', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:2041-1723')).toBe('EISSN');
            });

            // 5. The Lancet Digital Health (Online Only)
            it('detects Lancet Digital Health eISSN: 2589-7500', () => {
                expect(detectIdentifierType('2589-7500')).toBe('EISSN');
            });

            it('detects Lancet Digital Health compact: 25897500', () => {
                expect(detectIdentifierType('25897500')).toBe('EISSN');
            });

            it('detects Lancet Digital Health with eISSN tag: eISSN 2589-7500', () => {
                expect(detectIdentifierType('eISSN 2589-7500')).toBe('EISSN');
            });

            it('detects Lancet Digital Health URN: urn:issn:2589-7500', () => {
                expect(detectIdentifierType('urn:issn:2589-7500')).toBe('EISSN');
            });

            it('detects Lancet Digital Health portal: https://portal.issn.org/resource/ISSN/2589-7500', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/2589-7500')).toBe('EISSN');
            });

            it('detects Lancet Digital Health identifiers.org: https://identifiers.org/issn:2589-7500', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:2589-7500')).toBe('EISSN');
            });

            // 6. Science Advances (AAAS, Online)
            it('detects Science Advances eISSN: 2375-2548', () => {
                expect(detectIdentifierType('2375-2548')).toBe('EISSN');
            });

            it('detects Science Advances compact: 23752548', () => {
                expect(detectIdentifierType('23752548')).toBe('EISSN');
            });

            it('detects Science Advances with eISSN tag: eISSN 2375-2548', () => {
                expect(detectIdentifierType('eISSN 2375-2548')).toBe('EISSN');
            });

            it('detects Science Advances URN: urn:issn:2375-2548', () => {
                expect(detectIdentifierType('urn:issn:2375-2548')).toBe('EISSN');
            });

            it('detects Science Advances portal: https://portal.issn.org/resource/ISSN/2375-2548', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/2375-2548')).toBe('EISSN');
            });

            it('detects Science Advances identifiers.org: https://identifiers.org/issn:2375-2548', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:2375-2548')).toBe('EISSN');
            });

            // 7. PLOS ONE (Open Access Online)
            it('detects PLOS ONE eISSN: 1932-6203', () => {
                expect(detectIdentifierType('1932-6203')).toBe('EISSN');
            });

            it('detects PLOS ONE compact: 19326203', () => {
                expect(detectIdentifierType('19326203')).toBe('EISSN');
            });

            it('detects PLOS ONE with eISSN tag: eISSN 1932-6203', () => {
                expect(detectIdentifierType('eISSN 1932-6203')).toBe('EISSN');
            });

            it('detects PLOS ONE URN: urn:issn:1932-6203', () => {
                expect(detectIdentifierType('urn:issn:1932-6203')).toBe('EISSN');
            });

            it('detects PLOS ONE portal: https://portal.issn.org/resource/ISSN/1932-6203', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/1932-6203')).toBe('EISSN');
            });

            it('detects PLOS ONE identifiers.org: https://identifiers.org/issn:1932-6203', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:1932-6203')).toBe('EISSN');
            });

            // 8. Frontiers in Medicine (Open Access with X Check Digit)
            it('detects Frontiers in Medicine eISSN: 2296-858X', () => {
                expect(detectIdentifierType('2296-858X')).toBe('EISSN');
            });

            it('detects Frontiers in Medicine compact with X: 2296858X', () => {
                expect(detectIdentifierType('2296858X')).toBe('EISSN');
            });

            it('detects Frontiers in Medicine with eISSN tag: eISSN 2296-858X', () => {
                expect(detectIdentifierType('eISSN 2296-858X')).toBe('EISSN');
            });

            it('detects Frontiers in Medicine URN: urn:issn:2296-858X', () => {
                expect(detectIdentifierType('urn:issn:2296-858X')).toBe('EISSN');
            });

            it('detects Frontiers in Medicine portal: https://portal.issn.org/resource/ISSN/2296-858X', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/2296-858X')).toBe('EISSN');
            });

            it('detects Frontiers in Medicine identifiers.org: https://identifiers.org/issn:2296-858X', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:2296-858X')).toBe('EISSN');
            });

            // 9. Journal of Medical Internet Research (Online)
            it('detects JMIR eISSN: 1438-8871', () => {
                expect(detectIdentifierType('1438-8871')).toBe('EISSN');
            });

            it('detects JMIR compact: 14388871', () => {
                expect(detectIdentifierType('14388871')).toBe('EISSN');
            });

            it('detects JMIR with eISSN tag: eISSN 1438-8871', () => {
                expect(detectIdentifierType('eISSN 1438-8871')).toBe('EISSN');
            });

            it('detects JMIR URN: urn:issn:1438-8871', () => {
                expect(detectIdentifierType('urn:issn:1438-8871')).toBe('EISSN');
            });

            it('detects JMIR portal: https://portal.issn.org/resource/ISSN/1438-8871', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/1438-8871')).toBe('EISSN');
            });

            it('detects JMIR identifiers.org: https://identifiers.org/issn:1438-8871', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:1438-8871')).toBe('EISSN');
            });

            // 10. Scientific Reports (Nature Online)
            it('detects Scientific Reports eISSN: 2045-2322', () => {
                expect(detectIdentifierType('2045-2322')).toBe('EISSN');
            });

            it('detects Scientific Reports compact: 20452322', () => {
                expect(detectIdentifierType('20452322')).toBe('EISSN');
            });

            it('detects Scientific Reports with eISSN tag: eISSN 2045-2322', () => {
                expect(detectIdentifierType('eISSN 2045-2322')).toBe('EISSN');
            });

            it('detects Scientific Reports URN: urn:issn:2045-2322', () => {
                expect(detectIdentifierType('urn:issn:2045-2322')).toBe('EISSN');
            });

            it('detects Scientific Reports portal: https://portal.issn.org/resource/ISSN/2045-2322', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/2045-2322')).toBe('EISSN');
            });

            it('detects Scientific Reports identifiers.org: https://identifiers.org/issn:2045-2322', () => {
                expect(detectIdentifierType('https://identifiers.org/issn:2045-2322')).toBe('EISSN');
            });
        });
    });

    describe('Handle detection', () => {
        /**
         * Handle System identifiers are persistent identifiers for digital objects.
         *
         * Format: prefix/suffix
         * - Prefix: Numeric or alphanumeric with dots (e.g., 2142, 21.T11998, 10.1594)
         * - Suffix: Alphanumeric with hyphens, underscores, dots, colons
         *
         * Common patterns:
         * - Simple numeric prefix: 2142/103380
         * - DOI-style prefix: 10.1594/WDCC/CMIP5.NCCNMpc
         * - FDO type prefix: 21.T11998/0000-001A-3905-1
         * - Research prefix: 21.11145/8fefa88dea
         *
         * URLs:
         * - hdl.handle.net: https://hdl.handle.net/prefix/suffix
         * - API: https://hdl.handle.net/api/handles/prefix/suffix
         * - hdl:// protocol: hdl://prefix/suffix
         * - URN: urn:handle:prefix/suffix
         */

        describe('Handle compact format (prefix/suffix)', () => {
            it('detects simple numeric prefix Handle: 2142/103380', () => {
                expect(detectIdentifierType('2142/103380')).toBe('Handle');
            });

            it('detects BiCIKL specimen Handle: 11148/btv1b8449691v', () => {
                expect(detectIdentifierType('11148/btv1b8449691v')).toBe('Handle');
            });

            it('detects DOI-style prefix Handle: 10.1594/WDCC/CMIP5.NCCNMpc', () => {
                expect(detectIdentifierType('10.1594/WDCC/CMIP5.NCCNMpc')).toBe('Handle');
            });

            it('detects FDO type Handle: 21.T11998/0000-001A-3905-1', () => {
                expect(detectIdentifierType('21.T11998/0000-001A-3905-1')).toBe('Handle');
            });

            it('detects PIDINST instrument Handle: 21.T11148/7adfcd13b3b01de0d875', () => {
                expect(detectIdentifierType('21.T11148/7adfcd13b3b01de0d875')).toBe('Handle');
            });

            it('detects CORDRA Handle: 21.T11148/c2c8c452912d57a44117', () => {
                expect(detectIdentifierType('21.T11148/c2c8c452912d57a44117')).toBe('Handle');
            });

            it('detects GWDG Handle: 21.11145/8fefa88dea', () => {
                expect(detectIdentifierType('21.11145/8fefa88dea')).toBe('Handle');
            });

            it('detects UUID-based Handle: 11148/d041e5f0-a1b2-c3d4-e5f6-789abcdef000', () => {
                expect(detectIdentifierType('11148/d041e5f0-a1b2-c3d4-e5f6-789abcdef000')).toBe('Handle');
            });

            it('detects hierarchical Handle: 1234/test.object.climate.2024.v1', () => {
                expect(detectIdentifierType('1234/test.object.climate.2024.v1')).toBe('Handle');
            });

            it('detects descriptive Handle: 2142/data_archive_collection_2024_001', () => {
                expect(detectIdentifierType('2142/data_archive_collection_2024_001')).toBe('Handle');
            });
        });

        describe('Handle with http resolver URL', () => {
            it('detects http hdl.handle.net URL: http://hdl.handle.net/2142/103380', () => {
                expect(detectIdentifierType('http://hdl.handle.net/2142/103380')).toBe('Handle');
            });

            it('detects http URL for BiCIKL: http://hdl.handle.net/11148/btv1b8449691v', () => {
                expect(detectIdentifierType('http://hdl.handle.net/11148/btv1b8449691v')).toBe('Handle');
            });
        });

        describe('Handle with https resolver URL', () => {
            it('detects https hdl.handle.net URL: https://hdl.handle.net/2142/103380', () => {
                expect(detectIdentifierType('https://hdl.handle.net/2142/103380')).toBe('Handle');
            });

            it('detects https URL for BiCIKL: https://hdl.handle.net/11148/btv1b8449691v', () => {
                expect(detectIdentifierType('https://hdl.handle.net/11148/btv1b8449691v')).toBe('Handle');
            });

            it('detects https URL for WDCC: https://hdl.handle.net/10.1594/WDCC/CMIP5.NCCNMpc', () => {
                expect(detectIdentifierType('https://hdl.handle.net/10.1594/WDCC/CMIP5.NCCNMpc')).toBe('Handle');
            });

            it('detects https URL for FDO: https://hdl.handle.net/21.T11998/0000-001A-3905-1', () => {
                expect(detectIdentifierType('https://hdl.handle.net/21.T11998/0000-001A-3905-1')).toBe('Handle');
            });

            it('detects https URL for PIDINST: https://hdl.handle.net/21.T11148/7adfcd13b3b01de0d875', () => {
                expect(detectIdentifierType('https://hdl.handle.net/21.T11148/7adfcd13b3b01de0d875')).toBe('Handle');
            });

            it('detects https URL for CORDRA: https://hdl.handle.net/21.T11148/c2c8c452912d57a44117', () => {
                expect(detectIdentifierType('https://hdl.handle.net/21.T11148/c2c8c452912d57a44117')).toBe('Handle');
            });

            it('detects https URL for GWDG: https://hdl.handle.net/21.11145/8fefa88dea', () => {
                expect(detectIdentifierType('https://hdl.handle.net/21.11145/8fefa88dea')).toBe('Handle');
            });

            it('detects https URL for UUID: https://hdl.handle.net/11148/d041e5f0-a1b2-c3d4-e5f6-789abcdef000', () => {
                expect(detectIdentifierType('https://hdl.handle.net/11148/d041e5f0-a1b2-c3d4-e5f6-789abcdef000')).toBe(
                    'Handle',
                );
            });

            it('detects https URL for hierarchical: https://hdl.handle.net/1234/test.object.climate.2024.v1', () => {
                expect(detectIdentifierType('https://hdl.handle.net/1234/test.object.climate.2024.v1')).toBe('Handle');
            });

            it('detects https URL for descriptive: https://hdl.handle.net/2142/data_archive_collection_2024_001', () => {
                expect(detectIdentifierType('https://hdl.handle.net/2142/data_archive_collection_2024_001')).toBe(
                    'Handle',
                );
            });
        });

        describe('Handle with noredirect query parameter', () => {
            it('detects URL with noredirect: https://hdl.handle.net/2142/103380?noredirect', () => {
                expect(detectIdentifierType('https://hdl.handle.net/2142/103380?noredirect')).toBe('Handle');
            });

            it('detects BiCIKL with noredirect: https://hdl.handle.net/11148/btv1b8449691v?noredirect', () => {
                expect(detectIdentifierType('https://hdl.handle.net/11148/btv1b8449691v?noredirect')).toBe('Handle');
            });

            it('detects FDO with noredirect: https://hdl.handle.net/21.T11998/0000-001A-3905-1?noredirect', () => {
                expect(detectIdentifierType('https://hdl.handle.net/21.T11998/0000-001A-3905-1?noredirect')).toBe(
                    'Handle',
                );
            });

            it('detects PIDINST with noredirect: https://hdl.handle.net/21.T11148/7adfcd13b3b01de0d875?noredirect', () => {
                expect(detectIdentifierType('https://hdl.handle.net/21.T11148/7adfcd13b3b01de0d875?noredirect')).toBe(
                    'Handle',
                );
            });

            it('detects CORDRA with noredirect: https://hdl.handle.net/21.T11148/c2c8c452912d57a44117?noredirect', () => {
                expect(detectIdentifierType('https://hdl.handle.net/21.T11148/c2c8c452912d57a44117?noredirect')).toBe(
                    'Handle',
                );
            });

            it('detects hierarchical with noredirect: https://hdl.handle.net/1234/test.object.climate.2024.v1?noredirect', () => {
                expect(
                    detectIdentifierType('https://hdl.handle.net/1234/test.object.climate.2024.v1?noredirect'),
                ).toBe('Handle');
            });
        });

        describe('Handle with auth query parameter', () => {
            it('detects URL with auth: https://hdl.handle.net/2142/data_archive_collection_2024_001?auth', () => {
                expect(detectIdentifierType('https://hdl.handle.net/2142/data_archive_collection_2024_001?auth')).toBe(
                    'Handle',
                );
            });
        });

        describe('Handle REST API URLs', () => {
            it('detects API URL: https://hdl.handle.net/api/handles/2142/103380', () => {
                expect(detectIdentifierType('https://hdl.handle.net/api/handles/2142/103380')).toBe('Handle');
            });

            it('detects API URL for BiCIKL: https://hdl.handle.net/api/handles/11148/btv1b8449691v', () => {
                expect(detectIdentifierType('https://hdl.handle.net/api/handles/11148/btv1b8449691v')).toBe('Handle');
            });

            it('detects API URL for WDCC: https://hdl.handle.net/api/handles/10.1594/WDCC/CMIP5.NCCNMpc', () => {
                expect(detectIdentifierType('https://hdl.handle.net/api/handles/10.1594/WDCC/CMIP5.NCCNMpc')).toBe(
                    'Handle',
                );
            });

            it('detects API URL for FDO: https://hdl.handle.net/api/handles/21.T11998/0000-001A-3905-1', () => {
                expect(detectIdentifierType('https://hdl.handle.net/api/handles/21.T11998/0000-001A-3905-1')).toBe(
                    'Handle',
                );
            });

            it('detects API URL for PIDINST: https://hdl.handle.net/api/handles/21.T11148/7adfcd13b3b01de0d875', () => {
                expect(detectIdentifierType('https://hdl.handle.net/api/handles/21.T11148/7adfcd13b3b01de0d875')).toBe(
                    'Handle',
                );
            });

            it('detects API URL for CORDRA: https://hdl.handle.net/api/handles/21.T11148/c2c8c452912d57a44117', () => {
                expect(detectIdentifierType('https://hdl.handle.net/api/handles/21.T11148/c2c8c452912d57a44117')).toBe(
                    'Handle',
                );
            });

            it('detects API URL for GWDG: https://hdl.handle.net/api/handles/21.11145/8fefa88dea', () => {
                expect(detectIdentifierType('https://hdl.handle.net/api/handles/21.11145/8fefa88dea')).toBe('Handle');
            });

            it('detects API URL for UUID: https://hdl.handle.net/api/handles/11148/d041e5f0-a1b2-c3d4-e5f6-789abcdef000', () => {
                expect(
                    detectIdentifierType('https://hdl.handle.net/api/handles/11148/d041e5f0-a1b2-c3d4-e5f6-789abcdef000'),
                ).toBe('Handle');
            });

            it('detects API URL for hierarchical: https://hdl.handle.net/api/handles/1234/test.object.climate.2024.v1', () => {
                expect(
                    detectIdentifierType('https://hdl.handle.net/api/handles/1234/test.object.climate.2024.v1'),
                ).toBe('Handle');
            });

            it('detects API URL for descriptive: https://hdl.handle.net/api/handles/2142/data_archive_collection_2024_001', () => {
                expect(
                    detectIdentifierType('https://hdl.handle.net/api/handles/2142/data_archive_collection_2024_001'),
                ).toBe('Handle');
            });
        });

        describe('Handle with hdl:// protocol', () => {
            it('detects hdl:// protocol: hdl://11148/btv1b8449691v', () => {
                expect(detectIdentifierType('hdl://11148/btv1b8449691v')).toBe('Handle');
            });

            it('detects hdl:// for FDO: hdl://21.T11998/0000-001A-3905-1', () => {
                expect(detectIdentifierType('hdl://21.T11998/0000-001A-3905-1')).toBe('Handle');
            });

            it('detects hdl:// for CORDRA: hdl://21.T11148/c2c8c452912d57a44117', () => {
                expect(detectIdentifierType('hdl://21.T11148/c2c8c452912d57a44117')).toBe('Handle');
            });

            it('detects hdl:// for UUID: hdl://11148/d041e5f0-a1b2-c3d4-e5f6-789abcdef000', () => {
                expect(detectIdentifierType('hdl://11148/d041e5f0-a1b2-c3d4-e5f6-789abcdef000')).toBe('Handle');
            });

            it('detects hdl:// for descriptive: hdl://2142/data_archive_collection_2024_001', () => {
                expect(detectIdentifierType('hdl://2142/data_archive_collection_2024_001')).toBe('Handle');
            });
        });

        describe('Handle with URN format', () => {
            it('detects URN format: urn:handle:2142/103380', () => {
                expect(detectIdentifierType('urn:handle:2142/103380')).toBe('Handle');
            });

            it('detects URN for BiCIKL: urn:handle:11148/btv1b8449691v', () => {
                expect(detectIdentifierType('urn:handle:11148/btv1b8449691v')).toBe('Handle');
            });

            it('detects URN for FDO: urn:handle:21.T11998/0000-001A-3905-1', () => {
                expect(detectIdentifierType('urn:handle:21.T11998/0000-001A-3905-1')).toBe('Handle');
            });

            it('detects URN for PIDINST: urn:handle:21.T11148/7adfcd13b3b01de0d875', () => {
                expect(detectIdentifierType('urn:handle:21.T11148/7adfcd13b3b01de0d875')).toBe('Handle');
            });

            it('detects URN for GWDG: urn:handle:21.11145/8fefa88dea', () => {
                expect(detectIdentifierType('urn:handle:21.11145/8fefa88dea')).toBe('Handle');
            });

            it('detects URN for hierarchical: urn:handle:1234/test.object.climate.2024.v1', () => {
                expect(detectIdentifierType('urn:handle:1234/test.object.climate.2024.v1')).toBe('Handle');
            });
        });

        describe('Handle with custom resolvers', () => {
            it('detects GWDG resolver URL: https://vm11.pid.gwdg.de:8445/objects/21.11145/8fefa88dea', () => {
                expect(detectIdentifierType('https://vm11.pid.gwdg.de:8445/objects/21.11145/8fefa88dea')).toBe(
                    'Handle',
                );
            });
        });

        describe('Handle edge cases', () => {
            it('handles leading/trailing whitespace', () => {
                expect(detectIdentifierType('  2142/103380  ')).toBe('Handle');
            });

            it('handles URL with leading/trailing whitespace', () => {
                expect(detectIdentifierType('  https://hdl.handle.net/2142/103380  ')).toBe('Handle');
            });

            it('handles URN with leading/trailing whitespace', () => {
                expect(detectIdentifierType('  urn:handle:11148/btv1b8449691v  ')).toBe('Handle');
            });

            it('handles hdl:// with leading/trailing whitespace', () => {
                expect(detectIdentifierType('  hdl://21.T11998/0000-001A-3905-1  ')).toBe('Handle');
            });
        });

        describe('real-world Handle examples from user requirements', () => {
            // 1. Library of Congress (LOC) Handle
            it('detects LOC compact: 2142/103380', () => {
                expect(detectIdentifierType('2142/103380')).toBe('Handle');
            });

            it('detects LOC http resolver: http://hdl.handle.net/2142/103380', () => {
                expect(detectIdentifierType('http://hdl.handle.net/2142/103380')).toBe('Handle');
            });

            it('detects LOC https resolver: https://hdl.handle.net/2142/103380', () => {
                expect(detectIdentifierType('https://hdl.handle.net/2142/103380')).toBe('Handle');
            });

            it('detects LOC with noredirect: https://hdl.handle.net/2142/103380?noredirect', () => {
                expect(detectIdentifierType('https://hdl.handle.net/2142/103380?noredirect')).toBe('Handle');
            });

            it('detects LOC REST API: https://hdl.handle.net/api/handles/2142/103380', () => {
                expect(detectIdentifierType('https://hdl.handle.net/api/handles/2142/103380')).toBe('Handle');
            });

            it('detects LOC URN: urn:handle:2142/103380', () => {
                expect(detectIdentifierType('urn:handle:2142/103380')).toBe('Handle');
            });

            // 2. BiCIKL Digital Specimen Handle
            it('detects BiCIKL compact: 11148/btv1b8449691v', () => {
                expect(detectIdentifierType('11148/btv1b8449691v')).toBe('Handle');
            });

            it('detects BiCIKL https: https://hdl.handle.net/11148/btv1b8449691v', () => {
                expect(detectIdentifierType('https://hdl.handle.net/11148/btv1b8449691v')).toBe('Handle');
            });

            it('detects BiCIKL hdl protocol: hdl://11148/btv1b8449691v', () => {
                expect(detectIdentifierType('hdl://11148/btv1b8449691v')).toBe('Handle');
            });

            it('detects BiCIKL noredirect: https://hdl.handle.net/11148/btv1b8449691v?noredirect', () => {
                expect(detectIdentifierType('https://hdl.handle.net/11148/btv1b8449691v?noredirect')).toBe('Handle');
            });

            it('detects BiCIKL API: https://hdl.handle.net/api/handles/11148/btv1b8449691v', () => {
                expect(detectIdentifierType('https://hdl.handle.net/api/handles/11148/btv1b8449691v')).toBe('Handle');
            });

            it('detects BiCIKL URN: urn:handle:11148/btv1b8449691v', () => {
                expect(detectIdentifierType('urn:handle:11148/btv1b8449691v')).toBe('Handle');
            });

            // 3. WDCC Climate Data (DOI-style prefix)
            it('detects WDCC compact: 10.1594/WDCC/CMIP5.NCCNMpc', () => {
                expect(detectIdentifierType('10.1594/WDCC/CMIP5.NCCNMpc')).toBe('Handle');
            });

            it('detects WDCC Handle URL: https://hdl.handle.net/10.1594/WDCC/CMIP5.NCCNMpc', () => {
                expect(detectIdentifierType('https://hdl.handle.net/10.1594/WDCC/CMIP5.NCCNMpc')).toBe('Handle');
            });

            it('detects WDCC API: https://hdl.handle.net/api/handles/10.1594/WDCC/CMIP5.NCCNMpc', () => {
                expect(detectIdentifierType('https://hdl.handle.net/api/handles/10.1594/WDCC/CMIP5.NCCNMpc')).toBe(
                    'Handle',
                );
            });

            // 4. FAIR Digital Object Type Definition
            it('detects FDO compact: 21.T11998/0000-001A-3905-1', () => {
                expect(detectIdentifierType('21.T11998/0000-001A-3905-1')).toBe('Handle');
            });

            it('detects FDO https with noredirect: https://hdl.handle.net/21.T11998/0000-001A-3905-1?noredirect', () => {
                expect(detectIdentifierType('https://hdl.handle.net/21.T11998/0000-001A-3905-1?noredirect')).toBe(
                    'Handle',
                );
            });

            it('detects FDO hdl protocol: hdl://21.T11998/0000-001A-3905-1', () => {
                expect(detectIdentifierType('hdl://21.T11998/0000-001A-3905-1')).toBe('Handle');
            });

            it('detects FDO API: https://hdl.handle.net/api/handles/21.T11998/0000-001A-3905-1', () => {
                expect(detectIdentifierType('https://hdl.handle.net/api/handles/21.T11998/0000-001A-3905-1')).toBe(
                    'Handle',
                );
            });

            it('detects FDO URN: urn:handle:21.T11998/0000-001A-3905-1', () => {
                expect(detectIdentifierType('urn:handle:21.T11998/0000-001A-3905-1')).toBe('Handle');
            });

            // 5. PIDINST Instrument Identifier
            it('detects PIDINST compact: 21.T11148/7adfcd13b3b01de0d875', () => {
                expect(detectIdentifierType('21.T11148/7adfcd13b3b01de0d875')).toBe('Handle');
            });

            it('detects PIDINST https: https://hdl.handle.net/21.T11148/7adfcd13b3b01de0d875', () => {
                expect(detectIdentifierType('https://hdl.handle.net/21.T11148/7adfcd13b3b01de0d875')).toBe('Handle');
            });

            it('detects PIDINST noredirect: https://hdl.handle.net/21.T11148/7adfcd13b3b01de0d875?noredirect', () => {
                expect(detectIdentifierType('https://hdl.handle.net/21.T11148/7adfcd13b3b01de0d875?noredirect')).toBe(
                    'Handle',
                );
            });

            it('detects PIDINST API: https://hdl.handle.net/api/handles/21.T11148/7adfcd13b3b01de0d875', () => {
                expect(detectIdentifierType('https://hdl.handle.net/api/handles/21.T11148/7adfcd13b3b01de0d875')).toBe(
                    'Handle',
                );
            });

            it('detects PIDINST URN: urn:handle:21.T11148/7adfcd13b3b01de0d875', () => {
                expect(detectIdentifierType('urn:handle:21.T11148/7adfcd13b3b01de0d875')).toBe('Handle');
            });

            // 6. CORDRA FDO Record Handle
            it('detects CORDRA compact: 21.T11148/c2c8c452912d57a44117', () => {
                expect(detectIdentifierType('21.T11148/c2c8c452912d57a44117')).toBe('Handle');
            });

            it('detects CORDRA https: https://hdl.handle.net/21.T11148/c2c8c452912d57a44117', () => {
                expect(detectIdentifierType('https://hdl.handle.net/21.T11148/c2c8c452912d57a44117')).toBe('Handle');
            });

            it('detects CORDRA API: https://hdl.handle.net/api/handles/21.T11148/c2c8c452912d57a44117', () => {
                expect(detectIdentifierType('https://hdl.handle.net/api/handles/21.T11148/c2c8c452912d57a44117')).toBe(
                    'Handle',
                );
            });

            it('detects CORDRA noredirect: https://hdl.handle.net/21.T11148/c2c8c452912d57a44117?noredirect', () => {
                expect(detectIdentifierType('https://hdl.handle.net/21.T11148/c2c8c452912d57a44117?noredirect')).toBe(
                    'Handle',
                );
            });

            it('detects CORDRA hdl protocol: hdl://21.T11148/c2c8c452912d57a44117', () => {
                expect(detectIdentifierType('hdl://21.T11148/c2c8c452912d57a44117')).toBe('Handle');
            });

            // 7. GWDG Research Infrastructure Handle
            it('detects GWDG compact: 21.11145/8fefa88dea', () => {
                expect(detectIdentifierType('21.11145/8fefa88dea')).toBe('Handle');
            });

            it('detects GWDG custom resolver: https://vm11.pid.gwdg.de:8445/objects/21.11145/8fefa88dea', () => {
                expect(detectIdentifierType('https://vm11.pid.gwdg.de:8445/objects/21.11145/8fefa88dea')).toBe(
                    'Handle',
                );
            });

            it('detects GWDG hdl.handle.net: https://hdl.handle.net/21.11145/8fefa88dea', () => {
                expect(detectIdentifierType('https://hdl.handle.net/21.11145/8fefa88dea')).toBe('Handle');
            });

            it('detects GWDG API: https://hdl.handle.net/api/handles/21.11145/8fefa88dea', () => {
                expect(detectIdentifierType('https://hdl.handle.net/api/handles/21.11145/8fefa88dea')).toBe('Handle');
            });

            it('detects GWDG URN: urn:handle:21.11145/8fefa88dea', () => {
                expect(detectIdentifierType('urn:handle:21.11145/8fefa88dea')).toBe('Handle');
            });

            // 8. UUID-based Handle
            it('detects UUID compact: 11148/d041e5f0-a1b2-c3d4-e5f6-789abcdef000', () => {
                expect(detectIdentifierType('11148/d041e5f0-a1b2-c3d4-e5f6-789abcdef000')).toBe('Handle');
            });

            it('detects UUID https: https://hdl.handle.net/11148/d041e5f0-a1b2-c3d4-e5f6-789abcdef000', () => {
                expect(detectIdentifierType('https://hdl.handle.net/11148/d041e5f0-a1b2-c3d4-e5f6-789abcdef000')).toBe(
                    'Handle',
                );
            });

            it('detects UUID hdl protocol: hdl://11148/d041e5f0-a1b2-c3d4-e5f6-789abcdef000', () => {
                expect(detectIdentifierType('hdl://11148/d041e5f0-a1b2-c3d4-e5f6-789abcdef000')).toBe('Handle');
            });

            it('detects UUID API: https://hdl.handle.net/api/handles/11148/d041e5f0-a1b2-c3d4-e5f6-789abcdef000', () => {
                expect(
                    detectIdentifierType('https://hdl.handle.net/api/handles/11148/d041e5f0-a1b2-c3d4-e5f6-789abcdef000'),
                ).toBe('Handle');
            });

            it('detects UUID noredirect: https://hdl.handle.net/11148/d041e5f0-a1b2-c3d4-e5f6-789abcdef000?noredirect', () => {
                expect(
                    detectIdentifierType('https://hdl.handle.net/11148/d041e5f0-a1b2-c3d4-e5f6-789abcdef000?noredirect'),
                ).toBe('Handle');
            });

            // 9. Hierarchisch strukturierter Handle
            it('detects hierarchical compact: 1234/test.object.climate.2024.v1', () => {
                expect(detectIdentifierType('1234/test.object.climate.2024.v1')).toBe('Handle');
            });

            it('detects hierarchical https: https://hdl.handle.net/1234/test.object.climate.2024.v1', () => {
                expect(detectIdentifierType('https://hdl.handle.net/1234/test.object.climate.2024.v1')).toBe('Handle');
            });

            it('detects hierarchical API: https://hdl.handle.net/api/handles/1234/test.object.climate.2024.v1', () => {
                expect(detectIdentifierType('https://hdl.handle.net/api/handles/1234/test.object.climate.2024.v1')).toBe(
                    'Handle',
                );
            });

            it('detects hierarchical noredirect: https://hdl.handle.net/1234/test.object.climate.2024.v1?noredirect', () => {
                expect(
                    detectIdentifierType('https://hdl.handle.net/1234/test.object.climate.2024.v1?noredirect'),
                ).toBe('Handle');
            });

            it('detects hierarchical URN: urn:handle:1234/test.object.climate.2024.v1', () => {
                expect(detectIdentifierType('urn:handle:1234/test.object.climate.2024.v1')).toBe('Handle');
            });

            // 10. Generischer Organisations-Handle
            it('detects descriptive compact: 2142/data_archive_collection_2024_001', () => {
                expect(detectIdentifierType('2142/data_archive_collection_2024_001')).toBe('Handle');
            });

            it('detects descriptive https: https://hdl.handle.net/2142/data_archive_collection_2024_001', () => {
                expect(detectIdentifierType('https://hdl.handle.net/2142/data_archive_collection_2024_001')).toBe(
                    'Handle',
                );
            });

            it('detects descriptive hdl protocol: hdl://2142/data_archive_collection_2024_001', () => {
                expect(detectIdentifierType('hdl://2142/data_archive_collection_2024_001')).toBe('Handle');
            });

            it('detects descriptive API: https://hdl.handle.net/api/handles/2142/data_archive_collection_2024_001', () => {
                expect(
                    detectIdentifierType('https://hdl.handle.net/api/handles/2142/data_archive_collection_2024_001'),
                ).toBe('Handle');
            });

            it('detects descriptive auth: https://hdl.handle.net/2142/data_archive_collection_2024_001?auth', () => {
                expect(detectIdentifierType('https://hdl.handle.net/2142/data_archive_collection_2024_001?auth')).toBe(
                    'Handle',
                );
            });
        });

        describe('Handle should NOT be detected for non-Handle identifiers', () => {
            it('should not detect plain URLs as Handle', () => {
                expect(detectIdentifierType('https://example.com/resource')).not.toBe('Handle');
            });

            it('should not detect arXiv IDs as Handle', () => {
                expect(detectIdentifierType('2501.13958')).not.toBe('Handle');
            });

            it('should not detect bibcodes as Handle', () => {
                expect(detectIdentifierType('2024AJ....167...20Z')).not.toBe('Handle');
            });

            it('should not detect ARK as Handle', () => {
                expect(detectIdentifierType('ark:12148/btv1b8449691v')).not.toBe('Handle');
            });

            it('should not detect CSTR as Handle', () => {
                expect(detectIdentifierType('CSTR:31253.11.sciencedb.j00001.00123')).not.toBe('Handle');
            });

            it('should not detect EAN-13 as Handle', () => {
                expect(detectIdentifierType('4006381333931')).not.toBe('Handle');
            });

            it('should not detect EISSN as Handle', () => {
                expect(detectIdentifierType('0378-5955')).not.toBe('Handle');
            });

            it('should not detect text without slash as Handle', () => {
                expect(detectIdentifierType('just-some-text')).not.toBe('Handle');
            });
        });
    });

    describe('IGSN detection', () => {
        /**
         * IGSN (International Generic Sample Number) is a persistent identifier
         * for physical samples in geoscience research.
         *
         * Format variations:
         * - Bare code: AU1101, SSH000SUA, BGRB5054RX05201
         * - With IGSN prefix: IGSN AU1101, IGSN:AU1101
         * - With igsn: tag: igsn:AU1101
         * - DOI form: 10.60516/AU1101, 10.58052/SSH000SUA
         * - DOI URL: https://doi.org/10.60516/AU1101
         * - Legacy Handle: https://igsn.org/10.273/AU1101
         * - URN: urn:igsn:AU1101
         */

        describe('IGSN bare code format', () => {
            it('detects Geoscience Australia IGSN: AU1101', () => {
                expect(detectIdentifierType('AU1101')).toBe('IGSN');
            });

            it('detects SESAR USA IGSN: SSH000SUA', () => {
                expect(detectIdentifierType('SSH000SUA')).toBe('IGSN');
            });

            it('detects BGR Germany IGSN: BGRB5054RX05201', () => {
                expect(detectIdentifierType('BGRB5054RX05201')).toBe('IGSN');
            });

            it('detects ICDP IGSN: ICDP5054ESYI201', () => {
                expect(detectIdentifierType('ICDP5054ESYI201')).toBe('IGSN');
            });

            it('detects ICDP borehole IGSN: ICDP5054EEW1001', () => {
                expect(detectIdentifierType('ICDP5054EEW1001')).toBe('IGSN');
            });

            it('detects CSIRO IGSN: CSRWA275', () => {
                expect(detectIdentifierType('CSRWA275')).toBe('IGSN');
            });

            it('detects CSIRO collection IGSN: CSRWASC00001', () => {
                expect(detectIdentifierType('CSRWASC00001')).toBe('IGSN');
            });

            it('detects GFZ IGSN: GFZ000001ABC', () => {
                expect(detectIdentifierType('GFZ000001ABC')).toBe('IGSN');
            });

            it('detects MARUM IGSN: MBCR5034RC57001', () => {
                expect(detectIdentifierType('MBCR5034RC57001')).toBe('IGSN');
            });

            it('detects ARDC IGSN: ARDC2024001XYZ', () => {
                expect(detectIdentifierType('ARDC2024001XYZ')).toBe('IGSN');
            });
        });

        describe('IGSN with IGSN prefix', () => {
            it('detects IGSN space prefix: IGSN AU1101', () => {
                expect(detectIdentifierType('IGSN AU1101')).toBe('IGSN');
            });

            it('detects IGSN space prefix: IGSN SSH000SUA', () => {
                expect(detectIdentifierType('IGSN SSH000SUA')).toBe('IGSN');
            });

            it('detects IGSN space prefix: IGSN BGRB5054RX05201', () => {
                expect(detectIdentifierType('IGSN BGRB5054RX05201')).toBe('IGSN');
            });

            it('detects IGSN space prefix: IGSN ICDP5054ESYI201', () => {
                expect(detectIdentifierType('IGSN ICDP5054ESYI201')).toBe('IGSN');
            });

            it('detects IGSN space prefix: IGSN CSRWA275', () => {
                expect(detectIdentifierType('IGSN CSRWA275')).toBe('IGSN');
            });

            it('detects IGSN space prefix: IGSN GFZ000001ABC', () => {
                expect(detectIdentifierType('IGSN GFZ000001ABC')).toBe('IGSN');
            });

            it('detects IGSN space prefix: IGSN ARDC2024001XYZ', () => {
                expect(detectIdentifierType('IGSN ARDC2024001XYZ')).toBe('IGSN');
            });
        });

        describe('IGSN with igsn: tag prefix', () => {
            it('detects igsn: tag: igsn:AU1101', () => {
                expect(detectIdentifierType('igsn:AU1101')).toBe('IGSN');
            });

            it('detects igsn: tag: igsn:SSH000SUA', () => {
                expect(detectIdentifierType('igsn:SSH000SUA')).toBe('IGSN');
            });

            it('detects igsn: tag: igsn:BGRB5054RX05201', () => {
                expect(detectIdentifierType('igsn:BGRB5054RX05201')).toBe('IGSN');
            });

            it('detects igsn: tag: igsn:ICDP5054ESYI201', () => {
                expect(detectIdentifierType('igsn:ICDP5054ESYI201')).toBe('IGSN');
            });

            it('detects igsn: tag: igsn:ICDP5054EEW1001', () => {
                expect(detectIdentifierType('igsn:ICDP5054EEW1001')).toBe('IGSN');
            });

            it('detects igsn: tag: igsn:CSRWA275', () => {
                expect(detectIdentifierType('igsn:CSRWA275')).toBe('IGSN');
            });

            it('detects igsn: tag: igsn:CSRWASC00001', () => {
                expect(detectIdentifierType('igsn:CSRWASC00001')).toBe('IGSN');
            });

            it('detects igsn: tag: igsn:GFZ000001ABC', () => {
                expect(detectIdentifierType('igsn:GFZ000001ABC')).toBe('IGSN');
            });

            it('detects igsn: tag: igsn:MBCR5034RC57001', () => {
                expect(detectIdentifierType('igsn:MBCR5034RC57001')).toBe('IGSN');
            });

            it('detects igsn: tag: igsn:ARDC2024001XYZ', () => {
                expect(detectIdentifierType('igsn:ARDC2024001XYZ')).toBe('IGSN');
            });
        });

        describe('IGSN DOI form (bare)', () => {
            it('detects IGSN DOI: 10.60516/AU1101', () => {
                expect(detectIdentifierType('10.60516/AU1101')).toBe('IGSN');
            });

            it('detects IGSN DOI: 10.58052/SSH000SUA', () => {
                expect(detectIdentifierType('10.58052/SSH000SUA')).toBe('IGSN');
            });

            it('detects IGSN DOI: 10.60510/BGRB5054RX05201', () => {
                expect(detectIdentifierType('10.60510/BGRB5054RX05201')).toBe('IGSN');
            });

            it('detects IGSN DOI: 10.60510/ICDP5054ESYI201', () => {
                expect(detectIdentifierType('10.60510/ICDP5054ESYI201')).toBe('IGSN');
            });

            it('detects IGSN DOI: 10.60510/ICDP5054EEW1001', () => {
                expect(detectIdentifierType('10.60510/ICDP5054EEW1001')).toBe('IGSN');
            });

            it('detects IGSN DOI: 10.58108/CSRWA275', () => {
                expect(detectIdentifierType('10.58108/CSRWA275')).toBe('IGSN');
            });

            it('detects IGSN DOI: 10.58108/CSRWASC00001', () => {
                expect(detectIdentifierType('10.58108/CSRWASC00001')).toBe('IGSN');
            });

            it('detects IGSN DOI: 10.60510/GFZ000001ABC', () => {
                expect(detectIdentifierType('10.60510/GFZ000001ABC')).toBe('IGSN');
            });

            it('detects IGSN DOI: 10.58095/MBCR5034RC57001', () => {
                expect(detectIdentifierType('10.58095/MBCR5034RC57001')).toBe('IGSN');
            });

            it('detects IGSN DOI: 10.60516/ARDC2024001XYZ', () => {
                expect(detectIdentifierType('10.60516/ARDC2024001XYZ')).toBe('IGSN');
            });
        });

        describe('IGSN DOI URL form', () => {
            it('detects IGSN DOI URL: https://doi.org/10.60516/AU1101', () => {
                expect(detectIdentifierType('https://doi.org/10.60516/AU1101')).toBe('IGSN');
            });

            it('detects IGSN DOI URL: https://doi.org/10.58052/SSH000SUA', () => {
                expect(detectIdentifierType('https://doi.org/10.58052/SSH000SUA')).toBe('IGSN');
            });

            it('detects IGSN DOI URL: https://doi.org/10.60510/BGRB5054RX05201', () => {
                expect(detectIdentifierType('https://doi.org/10.60510/BGRB5054RX05201')).toBe('IGSN');
            });

            it('detects IGSN DOI URL: https://doi.org/10.60510/ICDP5054ESYI201', () => {
                expect(detectIdentifierType('https://doi.org/10.60510/ICDP5054ESYI201')).toBe('IGSN');
            });

            it('detects IGSN DOI URL: https://doi.org/10.60510/ICDP5054EEW1001', () => {
                expect(detectIdentifierType('https://doi.org/10.60510/ICDP5054EEW1001')).toBe('IGSN');
            });

            it('detects IGSN DOI URL: https://doi.org/10.58108/CSRWA275', () => {
                expect(detectIdentifierType('https://doi.org/10.58108/CSRWA275')).toBe('IGSN');
            });

            it('detects IGSN DOI URL: https://doi.org/10.58108/CSRWASC00001', () => {
                expect(detectIdentifierType('https://doi.org/10.58108/CSRWASC00001')).toBe('IGSN');
            });

            it('detects IGSN DOI URL: https://doi.org/10.60510/GFZ000001ABC', () => {
                expect(detectIdentifierType('https://doi.org/10.60510/GFZ000001ABC')).toBe('IGSN');
            });

            it('detects IGSN DOI URL: https://doi.org/10.58095/MBCR5034RC57001', () => {
                expect(detectIdentifierType('https://doi.org/10.58095/MBCR5034RC57001')).toBe('IGSN');
            });

            it('detects IGSN DOI URL: https://doi.org/10.60516/ARDC2024001XYZ', () => {
                expect(detectIdentifierType('https://doi.org/10.60516/ARDC2024001XYZ')).toBe('IGSN');
            });
        });

        describe('IGSN legacy Handle URL', () => {
            it('detects legacy Handle: https://igsn.org/10.273/AU1101', () => {
                expect(detectIdentifierType('https://igsn.org/10.273/AU1101')).toBe('IGSN');
            });

            it('detects legacy Handle: https://igsn.org/10.273/BGRB5054RX05201', () => {
                expect(detectIdentifierType('https://igsn.org/10.273/BGRB5054RX05201')).toBe('IGSN');
            });
        });

        describe('IGSN URN format', () => {
            it('detects URN: urn:igsn:AU1101', () => {
                expect(detectIdentifierType('urn:igsn:AU1101')).toBe('IGSN');
            });

            it('detects URN: urn:igsn:SSH000SUA', () => {
                expect(detectIdentifierType('urn:igsn:SSH000SUA')).toBe('IGSN');
            });

            it('detects URN: urn:igsn:BGRB5054RX05201', () => {
                expect(detectIdentifierType('urn:igsn:BGRB5054RX05201')).toBe('IGSN');
            });

            it('detects URN: urn:igsn:ICDP5054ESYI201', () => {
                expect(detectIdentifierType('urn:igsn:ICDP5054ESYI201')).toBe('IGSN');
            });

            it('detects URN: urn:igsn:ICDP5054EEW1001', () => {
                expect(detectIdentifierType('urn:igsn:ICDP5054EEW1001')).toBe('IGSN');
            });

            it('detects URN: urn:igsn:CSRWA275', () => {
                expect(detectIdentifierType('urn:igsn:CSRWA275')).toBe('IGSN');
            });

            it('detects URN: urn:igsn:CSRWASC00001', () => {
                expect(detectIdentifierType('urn:igsn:CSRWASC00001')).toBe('IGSN');
            });

            it('detects URN: urn:igsn:GFZ000001ABC', () => {
                expect(detectIdentifierType('urn:igsn:GFZ000001ABC')).toBe('IGSN');
            });

            it('detects URN: urn:igsn:MBCR5034RC57001', () => {
                expect(detectIdentifierType('urn:igsn:MBCR5034RC57001')).toBe('IGSN');
            });

            it('detects URN: urn:igsn:ARDC2024001XYZ', () => {
                expect(detectIdentifierType('urn:igsn:ARDC2024001XYZ')).toBe('IGSN');
            });
        });

        describe('IGSN case-insensitive handling', () => {
            it('detects lowercase igsn: tag: igsn:au1101', () => {
                expect(detectIdentifierType('igsn:au1101')).toBe('IGSN');
            });

            it('detects lowercase bare code: ssh000sua', () => {
                expect(detectIdentifierType('ssh000sua')).toBe('IGSN');
            });

            it('detects lowercase igsn: tag: igsn:icdp5054esyi201', () => {
                expect(detectIdentifierType('igsn:icdp5054esyi201')).toBe('IGSN');
            });

            it('detects lowercase igsn: tag: igsn:csrwa275', () => {
                expect(detectIdentifierType('igsn:csrwa275')).toBe('IGSN');
            });
        });

        describe('IGSN edge cases', () => {
            it('handles leading/trailing whitespace', () => {
                expect(detectIdentifierType('  IGSN AU1101  ')).toBe('IGSN');
            });

            it('handles DOI URL with leading/trailing whitespace', () => {
                expect(detectIdentifierType('  https://doi.org/10.60516/AU1101  ')).toBe('IGSN');
            });

            it('handles URN with leading/trailing whitespace', () => {
                expect(detectIdentifierType('  urn:igsn:SSH000SUA  ')).toBe('IGSN');
            });
        });

        describe('real-world IGSN examples from user requirements', () => {
            // 1. Geoscience Australia Sample
            it('detects AU bare code: AU1101', () => {
                expect(detectIdentifierType('AU1101')).toBe('IGSN');
            });

            it('detects AU with IGSN prefix: IGSN AU1101', () => {
                expect(detectIdentifierType('IGSN AU1101')).toBe('IGSN');
            });

            it('detects AU with igsn: tag: igsn:AU1101', () => {
                expect(detectIdentifierType('igsn:AU1101')).toBe('IGSN');
            });

            it('detects AU DOI form: 10.60516/AU1101', () => {
                expect(detectIdentifierType('10.60516/AU1101')).toBe('IGSN');
            });

            it('detects AU DOI URL: https://doi.org/10.60516/AU1101', () => {
                expect(detectIdentifierType('https://doi.org/10.60516/AU1101')).toBe('IGSN');
            });

            it('detects AU legacy Handle: https://igsn.org/10.273/AU1101', () => {
                expect(detectIdentifierType('https://igsn.org/10.273/AU1101')).toBe('IGSN');
            });

            it('detects AU URN: urn:igsn:AU1101', () => {
                expect(detectIdentifierType('urn:igsn:AU1101')).toBe('IGSN');
            });

            it('detects AU case-insensitive: igsn:au1101', () => {
                expect(detectIdentifierType('igsn:au1101')).toBe('IGSN');
            });

            // 2. Susquehanna Shale Hills CZO (USA) - SESAR
            it('detects SSH bare code: SSH000SUA', () => {
                expect(detectIdentifierType('SSH000SUA')).toBe('IGSN');
            });

            it('detects SSH with IGSN prefix: IGSN SSH000SUA', () => {
                expect(detectIdentifierType('IGSN SSH000SUA')).toBe('IGSN');
            });

            it('detects SSH with igsn: tag: igsn:SSH000SUA', () => {
                expect(detectIdentifierType('igsn:SSH000SUA')).toBe('IGSN');
            });

            it('detects SSH DOI form: 10.58052/SSH000SUA', () => {
                expect(detectIdentifierType('10.58052/SSH000SUA')).toBe('IGSN');
            });

            it('detects SSH DOI URL: https://doi.org/10.58052/SSH000SUA', () => {
                expect(detectIdentifierType('https://doi.org/10.58052/SSH000SUA')).toBe('IGSN');
            });

            it('detects SSH URN: urn:igsn:SSH000SUA', () => {
                expect(detectIdentifierType('urn:igsn:SSH000SUA')).toBe('IGSN');
            });

            it('detects SSH case-insensitive: ssh000sua', () => {
                expect(detectIdentifierType('ssh000sua')).toBe('IGSN');
            });

            // 3. German Federal Geological Survey (BGR) Bohrkernsample
            it('detects BGR bare code: BGRB5054RX05201', () => {
                expect(detectIdentifierType('BGRB5054RX05201')).toBe('IGSN');
            });

            it('detects BGR with IGSN prefix: IGSN BGRB5054RX05201', () => {
                expect(detectIdentifierType('IGSN BGRB5054RX05201')).toBe('IGSN');
            });

            it('detects BGR with igsn: tag: igsn:BGRB5054RX05201', () => {
                expect(detectIdentifierType('igsn:BGRB5054RX05201')).toBe('IGSN');
            });

            it('detects BGR modern DOI: 10.60510/BGRB5054RX05201', () => {
                expect(detectIdentifierType('10.60510/BGRB5054RX05201')).toBe('IGSN');
            });

            it('detects BGR DOI URL: https://doi.org/10.60510/BGRB5054RX05201', () => {
                expect(detectIdentifierType('https://doi.org/10.60510/BGRB5054RX05201')).toBe('IGSN');
            });

            it('detects BGR legacy Handle: https://igsn.org/10.273/BGRB5054RX05201', () => {
                expect(detectIdentifierType('https://igsn.org/10.273/BGRB5054RX05201')).toBe('IGSN');
            });

            it('detects BGR URN: urn:igsn:BGRB5054RX05201', () => {
                expect(detectIdentifierType('urn:igsn:BGRB5054RX05201')).toBe('IGSN');
            });

            // 4. International Continental Drilling Program (ICDP) Sample
            it('detects ICDP bare code: ICDP5054ESYI201', () => {
                expect(detectIdentifierType('ICDP5054ESYI201')).toBe('IGSN');
            });

            it('detects ICDP with IGSN prefix: IGSN ICDP5054ESYI201', () => {
                expect(detectIdentifierType('IGSN ICDP5054ESYI201')).toBe('IGSN');
            });

            it('detects ICDP with igsn: tag: igsn:ICDP5054ESYI201', () => {
                expect(detectIdentifierType('igsn:ICDP5054ESYI201')).toBe('IGSN');
            });

            it('detects ICDP DOI form: 10.60510/ICDP5054ESYI201', () => {
                expect(detectIdentifierType('10.60510/ICDP5054ESYI201')).toBe('IGSN');
            });

            it('detects ICDP DOI URL: https://doi.org/10.60510/ICDP5054ESYI201', () => {
                expect(detectIdentifierType('https://doi.org/10.60510/ICDP5054ESYI201')).toBe('IGSN');
            });

            it('detects ICDP URN: urn:igsn:ICDP5054ESYI201', () => {
                expect(detectIdentifierType('urn:igsn:ICDP5054ESYI201')).toBe('IGSN');
            });

            it('detects ICDP case-insensitive: igsn:icdp5054esyi201', () => {
                expect(detectIdentifierType('igsn:icdp5054esyi201')).toBe('IGSN');
            });

            // 5. ICDP Borehole Sampling Feature
            it('detects ICDP borehole bare code: ICDP5054EEW1001', () => {
                expect(detectIdentifierType('ICDP5054EEW1001')).toBe('IGSN');
            });

            it('detects ICDP borehole with IGSN prefix: IGSN ICDP5054EEW1001', () => {
                expect(detectIdentifierType('IGSN ICDP5054EEW1001')).toBe('IGSN');
            });

            it('detects ICDP borehole with igsn: tag: igsn:ICDP5054EEW1001', () => {
                expect(detectIdentifierType('igsn:ICDP5054EEW1001')).toBe('IGSN');
            });

            it('detects ICDP borehole DOI form: 10.60510/ICDP5054EEW1001', () => {
                expect(detectIdentifierType('10.60510/ICDP5054EEW1001')).toBe('IGSN');
            });

            it('detects ICDP borehole DOI URL: https://doi.org/10.60510/ICDP5054EEW1001', () => {
                expect(detectIdentifierType('https://doi.org/10.60510/ICDP5054EEW1001')).toBe('IGSN');
            });

            it('detects ICDP borehole URN: urn:igsn:ICDP5054EEW1001', () => {
                expect(detectIdentifierType('urn:igsn:ICDP5054EEW1001')).toBe('IGSN');
            });

            // 6. CSIRO Australian Resources Research Centre Sample
            it('detects CSIRO bare code: CSRWA275', () => {
                expect(detectIdentifierType('CSRWA275')).toBe('IGSN');
            });

            it('detects CSIRO with IGSN prefix: IGSN CSRWA275', () => {
                expect(detectIdentifierType('IGSN CSRWA275')).toBe('IGSN');
            });

            it('detects CSIRO with igsn: tag: igsn:CSRWA275', () => {
                expect(detectIdentifierType('igsn:CSRWA275')).toBe('IGSN');
            });

            it('detects CSIRO DOI form: 10.58108/CSRWA275', () => {
                expect(detectIdentifierType('10.58108/CSRWA275')).toBe('IGSN');
            });

            it('detects CSIRO DOI URL: https://doi.org/10.58108/CSRWA275', () => {
                expect(detectIdentifierType('https://doi.org/10.58108/CSRWA275')).toBe('IGSN');
            });

            it('detects CSIRO URN: urn:igsn:CSRWA275', () => {
                expect(detectIdentifierType('urn:igsn:CSRWA275')).toBe('IGSN');
            });

            it('detects CSIRO case-insensitive: igsn:csrwa275', () => {
                expect(detectIdentifierType('igsn:csrwa275')).toBe('IGSN');
            });

            // 7. CSIRO Sub-Collection
            it('detects CSIRO collection bare code: CSRWASC00001', () => {
                expect(detectIdentifierType('CSRWASC00001')).toBe('IGSN');
            });

            it('detects CSIRO collection with IGSN prefix: IGSN CSRWASC00001', () => {
                expect(detectIdentifierType('IGSN CSRWASC00001')).toBe('IGSN');
            });

            it('detects CSIRO collection with igsn: tag: igsn:CSRWASC00001', () => {
                expect(detectIdentifierType('igsn:CSRWASC00001')).toBe('IGSN');
            });

            it('detects CSIRO collection DOI form: 10.58108/CSRWASC00001', () => {
                expect(detectIdentifierType('10.58108/CSRWASC00001')).toBe('IGSN');
            });

            it('detects CSIRO collection DOI URL: https://doi.org/10.58108/CSRWASC00001', () => {
                expect(detectIdentifierType('https://doi.org/10.58108/CSRWASC00001')).toBe('IGSN');
            });

            it('detects CSIRO collection URN: urn:igsn:CSRWASC00001', () => {
                expect(detectIdentifierType('urn:igsn:CSRWASC00001')).toBe('IGSN');
            });

            // 8. GFZ Potsdam
            it('detects GFZ bare code: GFZ000001ABC', () => {
                expect(detectIdentifierType('GFZ000001ABC')).toBe('IGSN');
            });

            it('detects GFZ with IGSN prefix: IGSN GFZ000001ABC', () => {
                expect(detectIdentifierType('IGSN GFZ000001ABC')).toBe('IGSN');
            });

            it('detects GFZ with igsn: tag: igsn:GFZ000001ABC', () => {
                expect(detectIdentifierType('igsn:GFZ000001ABC')).toBe('IGSN');
            });

            it('detects GFZ DOI form: 10.60510/GFZ000001ABC', () => {
                expect(detectIdentifierType('10.60510/GFZ000001ABC')).toBe('IGSN');
            });

            it('detects GFZ DOI URL: https://doi.org/10.60510/GFZ000001ABC', () => {
                expect(detectIdentifierType('https://doi.org/10.60510/GFZ000001ABC')).toBe('IGSN');
            });

            it('detects GFZ URN: urn:igsn:GFZ000001ABC', () => {
                expect(detectIdentifierType('urn:igsn:GFZ000001ABC')).toBe('IGSN');
            });

            // 9. MARUM Universität Bremen
            it('detects MARUM bare code: MBCR5034RC57001', () => {
                expect(detectIdentifierType('MBCR5034RC57001')).toBe('IGSN');
            });

            it('detects MARUM with IGSN prefix: IGSN MBCR5034RC57001', () => {
                expect(detectIdentifierType('IGSN MBCR5034RC57001')).toBe('IGSN');
            });

            it('detects MARUM with igsn: tag: igsn:MBCR5034RC57001', () => {
                expect(detectIdentifierType('igsn:MBCR5034RC57001')).toBe('IGSN');
            });

            it('detects MARUM DOI form: 10.58095/MBCR5034RC57001', () => {
                expect(detectIdentifierType('10.58095/MBCR5034RC57001')).toBe('IGSN');
            });

            it('detects MARUM DOI URL: https://doi.org/10.58095/MBCR5034RC57001', () => {
                expect(detectIdentifierType('https://doi.org/10.58095/MBCR5034RC57001')).toBe('IGSN');
            });

            it('detects MARUM URN: urn:igsn:MBCR5034RC57001', () => {
                expect(detectIdentifierType('urn:igsn:MBCR5034RC57001')).toBe('IGSN');
            });

            // 10. ARDC Australian Universities Service
            it('detects ARDC bare code: ARDC2024001XYZ', () => {
                expect(detectIdentifierType('ARDC2024001XYZ')).toBe('IGSN');
            });

            it('detects ARDC with IGSN prefix: IGSN ARDC2024001XYZ', () => {
                expect(detectIdentifierType('IGSN ARDC2024001XYZ')).toBe('IGSN');
            });

            it('detects ARDC with igsn: tag: igsn:ARDC2024001XYZ', () => {
                expect(detectIdentifierType('igsn:ARDC2024001XYZ')).toBe('IGSN');
            });

            it('detects ARDC DOI form: 10.60516/ARDC2024001XYZ', () => {
                expect(detectIdentifierType('10.60516/ARDC2024001XYZ')).toBe('IGSN');
            });

            it('detects ARDC DOI URL: https://doi.org/10.60516/ARDC2024001XYZ', () => {
                expect(detectIdentifierType('https://doi.org/10.60516/ARDC2024001XYZ')).toBe('IGSN');
            });

            it('detects ARDC URN: urn:igsn:ARDC2024001XYZ', () => {
                expect(detectIdentifierType('urn:igsn:ARDC2024001XYZ')).toBe('IGSN');
            });
        });

        describe('IGSN should NOT be detected for non-IGSN identifiers', () => {
            it('should not detect plain URLs as IGSN', () => {
                expect(detectIdentifierType('https://example.com/resource')).not.toBe('IGSN');
            });

            it('should not detect DOIs as IGSN', () => {
                expect(detectIdentifierType('10.5880/fidgeo.2025.072')).not.toBe('IGSN');
            });

            it('should not detect arXiv IDs as IGSN', () => {
                expect(detectIdentifierType('2501.13958')).not.toBe('IGSN');
            });

            it('should not detect bibcodes as IGSN', () => {
                expect(detectIdentifierType('2024AJ....167...20Z')).not.toBe('IGSN');
            });

            it('should not detect handles as IGSN', () => {
                expect(detectIdentifierType('11234/56789')).not.toBe('IGSN');
            });

            it('should not detect ARK as IGSN', () => {
                expect(detectIdentifierType('ark:12148/btv1b8449691v')).not.toBe('IGSN');
            });

            it('should not detect CSTR as IGSN', () => {
                expect(detectIdentifierType('CSTR:31253.11.sciencedb.j00001.00123')).not.toBe('IGSN');
            });

            it('should not detect EAN-13 as IGSN', () => {
                expect(detectIdentifierType('4006381333931')).not.toBe('IGSN');
            });

            it('should not detect EISSN as IGSN', () => {
                expect(detectIdentifierType('0378-5955')).not.toBe('IGSN');
            });

            it('should not detect random text as IGSN', () => {
                expect(detectIdentifierType('just-some-random-text')).not.toBe('IGSN');
            });
        });
    });

    describe('ISBN detection', () => {
        /**
         * ISBN (International Standard Book Number) identifies books uniquely.
         *
         * Format variations:
         * - ISBN-13: 13 digits starting with 978 or 979 (modern standard)
         * - ISBN-10: 10 digits, last may be X (legacy format)
         * - With hyphens: 978-0-306-40615-7
         * - URN format: urn:isbn:978-0-306-40615-7
         * - With prefix: ISBN-13: 978-..., ISBN 978-..., ISBN: 0-306-...
         * - OpenEdition URL: isbn.openedition.org/978-...
         */

        describe('ISBN-13 compact format (13 digits)', () => {
            it('detects scientific reference book: 9780306406157', () => {
                expect(detectIdentifierType('9780306406157')).toBe('ISBN');
            });

            it('detects The Last Unicorn: 9780451450523', () => {
                expect(detectIdentifierType('9780451450523')).toBe('ISBN');
            });

            it('detects Piper German publisher: 9783492241245', () => {
                expect(detectIdentifierType('9783492241245')).toBe('ISBN');
            });

            it('detects Oxford University Press: 9780198758159', () => {
                expect(detectIdentifierType('9780198758159')).toBe('ISBN');
            });

            it('detects Gallimard French publisher: 9782070748921', () => {
                expect(detectIdentifierType('9782070748921')).toBe('ISBN');
            });

            it('detects Open Edition eBook: 9782354102375', () => {
                expect(detectIdentifierType('9782354102375')).toBe('ISBN');
            });

            it('detects Kodansha Japanese publisher: 9784065278345', () => {
                expect(detectIdentifierType('9784065278345')).toBe('ISBN');
            });

            it('detects eBook ISBN: 9781481258327', () => {
                expect(detectIdentifierType('9781481258327')).toBe('ISBN');
            });

            it('detects Audiobook ISBN: 9780062468673', () => {
                expect(detectIdentifierType('9780062468673')).toBe('ISBN');
            });

            it('detects 979 prefix (modern): 9791234567890', () => {
                expect(detectIdentifierType('9791234567890')).toBe('ISBN');
            });
        });

        describe('ISBN-13 with hyphens', () => {
            it('detects with hyphens: 978-0-306-40615-7', () => {
                expect(detectIdentifierType('978-0-306-40615-7')).toBe('ISBN');
            });

            it('detects The Last Unicorn: 978-0-451-45052-3', () => {
                expect(detectIdentifierType('978-0-451-45052-3')).toBe('ISBN');
            });

            it('detects Piper: 978-3-492-24124-5', () => {
                expect(detectIdentifierType('978-3-492-24124-5')).toBe('ISBN');
            });

            it('detects Oxford: 978-0-19-875815-9', () => {
                expect(detectIdentifierType('978-0-19-875815-9')).toBe('ISBN');
            });

            it('detects Gallimard: 978-2-07-074892-1', () => {
                expect(detectIdentifierType('978-2-07-074892-1')).toBe('ISBN');
            });

            it('detects OpenEdition: 978-2-35410-237-5', () => {
                expect(detectIdentifierType('978-2-35410-237-5')).toBe('ISBN');
            });

            it('detects Kodansha: 978-4-06-527834-5', () => {
                expect(detectIdentifierType('978-4-06-527834-5')).toBe('ISBN');
            });

            it('detects eBook: 978-1-4812-5832-7', () => {
                expect(detectIdentifierType('978-1-4812-5832-7')).toBe('ISBN');
            });

            it('detects Audiobook: 978-0-06-246867-3', () => {
                expect(detectIdentifierType('978-0-06-246867-3')).toBe('ISBN');
            });

            it('detects 979 prefix with hyphens: 979-1-23-456789-0', () => {
                expect(detectIdentifierType('979-1-23-456789-0')).toBe('ISBN');
            });
        });

        describe('ISBN-10 compact format (legacy)', () => {
            it('detects ISBN-10: 0306406152', () => {
                expect(detectIdentifierType('0306406152')).toBe('ISBN');
            });

            it('detects The Last Unicorn ISBN-10: 0451450523', () => {
                expect(detectIdentifierType('0451450523')).toBe('ISBN');
            });

            it('detects ISBN-10 with X check digit: 080442957X', () => {
                expect(detectIdentifierType('080442957X')).toBe('ISBN');
            });

            it('detects ISBN-10 with lowercase x: 080442957x', () => {
                expect(detectIdentifierType('080442957x')).toBe('ISBN');
            });
        });

        describe('ISBN-10 with hyphens (legacy)', () => {
            it('detects ISBN-10 with hyphens: 0-306-40615-2', () => {
                expect(detectIdentifierType('0-306-40615-2')).toBe('ISBN');
            });

            it('detects The Last Unicorn ISBN-10: 0-451-45052-3', () => {
                expect(detectIdentifierType('0-451-45052-3')).toBe('ISBN');
            });

            it('detects ISBN-10 with X: 0-8044-2957-X', () => {
                expect(detectIdentifierType('0-8044-2957-X')).toBe('ISBN');
            });
        });

        describe('ISBN with URN format', () => {
            it('detects URN ISBN-13: urn:isbn:978-0-306-40615-7', () => {
                expect(detectIdentifierType('urn:isbn:978-0-306-40615-7')).toBe('ISBN');
            });

            it('detects URN ISBN-10: urn:isbn:0-306-40615-2', () => {
                expect(detectIdentifierType('urn:isbn:0-306-40615-2')).toBe('ISBN');
            });

            it('detects URN The Last Unicorn: urn:isbn:978-0-451-45052-3', () => {
                expect(detectIdentifierType('urn:isbn:978-0-451-45052-3')).toBe('ISBN');
            });

            it('detects URN Piper: urn:isbn:978-3-492-24124-5', () => {
                expect(detectIdentifierType('urn:isbn:978-3-492-24124-5')).toBe('ISBN');
            });

            it('detects URN Oxford: urn:isbn:978-0-19-875815-9', () => {
                expect(detectIdentifierType('urn:isbn:978-0-19-875815-9')).toBe('ISBN');
            });

            it('detects URN Gallimard: urn:isbn:978-2-07-074892-1', () => {
                expect(detectIdentifierType('urn:isbn:978-2-07-074892-1')).toBe('ISBN');
            });

            it('detects URN OpenEdition: urn:isbn:978-2-35410-237-5', () => {
                expect(detectIdentifierType('urn:isbn:978-2-35410-237-5')).toBe('ISBN');
            });

            it('detects URN Kodansha: urn:isbn:978-4-06-527834-5', () => {
                expect(detectIdentifierType('urn:isbn:978-4-06-527834-5')).toBe('ISBN');
            });

            it('detects URN eBook: urn:isbn:978-1-4812-5832-7', () => {
                expect(detectIdentifierType('urn:isbn:978-1-4812-5832-7')).toBe('ISBN');
            });

            it('detects URN Audiobook: urn:isbn:978-0-06-246867-3', () => {
                expect(detectIdentifierType('urn:isbn:978-0-06-246867-3')).toBe('ISBN');
            });

            it('detects URN 979 prefix: urn:isbn:979-1-23-456789-0', () => {
                expect(detectIdentifierType('urn:isbn:979-1-23-456789-0')).toBe('ISBN');
            });
        });

        describe('ISBN with explicit prefix', () => {
            it('detects ISBN-13 prefix: ISBN-13: 978-0-306-40615-7', () => {
                expect(detectIdentifierType('ISBN-13: 978-0-306-40615-7')).toBe('ISBN');
            });

            it('detects ISBN prefix: ISBN 978-0-451-45052-3', () => {
                expect(detectIdentifierType('ISBN 978-0-451-45052-3')).toBe('ISBN');
            });

            it('detects ISBN prefix without space: ISBN:978-0-19-875815-9', () => {
                expect(detectIdentifierType('ISBN:978-0-19-875815-9')).toBe('ISBN');
            });

            it('detects ISBN-10 prefix: ISBN-10: 0-306-40615-2', () => {
                expect(detectIdentifierType('ISBN-10: 0-306-40615-2')).toBe('ISBN');
            });

            it('detects ISBN (eBook) format: ISBN (eBook): 978-1-4812-5832-7', () => {
                expect(detectIdentifierType('ISBN (eBook): 978-1-4812-5832-7')).toBe('ISBN');
            });

            it('detects ISBN (Audio) format: ISBN (Audio): 978-0-06-246867-3', () => {
                expect(detectIdentifierType('ISBN (Audio): 978-0-06-246867-3')).toBe('ISBN');
            });
        });

        describe('ISBN with OpenEdition URL', () => {
            it('detects OpenEdition URL: https://isbn.openedition.org/978-3-492-24124-5', () => {
                expect(detectIdentifierType('https://isbn.openedition.org/978-3-492-24124-5')).toBe('ISBN');
            });

            it('detects OpenEdition Gallimard: https://isbn.openedition.org/978-2-07-074892-1', () => {
                expect(detectIdentifierType('https://isbn.openedition.org/978-2-07-074892-1')).toBe('ISBN');
            });

            it('detects OpenEdition eBook: https://isbn.openedition.org/978-2-35410-237-5', () => {
                expect(detectIdentifierType('https://isbn.openedition.org/978-2-35410-237-5')).toBe('ISBN');
            });

            it('detects http OpenEdition URL: http://isbn.openedition.org/9780306406157', () => {
                expect(detectIdentifierType('http://isbn.openedition.org/9780306406157')).toBe('ISBN');
            });
        });

        describe('ISBN edge cases', () => {
            it('handles leading/trailing whitespace', () => {
                expect(detectIdentifierType('  9780306406157  ')).toBe('ISBN');
            });

            it('handles ISBN-13 with spaces instead of hyphens', () => {
                expect(detectIdentifierType('978 0 306 40615 7')).toBe('ISBN');
            });

            it('handles ISBN-10 with spaces', () => {
                expect(detectIdentifierType('0 306 40615 2')).toBe('ISBN');
            });

            it('handles URN with whitespace', () => {
                expect(detectIdentifierType('  urn:isbn:978-0-306-40615-7  ')).toBe('ISBN');
            });
        });

        describe('real-world ISBN examples from user requirements', () => {
            // 1. Scientific Reference Book (English)
            it('detects scientific book compact: 9780306406157', () => {
                expect(detectIdentifierType('9780306406157')).toBe('ISBN');
            });

            it('detects scientific book with hyphens: 978-0-306-40615-7', () => {
                expect(detectIdentifierType('978-0-306-40615-7')).toBe('ISBN');
            });

            it('detects scientific book ISBN-10: 0306406152', () => {
                expect(detectIdentifierType('0306406152')).toBe('ISBN');
            });

            it('detects scientific book ISBN-10 hyphens: 0-306-40615-2', () => {
                expect(detectIdentifierType('0-306-40615-2')).toBe('ISBN');
            });

            it('detects scientific book URN: urn:isbn:978-0-306-40615-7', () => {
                expect(detectIdentifierType('urn:isbn:978-0-306-40615-7')).toBe('ISBN');
            });

            it('detects scientific book URN ISBN-10: urn:isbn:0-306-40615-2', () => {
                expect(detectIdentifierType('urn:isbn:0-306-40615-2')).toBe('ISBN');
            });

            it('detects scientific book with prefix: ISBN-13: 978-0-306-40615-7', () => {
                expect(detectIdentifierType('ISBN-13: 978-0-306-40615-7')).toBe('ISBN');
            });

            // 2. The Last Unicorn (English Classic)
            it('detects Last Unicorn compact: 9780451450523', () => {
                expect(detectIdentifierType('9780451450523')).toBe('ISBN');
            });

            it('detects Last Unicorn with hyphens: 978-0-451-45052-3', () => {
                expect(detectIdentifierType('978-0-451-45052-3')).toBe('ISBN');
            });

            it('detects Last Unicorn ISBN-10: 0451450523', () => {
                expect(detectIdentifierType('0451450523')).toBe('ISBN');
            });

            it('detects Last Unicorn ISBN-10 hyphens: 0-451-45052-3', () => {
                expect(detectIdentifierType('0-451-45052-3')).toBe('ISBN');
            });

            it('detects Last Unicorn URN: urn:isbn:978-0-451-45052-3', () => {
                expect(detectIdentifierType('urn:isbn:978-0-451-45052-3')).toBe('ISBN');
            });

            it('detects Last Unicorn with tag: ISBN 978-0-451-45052-3', () => {
                expect(detectIdentifierType('ISBN 978-0-451-45052-3')).toBe('ISBN');
            });

            // 3. German Publisher (Piper)
            it('detects Piper compact: 9783492241245', () => {
                expect(detectIdentifierType('9783492241245')).toBe('ISBN');
            });

            it('detects Piper with hyphens: 978-3-492-24124-5', () => {
                expect(detectIdentifierType('978-3-492-24124-5')).toBe('ISBN');
            });

            it('detects Piper URN: urn:isbn:978-3-492-24124-5', () => {
                expect(detectIdentifierType('urn:isbn:978-3-492-24124-5')).toBe('ISBN');
            });

            it('detects Piper with prefix: ISBN-13: 978-3-492-24124-5', () => {
                expect(detectIdentifierType('ISBN-13: 978-3-492-24124-5')).toBe('ISBN');
            });

            it('detects Piper OpenEdition: https://isbn.openedition.org/978-3-492-24124-5', () => {
                expect(detectIdentifierType('https://isbn.openedition.org/978-3-492-24124-5')).toBe('ISBN');
            });

            // 4. Oxford University Press (UK)
            it('detects Oxford compact: 9780198758159', () => {
                expect(detectIdentifierType('9780198758159')).toBe('ISBN');
            });

            it('detects Oxford with hyphens: 978-0-19-875815-9', () => {
                expect(detectIdentifierType('978-0-19-875815-9')).toBe('ISBN');
            });

            it('detects Oxford URN: urn:isbn:978-0-19-875815-9', () => {
                expect(detectIdentifierType('urn:isbn:978-0-19-875815-9')).toBe('ISBN');
            });

            it('detects Oxford with tag: ISBN 978-0-19-875815-9', () => {
                expect(detectIdentifierType('ISBN 978-0-19-875815-9')).toBe('ISBN');
            });

            // 5. French Publisher (Gallimard)
            it('detects Gallimard compact: 9782070748921', () => {
                expect(detectIdentifierType('9782070748921')).toBe('ISBN');
            });

            it('detects Gallimard with hyphens: 978-2-07-074892-1', () => {
                expect(detectIdentifierType('978-2-07-074892-1')).toBe('ISBN');
            });

            it('detects Gallimard URN: urn:isbn:978-2-07-074892-1', () => {
                expect(detectIdentifierType('urn:isbn:978-2-07-074892-1')).toBe('ISBN');
            });

            it('detects Gallimard OpenEdition: https://isbn.openedition.org/978-2-07-074892-1', () => {
                expect(detectIdentifierType('https://isbn.openedition.org/978-2-07-074892-1')).toBe('ISBN');
            });

            // 6. Open Access eBook (OpenEdition)
            it('detects OpenEdition eBook compact: 9782354102375', () => {
                expect(detectIdentifierType('9782354102375')).toBe('ISBN');
            });

            it('detects OpenEdition eBook with hyphens: 978-2-35410-237-5', () => {
                expect(detectIdentifierType('978-2-35410-237-5')).toBe('ISBN');
            });

            it('detects OpenEdition eBook URN: urn:isbn:978-2-35410-237-5', () => {
                expect(detectIdentifierType('urn:isbn:978-2-35410-237-5')).toBe('ISBN');
            });

            it('detects OpenEdition resolver: https://isbn.openedition.org/978-2-35410-237-5', () => {
                expect(detectIdentifierType('https://isbn.openedition.org/978-2-35410-237-5')).toBe('ISBN');
            });

            // 7. Japanese Publisher (Kodansha)
            it('detects Kodansha compact: 9784065278345', () => {
                expect(detectIdentifierType('9784065278345')).toBe('ISBN');
            });

            it('detects Kodansha with hyphens: 978-4-06-527834-5', () => {
                expect(detectIdentifierType('978-4-06-527834-5')).toBe('ISBN');
            });

            it('detects Kodansha URN: urn:isbn:978-4-06-527834-5', () => {
                expect(detectIdentifierType('urn:isbn:978-4-06-527834-5')).toBe('ISBN');
            });

            it('detects Kodansha with prefix: ISBN-13: 978-4-06-527834-5', () => {
                expect(detectIdentifierType('ISBN-13: 978-4-06-527834-5')).toBe('ISBN');
            });

            // 8. E-Book (Electronic ISBN)
            it('detects eBook compact: 9781481258327', () => {
                expect(detectIdentifierType('9781481258327')).toBe('ISBN');
            });

            it('detects eBook with hyphens: 978-1-4812-5832-7', () => {
                expect(detectIdentifierType('978-1-4812-5832-7')).toBe('ISBN');
            });

            it('detects eBook URN: urn:isbn:978-1-4812-5832-7', () => {
                expect(detectIdentifierType('urn:isbn:978-1-4812-5832-7')).toBe('ISBN');
            });

            it('detects eBook format tag: ISBN (eBook): 978-1-4812-5832-7', () => {
                expect(detectIdentifierType('ISBN (eBook): 978-1-4812-5832-7')).toBe('ISBN');
            });

            // 9. Audiobook (Audio ISBN)
            it('detects Audiobook compact: 9780062468673', () => {
                expect(detectIdentifierType('9780062468673')).toBe('ISBN');
            });

            it('detects Audiobook with hyphens: 978-0-06-246867-3', () => {
                expect(detectIdentifierType('978-0-06-246867-3')).toBe('ISBN');
            });

            it('detects Audiobook URN: urn:isbn:978-0-06-246867-3', () => {
                expect(detectIdentifierType('urn:isbn:978-0-06-246867-3')).toBe('ISBN');
            });

            it('detects Audiobook format tag: ISBN (Audio): 978-0-06-246867-3', () => {
                expect(detectIdentifierType('ISBN (Audio): 978-0-06-246867-3')).toBe('ISBN');
            });

            // 10. New 979 Prefix (Modern Standard)
            it('detects 979 prefix compact: 9791234567890', () => {
                expect(detectIdentifierType('9791234567890')).toBe('ISBN');
            });

            it('detects 979 prefix with hyphens: 979-1-23-456789-0', () => {
                expect(detectIdentifierType('979-1-23-456789-0')).toBe('ISBN');
            });

            it('detects 979 prefix URN: urn:isbn:979-1-23-456789-0', () => {
                expect(detectIdentifierType('urn:isbn:979-1-23-456789-0')).toBe('ISBN');
            });

            it('detects 979 prefix with tag: ISBN-13: 979-1-23-456789-0', () => {
                expect(detectIdentifierType('ISBN-13: 979-1-23-456789-0')).toBe('ISBN');
            });
        });

        describe('ISBN should NOT be detected for non-ISBN identifiers', () => {
            it('should not detect plain URLs as ISBN', () => {
                expect(detectIdentifierType('https://example.com/resource')).not.toBe('ISBN');
            });

            it('should not detect DOIs as ISBN', () => {
                expect(detectIdentifierType('10.5880/fidgeo.2025.072')).not.toBe('ISBN');
            });

            it('should not detect arXiv IDs as ISBN', () => {
                expect(detectIdentifierType('2501.13958')).not.toBe('ISBN');
            });

            it('should not detect bibcodes as ISBN', () => {
                expect(detectIdentifierType('2024AJ....167...20Z')).not.toBe('ISBN');
            });

            it('should not detect handles as ISBN', () => {
                expect(detectIdentifierType('11234/56789')).not.toBe('ISBN');
            });

            it('should not detect ARK as ISBN', () => {
                expect(detectIdentifierType('ark:12148/btv1b8449691v')).not.toBe('ISBN');
            });

            it('should not detect CSTR as ISBN', () => {
                expect(detectIdentifierType('CSTR:31253.11.sciencedb.j00001.00123')).not.toBe('ISBN');
            });

            it('should not detect non-978/979 EAN-13 as ISBN', () => {
                expect(detectIdentifierType('4006381333931')).not.toBe('ISBN');
            });

            it('should not detect EISSN as ISBN', () => {
                expect(detectIdentifierType('0378-5955')).not.toBe('ISBN');
            });

            it('should not detect IGSN as ISBN', () => {
                expect(detectIdentifierType('igsn:AU1101')).not.toBe('ISBN');
            });

            it('should not detect 9-digit number as ISBN', () => {
                expect(detectIdentifierType('123456789')).not.toBe('ISBN');
            });

            it('should not detect 11-digit number as ISBN', () => {
                expect(detectIdentifierType('12345678901')).not.toBe('ISBN');
            });
        });
    });

    describe('ISTC detection', () => {
        /**
         * ISTC (International Standard Text Code) uniquely identifies textual works.
         *
         * Format: 16 characters in groups XXX-YYYY-ZZZZ-ZZZZ-C
         * - XXX = Registration Agency (3 extended hex: 0-9, A-J)
         * - YYYY = Year (4 digits)
         * - ZZZZZZZZ = Work element (8 extended hex, displayed as two 4-char groups)
         * - C = Check digit (1 extended hex)
         *
         * Extended hexadecimal uses 0-9 and A-J (not A-F like standard hex).
         * Can be displayed with or without hyphens.
         *
         * Common patterns:
         * - With hyphens: 0A9-2010-31F4-CB2C-B
         * - Compact: 0A9201031F4CB2CB
         * - URN format: urn:istc:0A9201031F4CB2CB
         * - With prefix: ISTC 0A9-2010-31F4-CB2C-B
         */

        describe('ISTC with hyphens (XXX-YYYY-ZZZZ-ZZZZ-C)', () => {
            it('detects ISO standard example: 0A9-2010-31F4-CB2C-B', () => {
                expect(detectIdentifierType('0A9-2010-31F4-CB2C-B')).toBe('ISTC');
            });

            it('detects Hamlet example: 03A-2009-000C-299F-D', () => {
                expect(detectIdentifierType('03A-2009-000C-299F-D')).toBe('ISTC');
            });

            it('detects Pride and Prejudice: 0B7-1998-F5A3-1D8E-2', () => {
                expect(detectIdentifierType('0B7-1998-F5A3-1D8E-2')).toBe('ISTC');
            });

            it('detects modern publication: 1C3-2020-AA5D-47BC-F', () => {
                expect(detectIdentifierType('1C3-2020-AA5D-47BC-F')).toBe('ISTC');
            });

            it('detects Bowker registration: 2D5-2015-BB7E-C9A1-3', () => {
                expect(detectIdentifierType('2D5-2015-BB7E-C9A1-3')).toBe('ISTC');
            });

            it('detects Nielsen registration: 0E4-2018-CD3F-E7B2-A', () => {
                expect(detectIdentifierType('0E4-2018-CD3F-E7B2-A')).toBe('ISTC');
            });

            it('detects abridged edition: 3F6-2016-DE9G-F8C4-5', () => {
                expect(detectIdentifierType('3F6-2016-DE9G-F8C4-5')).toBe('ISTC');
            });

            it('detects academic text: 4A7-2021-EF5H-A9D3-6', () => {
                expect(detectIdentifierType('4A7-2021-EF5H-A9D3-6')).toBe('ISTC');
            });

            it('detects translated work: 5B8-2019-FG6I-B0E2-7', () => {
                expect(detectIdentifierType('5B8-2019-FG6I-B0E2-7')).toBe('ISTC');
            });

            it('detects 2024 publication: 6C9-2024-GH7J-C1F3-8', () => {
                expect(detectIdentifierType('6C9-2024-GH7J-C1F3-8')).toBe('ISTC');
            });
        });

        describe('ISTC compact format (16 characters)', () => {
            it('detects ISO standard compact: 0A9201031F4CB2CB', () => {
                expect(detectIdentifierType('0A9201031F4CB2CB')).toBe('ISTC');
            });

            it('detects Hamlet compact: 03A2009000C299FD', () => {
                expect(detectIdentifierType('03A2009000C299FD')).toBe('ISTC');
            });

            it('detects Pride and Prejudice compact: 0B71998F5A31D8E2', () => {
                expect(detectIdentifierType('0B71998F5A31D8E2')).toBe('ISTC');
            });

            it('detects modern publication compact: 1C32020AA5D47BCF', () => {
                expect(detectIdentifierType('1C32020AA5D47BCF')).toBe('ISTC');
            });

            it('detects Bowker compact: 2D52015BB7EC9A13', () => {
                expect(detectIdentifierType('2D52015BB7EC9A13')).toBe('ISTC');
            });

            it('detects Nielsen compact: 0E42018CD3FE7B2A', () => {
                expect(detectIdentifierType('0E42018CD3FE7B2A')).toBe('ISTC');
            });

            it('detects abridged compact: 3F62016DE9GF8C45', () => {
                expect(detectIdentifierType('3F62016DE9GF8C45')).toBe('ISTC');
            });

            it('detects academic compact: 4A72021EF5HA9D36', () => {
                expect(detectIdentifierType('4A72021EF5HA9D36')).toBe('ISTC');
            });

            it('detects translated compact: 5B82019FG6IB0E27', () => {
                expect(detectIdentifierType('5B82019FG6IB0E27')).toBe('ISTC');
            });

            it('detects 2024 compact: 6C92024GH7JC1F38', () => {
                expect(detectIdentifierType('6C92024GH7JC1F38')).toBe('ISTC');
            });
        });

        describe('ISTC with ISTC prefix', () => {
            it('detects ISTC prefix: ISTC 0A9-2010-31F4-CB2C-B', () => {
                expect(detectIdentifierType('ISTC 0A9-2010-31F4-CB2C-B')).toBe('ISTC');
            });

            it('detects ISTC prefix: ISTC 03A-2009-000C-299F-D', () => {
                expect(detectIdentifierType('ISTC 03A-2009-000C-299F-D')).toBe('ISTC');
            });

            it('detects ISTC prefix: ISTC 0B7-1998-F5A3-1D8E-2', () => {
                expect(detectIdentifierType('ISTC 0B7-1998-F5A3-1D8E-2')).toBe('ISTC');
            });

            it('detects ISTC prefix: ISTC 1C3-2020-AA5D-47BC-F', () => {
                expect(detectIdentifierType('ISTC 1C3-2020-AA5D-47BC-F')).toBe('ISTC');
            });

            it('detects ISTC prefix: ISTC 2D5-2015-BB7E-C9A1-3', () => {
                expect(detectIdentifierType('ISTC 2D5-2015-BB7E-C9A1-3')).toBe('ISTC');
            });

            it('detects ISTC prefix: ISTC 0E4-2018-CD3F-E7B2-A', () => {
                expect(detectIdentifierType('ISTC 0E4-2018-CD3F-E7B2-A')).toBe('ISTC');
            });

            it('detects ISTC prefix: ISTC 3F6-2016-DE9G-F8C4-5', () => {
                expect(detectIdentifierType('ISTC 3F6-2016-DE9G-F8C4-5')).toBe('ISTC');
            });

            it('detects ISTC prefix: ISTC 4A7-2021-EF5H-A9D3-6', () => {
                expect(detectIdentifierType('ISTC 4A7-2021-EF5H-A9D3-6')).toBe('ISTC');
            });

            it('detects ISTC prefix: ISTC 5B8-2019-FG6I-B0E2-7', () => {
                expect(detectIdentifierType('ISTC 5B8-2019-FG6I-B0E2-7')).toBe('ISTC');
            });

            it('detects ISTC prefix: ISTC 6C9-2024-GH7J-C1F3-8', () => {
                expect(detectIdentifierType('ISTC 6C9-2024-GH7J-C1F3-8')).toBe('ISTC');
            });
        });

        describe('ISTC with agency annotations', () => {
            it('detects ISTC with Bowker reference: ISTC (Bowker): 2D5-2015-BB7E-C9A1-3', () => {
                expect(detectIdentifierType('ISTC (Bowker): 2D5-2015-BB7E-C9A1-3')).toBe('ISTC');
            });

            it('detects ISTC with Nielsen reference: ISTC (Nielsen): 0E4-2018-CD3F-E7B2-A', () => {
                expect(detectIdentifierType('ISTC (Nielsen): 0E4-2018-CD3F-E7B2-A')).toBe('ISTC');
            });
        });

        describe('ISTC with URN format', () => {
            it('detects URN: urn:istc:0A9201031F4CB2CB', () => {
                expect(detectIdentifierType('urn:istc:0A9201031F4CB2CB')).toBe('ISTC');
            });

            it('detects URN: urn:istc:03A2009000C299FD', () => {
                expect(detectIdentifierType('urn:istc:03A2009000C299FD')).toBe('ISTC');
            });

            it('detects URN: urn:istc:0B71998F5A31D8E2', () => {
                expect(detectIdentifierType('urn:istc:0B71998F5A31D8E2')).toBe('ISTC');
            });

            it('detects URN: urn:istc:1C32020AA5D47BCF', () => {
                expect(detectIdentifierType('urn:istc:1C32020AA5D47BCF')).toBe('ISTC');
            });

            it('detects URN: urn:istc:2D52015BB7EC9A13', () => {
                expect(detectIdentifierType('urn:istc:2D52015BB7EC9A13')).toBe('ISTC');
            });

            it('detects URN: urn:istc:0E42018CD3FE7B2A', () => {
                expect(detectIdentifierType('urn:istc:0E42018CD3FE7B2A')).toBe('ISTC');
            });

            it('detects URN: urn:istc:3F62016DE9GF8C45', () => {
                expect(detectIdentifierType('urn:istc:3F62016DE9GF8C45')).toBe('ISTC');
            });

            it('detects URN: urn:istc:4A72021EF5HA9D36', () => {
                expect(detectIdentifierType('urn:istc:4A72021EF5HA9D36')).toBe('ISTC');
            });

            it('detects URN: urn:istc:5B82019FG6IB0E27', () => {
                expect(detectIdentifierType('urn:istc:5B82019FG6IB0E27')).toBe('ISTC');
            });

            it('detects URN: urn:istc:6C92024GH7JC1F38', () => {
                expect(detectIdentifierType('urn:istc:6C92024GH7JC1F38')).toBe('ISTC');
            });

            it('detects URN with hyphens: urn:istc:0A92010-31F4CB2CB', () => {
                expect(detectIdentifierType('urn:istc:0A92010-31F4CB2CB')).toBe('ISTC');
            });
        });

        describe('ISTC edge cases', () => {
            it('handles lowercase: 0a9-2010-31f4-cb2c-b', () => {
                expect(detectIdentifierType('0a9-2010-31f4-cb2c-b')).toBe('ISTC');
            });

            it('handles mixed case: 0A9-2010-31f4-CB2c-B', () => {
                expect(detectIdentifierType('0A9-2010-31f4-CB2c-B')).toBe('ISTC');
            });

            it('handles leading/trailing whitespace', () => {
                expect(detectIdentifierType('  0A9-2010-31F4-CB2C-B  ')).toBe('ISTC');
            });

            it('handles URN with whitespace', () => {
                expect(detectIdentifierType('  urn:istc:0A9201031F4CB2CB  ')).toBe('ISTC');
            });
        });

        describe('real-world ISTC examples from user requirements', () => {
            // 1. ISO 21047 Standard Example
            it('detects ISO example with hyphens: 0A9-2010-31F4-CB2C-B', () => {
                expect(detectIdentifierType('0A9-2010-31F4-CB2C-B')).toBe('ISTC');
            });

            it('detects ISO example compact: 0A9201031F4CB2CB', () => {
                expect(detectIdentifierType('0A9201031F4CB2CB')).toBe('ISTC');
            });

            it('detects ISO example with prefix: ISTC 0A9-2010-31F4-CB2C-B', () => {
                expect(detectIdentifierType('ISTC 0A9-2010-31F4-CB2C-B')).toBe('ISTC');
            });

            it('detects ISO example URN: urn:istc:0A92010-31F4CB2CB', () => {
                expect(detectIdentifierType('urn:istc:0A92010-31F4CB2CB')).toBe('ISTC');
            });

            // 2. Hamlet (Shakespeare)
            it('detects Hamlet with hyphens: 03A-2009-000C-299F-D', () => {
                expect(detectIdentifierType('03A-2009-000C-299F-D')).toBe('ISTC');
            });

            it('detects Hamlet compact: 03A2009000C299FD', () => {
                expect(detectIdentifierType('03A2009000C299FD')).toBe('ISTC');
            });

            it('detects Hamlet with prefix: ISTC 03A-2009-000C-299F-D', () => {
                expect(detectIdentifierType('ISTC 03A-2009-000C-299F-D')).toBe('ISTC');
            });

            it('detects Hamlet URN: urn:istc:03A2009000C299FD', () => {
                expect(detectIdentifierType('urn:istc:03A2009000C299FD')).toBe('ISTC');
            });

            // 3. Pride and Prejudice (Jane Austen)
            it('detects Pride and Prejudice with hyphens: 0B7-1998-F5A3-1D8E-2', () => {
                expect(detectIdentifierType('0B7-1998-F5A3-1D8E-2')).toBe('ISTC');
            });

            it('detects Pride and Prejudice compact: 0B71998F5A31D8E2', () => {
                expect(detectIdentifierType('0B71998F5A31D8E2')).toBe('ISTC');
            });

            it('detects Pride and Prejudice with prefix: ISTC 0B7-1998-F5A3-1D8E-2', () => {
                expect(detectIdentifierType('ISTC 0B7-1998-F5A3-1D8E-2')).toBe('ISTC');
            });

            it('detects Pride and Prejudice URN: urn:istc:0B71998F5A31D8E2', () => {
                expect(detectIdentifierType('urn:istc:0B71998F5A31D8E2')).toBe('ISTC');
            });

            // 4. Modern Literary Publication (2020)
            it('detects 2020 publication with hyphens: 1C3-2020-AA5D-47BC-F', () => {
                expect(detectIdentifierType('1C3-2020-AA5D-47BC-F')).toBe('ISTC');
            });

            it('detects 2020 publication compact: 1C32020AA5D47BCF', () => {
                expect(detectIdentifierType('1C32020AA5D47BCF')).toBe('ISTC');
            });

            it('detects 2020 publication with prefix: ISTC 1C3-2020-AA5D-47BC-F', () => {
                expect(detectIdentifierType('ISTC 1C3-2020-AA5D-47BC-F')).toBe('ISTC');
            });

            it('detects 2020 publication URN: urn:istc:1C32020AA5D47BCF', () => {
                expect(detectIdentifierType('urn:istc:1C32020AA5D47BCF')).toBe('ISTC');
            });

            // 5. Bowker Registration (USA)
            it('detects Bowker with hyphens: 2D5-2015-BB7E-C9A1-3', () => {
                expect(detectIdentifierType('2D5-2015-BB7E-C9A1-3')).toBe('ISTC');
            });

            it('detects Bowker compact: 2D52015BB7EC9A13', () => {
                expect(detectIdentifierType('2D52015BB7EC9A13')).toBe('ISTC');
            });

            it('detects Bowker with agency ref: ISTC (Bowker): 2D5-2015-BB7E-C9A1-3', () => {
                expect(detectIdentifierType('ISTC (Bowker): 2D5-2015-BB7E-C9A1-3')).toBe('ISTC');
            });

            it('detects Bowker URN: urn:istc:2D52015BB7EC9A13', () => {
                expect(detectIdentifierType('urn:istc:2D52015BB7EC9A13')).toBe('ISTC');
            });

            // 6. Nielsen Registration (UK)
            it('detects Nielsen with hyphens: 0E4-2018-CD3F-E7B2-A', () => {
                expect(detectIdentifierType('0E4-2018-CD3F-E7B2-A')).toBe('ISTC');
            });

            it('detects Nielsen compact: 0E42018CD3FE7B2A', () => {
                expect(detectIdentifierType('0E42018CD3FE7B2A')).toBe('ISTC');
            });

            it('detects Nielsen with agency ref: ISTC (Nielsen): 0E4-2018-CD3F-E7B2-A', () => {
                expect(detectIdentifierType('ISTC (Nielsen): 0E4-2018-CD3F-E7B2-A')).toBe('ISTC');
            });

            it('detects Nielsen URN: urn:istc:0E42018CD3FE7B2A', () => {
                expect(detectIdentifierType('urn:istc:0E42018CD3FE7B2A')).toBe('ISTC');
            });

            // 7. Abridged/Adapted Edition
            it('detects abridged with hyphens: 3F6-2016-DE9G-F8C4-5', () => {
                expect(detectIdentifierType('3F6-2016-DE9G-F8C4-5')).toBe('ISTC');
            });

            it('detects abridged compact: 3F62016DE9GF8C45', () => {
                expect(detectIdentifierType('3F62016DE9GF8C45')).toBe('ISTC');
            });

            it('detects abridged with prefix: ISTC 3F6-2016-DE9G-F8C4-5', () => {
                expect(detectIdentifierType('ISTC 3F6-2016-DE9G-F8C4-5')).toBe('ISTC');
            });

            it('detects abridged URN: urn:istc:3F62016DE9GF8C45', () => {
                expect(detectIdentifierType('urn:istc:3F62016DE9GF8C45')).toBe('ISTC');
            });

            // 8. Academic/Scientific Text
            it('detects academic with hyphens: 4A7-2021-EF5H-A9D3-6', () => {
                expect(detectIdentifierType('4A7-2021-EF5H-A9D3-6')).toBe('ISTC');
            });

            it('detects academic compact: 4A72021EF5HA9D36', () => {
                expect(detectIdentifierType('4A72021EF5HA9D36')).toBe('ISTC');
            });

            it('detects academic with prefix: ISTC 4A7-2021-EF5H-A9D3-6', () => {
                expect(detectIdentifierType('ISTC 4A7-2021-EF5H-A9D3-6')).toBe('ISTC');
            });

            it('detects academic URN: urn:istc:4A72021EF5HA9D36', () => {
                expect(detectIdentifierType('urn:istc:4A72021EF5HA9D36')).toBe('ISTC');
            });

            // 9. Translated Work
            it('detects translated with hyphens: 5B8-2019-FG6I-B0E2-7', () => {
                expect(detectIdentifierType('5B8-2019-FG6I-B0E2-7')).toBe('ISTC');
            });

            it('detects translated compact: 5B82019FG6IB0E27', () => {
                expect(detectIdentifierType('5B82019FG6IB0E27')).toBe('ISTC');
            });

            it('detects translated with prefix: ISTC 5B8-2019-FG6I-B0E2-7', () => {
                expect(detectIdentifierType('ISTC 5B8-2019-FG6I-B0E2-7')).toBe('ISTC');
            });

            it('detects translated URN: urn:istc:5B82019FG6IB0E27', () => {
                expect(detectIdentifierType('urn:istc:5B82019FG6IB0E27')).toBe('ISTC');
            });

            // 10. Current Publication (2024)
            it('detects 2024 publication with hyphens: 6C9-2024-GH7J-C1F3-8', () => {
                expect(detectIdentifierType('6C9-2024-GH7J-C1F3-8')).toBe('ISTC');
            });

            it('detects 2024 publication compact: 6C92024GH7JC1F38', () => {
                expect(detectIdentifierType('6C92024GH7JC1F38')).toBe('ISTC');
            });

            it('detects 2024 publication with prefix: ISTC 6C9-2024-GH7J-C1F3-8', () => {
                expect(detectIdentifierType('ISTC 6C9-2024-GH7J-C1F3-8')).toBe('ISTC');
            });

            it('detects 2024 publication URN: urn:istc:6C92024GH7JC1F38', () => {
                expect(detectIdentifierType('urn:istc:6C92024GH7JC1F38')).toBe('ISTC');
            });
        });

        describe('ISTC should NOT be detected for non-ISTC identifiers', () => {
            it('should not detect plain URLs as ISTC', () => {
                expect(detectIdentifierType('https://example.com/resource')).not.toBe('ISTC');
            });

            it('should not detect DOIs as ISTC', () => {
                expect(detectIdentifierType('10.5880/fidgeo.2025.072')).not.toBe('ISTC');
            });

            it('should not detect arXiv IDs as ISTC', () => {
                expect(detectIdentifierType('2501.13958')).not.toBe('ISTC');
            });

            it('should not detect bibcodes as ISTC', () => {
                expect(detectIdentifierType('2024AJ....167...20Z')).not.toBe('ISTC');
            });

            it('should not detect handles as ISTC', () => {
                expect(detectIdentifierType('11234/56789')).not.toBe('ISTC');
            });

            it('should not detect ARK as ISTC', () => {
                expect(detectIdentifierType('ark:12148/btv1b8449691v')).not.toBe('ISTC');
            });

            it('should not detect CSTR as ISTC', () => {
                expect(detectIdentifierType('CSTR:31253.11.sciencedb.j00001.00123')).not.toBe('ISTC');
            });

            it('should not detect EAN-13 as ISTC', () => {
                expect(detectIdentifierType('4006381333931')).not.toBe('ISTC');
            });

            it('should not detect ISBN as ISTC', () => {
                expect(detectIdentifierType('978-0-306-40615-7')).not.toBe('ISTC');
            });

            it('should not detect EISSN as ISTC', () => {
                expect(detectIdentifierType('0378-5955')).not.toBe('ISTC');
            });

            it('should not detect IGSN as ISTC', () => {
                expect(detectIdentifierType('igsn:AU1101')).not.toBe('ISTC');
            });

            it('should not detect 15-character hex string as ISTC', () => {
                expect(detectIdentifierType('0A9201031F4CB2C')).not.toBe('ISTC');
            });

            it('should not detect 17-character hex string as ISTC', () => {
                expect(detectIdentifierType('0A9201031F4CB2CBA')).not.toBe('ISTC');
            });
        });
    });

    describe('LISSN detection', () => {
        /**
         * LISSN (Linking ISSN / ISSN-L) links different media versions of the same
         * serial publication together. The ISSN-L is typically the ISSN of the
         * first published medium (usually print).
         *
         * Format: Same as ISSN (NNNN-NNNC where C = check digit 0-9 or X)
         *
         * Key characteristics:
         * - Links print, online, CD-ROM versions of same publication
         * - Usually equals the p-ISSN of the first medium
         * - Registered in the ISSN-L database
         *
         * Common patterns:
         * - LISSN NNNN-NNNN
         * - ISSN-L NNNN-NNNN
         * - https://portal.issn.org/resource/ISSN-L/NNNN-NNNN
         */

        describe('LISSN with LISSN prefix', () => {
            it('detects LISSN prefix: LISSN 1756-6606', () => {
                expect(detectIdentifierType('LISSN 1756-6606')).toBe('LISSN');
            });

            it('detects LISSN prefix: LISSN 0264-2875', () => {
                expect(detectIdentifierType('LISSN 0264-2875')).toBe('LISSN');
            });

            it('detects LISSN prefix: LISSN 1188-1534', () => {
                expect(detectIdentifierType('LISSN 1188-1534')).toBe('LISSN');
            });

            it('detects LISSN prefix: LISSN 0378-5955', () => {
                expect(detectIdentifierType('LISSN 0378-5955')).toBe('LISSN');
            });

            it('detects LISSN prefix: LISSN 0001-6772', () => {
                expect(detectIdentifierType('LISSN 0001-6772')).toBe('LISSN');
            });

            it('detects LISSN prefix: LISSN 1748-7188', () => {
                expect(detectIdentifierType('LISSN 1748-7188')).toBe('LISSN');
            });

            it('detects LISSN prefix: LISSN 2589-7500', () => {
                expect(detectIdentifierType('LISSN 2589-7500')).toBe('LISSN');
            });

            it('detects LISSN prefix: LISSN 2375-2548', () => {
                expect(detectIdentifierType('LISSN 2375-2548')).toBe('LISSN');
            });

            it('detects LISSN prefix: LISSN 1932-6203', () => {
                expect(detectIdentifierType('LISSN 1932-6203')).toBe('LISSN');
            });

            it('detects LISSN prefix with X check digit: LISSN 2296-858X', () => {
                expect(detectIdentifierType('LISSN 2296-858X')).toBe('LISSN');
            });
        });

        describe('LISSN with ISSN-L prefix', () => {
            it('detects ISSN-L prefix: ISSN-L 1756-6606', () => {
                expect(detectIdentifierType('ISSN-L 1756-6606')).toBe('LISSN');
            });

            it('detects ISSN-L prefix: ISSN-L 0264-2875', () => {
                expect(detectIdentifierType('ISSN-L 0264-2875')).toBe('LISSN');
            });

            it('detects ISSN-L prefix: ISSN-L 1188-1534', () => {
                expect(detectIdentifierType('ISSN-L 1188-1534')).toBe('LISSN');
            });

            it('detects ISSN-L prefix: ISSN-L 0378-5955', () => {
                expect(detectIdentifierType('ISSN-L 0378-5955')).toBe('LISSN');
            });

            it('detects ISSN-L prefix: ISSN-L 0001-6772', () => {
                expect(detectIdentifierType('ISSN-L 0001-6772')).toBe('LISSN');
            });

            it('detects ISSN-L prefix: ISSN-L 1748-7188', () => {
                expect(detectIdentifierType('ISSN-L 1748-7188')).toBe('LISSN');
            });

            it('detects ISSN-L prefix: ISSN-L 2589-7500', () => {
                expect(detectIdentifierType('ISSN-L 2589-7500')).toBe('LISSN');
            });

            it('detects ISSN-L prefix: ISSN-L 2375-2548', () => {
                expect(detectIdentifierType('ISSN-L 2375-2548')).toBe('LISSN');
            });

            it('detects ISSN-L prefix: ISSN-L 1932-6203', () => {
                expect(detectIdentifierType('ISSN-L 1932-6203')).toBe('LISSN');
            });

            it('detects ISSN-L prefix with X check digit: ISSN-L 2296-858X', () => {
                expect(detectIdentifierType('ISSN-L 2296-858X')).toBe('LISSN');
            });
        });

        describe('LISSN with portal.issn.org ISSN-L URL', () => {
            it('detects portal ISSN-L URL: https://portal.issn.org/resource/ISSN-L/1756-6606', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN-L/1756-6606')).toBe('LISSN');
            });

            it('detects portal ISSN-L URL: https://portal.issn.org/resource/ISSN-L/0264-2875', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN-L/0264-2875')).toBe('LISSN');
            });

            it('detects portal ISSN-L URL: https://portal.issn.org/resource/ISSN-L/1188-1534', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN-L/1188-1534')).toBe('LISSN');
            });

            it('detects portal ISSN-L URL: https://portal.issn.org/resource/ISSN-L/0378-5955', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN-L/0378-5955')).toBe('LISSN');
            });

            it('detects portal ISSN-L URL: https://portal.issn.org/resource/ISSN-L/0001-6772', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN-L/0001-6772')).toBe('LISSN');
            });

            it('detects portal ISSN-L URL: https://portal.issn.org/resource/ISSN-L/1748-7188', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN-L/1748-7188')).toBe('LISSN');
            });

            it('detects portal ISSN-L URL: https://portal.issn.org/resource/ISSN-L/2589-7500', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN-L/2589-7500')).toBe('LISSN');
            });

            it('detects portal ISSN-L URL: https://portal.issn.org/resource/ISSN-L/2375-2548', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN-L/2375-2548')).toBe('LISSN');
            });

            it('detects portal ISSN-L URL: https://portal.issn.org/resource/ISSN-L/1932-6203', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN-L/1932-6203')).toBe('LISSN');
            });

            it('detects portal ISSN-L URL with X: https://portal.issn.org/resource/ISSN-L/2296-858X', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN-L/2296-858X')).toBe('LISSN');
            });
        });

        describe('LISSN edge cases', () => {
            it('handles lowercase lissn prefix', () => {
                expect(detectIdentifierType('lissn 1756-6606')).toBe('LISSN');
            });

            it('handles lowercase issn-l prefix', () => {
                expect(detectIdentifierType('issn-l 1756-6606')).toBe('LISSN');
            });

            it('handles LISSN with colon', () => {
                expect(detectIdentifierType('LISSN: 1756-6606')).toBe('LISSN');
            });

            it('handles ISSN-L with colon', () => {
                expect(detectIdentifierType('ISSN-L: 0264-2875')).toBe('LISSN');
            });

            it('handles leading/trailing whitespace', () => {
                expect(detectIdentifierType('  LISSN 1756-6606  ')).toBe('LISSN');
            });

            it('handles ISSN-L with lowercase x check digit', () => {
                expect(detectIdentifierType('ISSN-L 2296-858x')).toBe('LISSN');
            });

            it('handles compact format with LISSN prefix', () => {
                expect(detectIdentifierType('LISSN 17566606')).toBe('LISSN');
            });

            it('handles compact format with ISSN-L prefix', () => {
                expect(detectIdentifierType('ISSN-L 02642875')).toBe('LISSN');
            });
        });

        describe('real-world LISSN examples from user requirements', () => {
            // 1. Nature Communications (Print + Online)
            it('detects Nature Communications LISSN: LISSN 1756-6606', () => {
                expect(detectIdentifierType('LISSN 1756-6606')).toBe('LISSN');
            });

            it('detects Nature Communications ISSN-L: ISSN-L 1756-6606', () => {
                expect(detectIdentifierType('ISSN-L 1756-6606')).toBe('LISSN');
            });

            it('detects Nature Communications portal: https://portal.issn.org/resource/ISSN-L/1756-6606', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN-L/1756-6606')).toBe('LISSN');
            });

            // 2. Dance Research (Print + Online)
            it('detects Dance Research LISSN: LISSN 0264-2875', () => {
                expect(detectIdentifierType('LISSN 0264-2875')).toBe('LISSN');
            });

            it('detects Dance Research ISSN-L: ISSN-L 0264-2875', () => {
                expect(detectIdentifierType('ISSN-L 0264-2875')).toBe('LISSN');
            });

            it('detects Dance Research portal: https://portal.issn.org/resource/ISSN-L/0264-2875', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN-L/0264-2875')).toBe('LISSN');
            });

            // 3. Plant Varieties Journal (Print + Online + CD-ROM)
            it('detects Plant Varieties LISSN: LISSN 1188-1534', () => {
                expect(detectIdentifierType('LISSN 1188-1534')).toBe('LISSN');
            });

            it('detects Plant Varieties ISSN-L: ISSN-L 1188-1534', () => {
                expect(detectIdentifierType('ISSN-L 1188-1534')).toBe('LISSN');
            });

            it('detects Plant Varieties portal: https://portal.issn.org/resource/ISSN-L/1188-1534', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN-L/1188-1534')).toBe('LISSN');
            });

            // 4. Hearing Research (Elsevier, Print + Online)
            it('detects Hearing Research LISSN: LISSN 0378-5955', () => {
                expect(detectIdentifierType('LISSN 0378-5955')).toBe('LISSN');
            });

            it('detects Hearing Research ISSN-L: ISSN-L 0378-5955', () => {
                expect(detectIdentifierType('ISSN-L 0378-5955')).toBe('LISSN');
            });

            it('detects Hearing Research portal: https://portal.issn.org/resource/ISSN-L/0378-5955', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN-L/0378-5955')).toBe('LISSN');
            });

            // 5. Acta Physiologica Scandinavica (Print + Online)
            it('detects Acta Physiologica LISSN: LISSN 0001-6772', () => {
                expect(detectIdentifierType('LISSN 0001-6772')).toBe('LISSN');
            });

            it('detects Acta Physiologica ISSN-L: ISSN-L 0001-6772', () => {
                expect(detectIdentifierType('ISSN-L 0001-6772')).toBe('LISSN');
            });

            it('detects Acta Physiologica portal: https://portal.issn.org/resource/ISSN-L/0001-6772', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN-L/0001-6772')).toBe('LISSN');
            });

            // 6. Algorithms for Molecular Biology (Online Only)
            it('detects Algorithms Mol Bio LISSN: LISSN 1748-7188', () => {
                expect(detectIdentifierType('LISSN 1748-7188')).toBe('LISSN');
            });

            it('detects Algorithms Mol Bio ISSN-L: ISSN-L 1748-7188', () => {
                expect(detectIdentifierType('ISSN-L 1748-7188')).toBe('LISSN');
            });

            it('detects Algorithms Mol Bio portal: https://portal.issn.org/resource/ISSN-L/1748-7188', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN-L/1748-7188')).toBe('LISSN');
            });

            // 7. The Lancet Digital Health (Online Only)
            it('detects Lancet Digital Health LISSN: LISSN 2589-7500', () => {
                expect(detectIdentifierType('LISSN 2589-7500')).toBe('LISSN');
            });

            it('detects Lancet Digital Health ISSN-L: ISSN-L 2589-7500', () => {
                expect(detectIdentifierType('ISSN-L 2589-7500')).toBe('LISSN');
            });

            it('detects Lancet Digital Health portal: https://portal.issn.org/resource/ISSN-L/2589-7500', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN-L/2589-7500')).toBe('LISSN');
            });

            // 8. Science Advances (AAAS, Online Only)
            it('detects Science Advances LISSN: LISSN 2375-2548', () => {
                expect(detectIdentifierType('LISSN 2375-2548')).toBe('LISSN');
            });

            it('detects Science Advances ISSN-L: ISSN-L 2375-2548', () => {
                expect(detectIdentifierType('ISSN-L 2375-2548')).toBe('LISSN');
            });

            it('detects Science Advances portal: https://portal.issn.org/resource/ISSN-L/2375-2548', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN-L/2375-2548')).toBe('LISSN');
            });

            // 9. PLOS ONE (Online Open Access)
            it('detects PLOS ONE LISSN: LISSN 1932-6203', () => {
                expect(detectIdentifierType('LISSN 1932-6203')).toBe('LISSN');
            });

            it('detects PLOS ONE ISSN-L: ISSN-L 1932-6203', () => {
                expect(detectIdentifierType('ISSN-L 1932-6203')).toBe('LISSN');
            });

            it('detects PLOS ONE portal: https://portal.issn.org/resource/ISSN-L/1932-6203', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN-L/1932-6203')).toBe('LISSN');
            });

            // 10. Frontiers in Medicine (Online Open Access with X Check Digit)
            it('detects Frontiers in Medicine LISSN: LISSN 2296-858X', () => {
                expect(detectIdentifierType('LISSN 2296-858X')).toBe('LISSN');
            });

            it('detects Frontiers in Medicine ISSN-L: ISSN-L 2296-858X', () => {
                expect(detectIdentifierType('ISSN-L 2296-858X')).toBe('LISSN');
            });

            it('detects Frontiers in Medicine portal: https://portal.issn.org/resource/ISSN-L/2296-858X', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN-L/2296-858X')).toBe('LISSN');
            });
        });

        describe('LISSN should NOT be detected for non-LISSN identifiers', () => {
            it('should not detect plain ISSN as LISSN (requires prefix)', () => {
                expect(detectIdentifierType('1756-6606')).not.toBe('LISSN');
            });

            it('should not detect eISSN prefix as LISSN', () => {
                expect(detectIdentifierType('eISSN 1756-6606')).not.toBe('LISSN');
            });

            it('should not detect p-ISSN prefix as LISSN', () => {
                expect(detectIdentifierType('p-ISSN 1756-6606')).not.toBe('LISSN');
            });

            it('should not detect portal ISSN URL as LISSN', () => {
                expect(detectIdentifierType('https://portal.issn.org/resource/ISSN/1756-6606')).not.toBe('LISSN');
            });

            it('should not detect urn:issn as LISSN', () => {
                expect(detectIdentifierType('urn:issn:1756-6606')).not.toBe('LISSN');
            });

            it('should not detect plain URLs as LISSN', () => {
                expect(detectIdentifierType('https://example.com/resource')).not.toBe('LISSN');
            });

            it('should not detect DOIs as LISSN', () => {
                expect(detectIdentifierType('10.5880/fidgeo.2025.072')).not.toBe('LISSN');
            });

            it('should not detect ISBN as LISSN', () => {
                expect(detectIdentifierType('978-0-306-40615-7')).not.toBe('LISSN');
            });

            it('should not detect ISTC as LISSN', () => {
                expect(detectIdentifierType('0A9-2010-31F4-CB2C-B')).not.toBe('LISSN');
            });

            it('should not detect compact ISSN without prefix as LISSN', () => {
                expect(detectIdentifierType('17566606')).not.toBe('LISSN');
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

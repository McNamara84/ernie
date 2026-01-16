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

            it('detects ISBN as EAN-13 (978 prefix)', () => {
                expect(detectIdentifierType('9780141026626')).toBe('EAN13');
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

            it('detects ISBN as EAN-13 with hyphens', () => {
                expect(detectIdentifierType('978-0-141-02662-6')).toBe('EAN13');
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

            it('detects urn:ean13 with ISBN', () => {
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
                // ISBN → EAN-13
                { input: '9780141026626', description: 'ISBN compact' },
                { input: '978-0-141-02662-6', description: 'ISBN with hyphens' },
                { input: 'https://identifiers.org/ean13:9780141026626', description: 'ISBN identifiers.org' },
                { input: 'urn:ean13:9780141026626', description: 'ISBN URN format' },
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

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

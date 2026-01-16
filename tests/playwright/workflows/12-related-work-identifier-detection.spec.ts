import { expect, test } from '@playwright/test';

import { loginAsTestUser } from '../helpers/test-helpers';

/**
 * E2E Tests for Related Work Identifier Type Auto-Detection
 *
 * These tests verify that the Related Work form correctly auto-detects
 * identifier types when users enter identifiers. The detection should
 * support various DOI formats, URLs, Handles, and other identifier types.
 *
 * The tests use real-world examples of identifiers to ensure accurate detection.
 *
 * Test Strategy:
 * 1. Enter an identifier in the input field
 * 2. Click the Add button to add the related work
 * 3. Check the identifier type badge in the added item list
 */

test.describe('Related Work Identifier Type Detection', () => {
    test.beforeEach(async ({ page }) => {
        await loginAsTestUser(page);
        await page.goto('/editor');

        // Wait for the page to be fully loaded
        await page.waitForLoadState('networkidle');

        // Check if the accordion is already open (it's open by default in the editor)
        const relatedWorkSection = page.getByTestId('related-work-section');
        await relatedWorkSection.waitFor({ state: 'visible', timeout: 10000 });

        const isOpen = await relatedWorkSection.getAttribute('data-state') === 'open';

        // Only click to open if it's currently closed
        if (!isOpen) {
            const relatedWorkAccordion = page.getByTestId('related-work-accordion-trigger');
            await relatedWorkAccordion.scrollIntoViewIfNeeded();
            await relatedWorkAccordion.click();
            await expect(relatedWorkSection).toHaveAttribute('data-state', 'open', { timeout: 10000 });
        }

        // Now wait for the input to be visible and interactable
        const identifierInput = page.getByTestId('related-identifier-input');
        await identifierInput.waitFor({ state: 'visible', timeout: 10000 });
    });

    /**
     * Helper function to add a related work and verify its identifier type
     */
    async function addRelatedWorkAndVerifyType(
        page: import('@playwright/test').Page,
        identifier: string,
        expectedType: string,
    ) {
        // Wait for and enter the identifier using stable data-testid
        const identifierInput = page.getByTestId('related-identifier-input');
        await identifierInput.waitFor({ state: 'visible', timeout: 10000 });
        await identifierInput.fill(identifier);

        // Wait for the Add button to become enabled (validation complete)
        // The button is disabled while: identifier is empty, validation is running, or validation failed
        const addButton = page.getByTestId('add-related-work-button');
        await addButton.waitFor({ state: 'visible', timeout: 5000 });

        // Wait until button is enabled (validation debounce + API call can take several seconds in CI)
        await expect(addButton).toBeEnabled({ timeout: 15000 });

        await addButton.click();

        // Wait for the identifier type badge to appear using stable data-testid
        const typeBadge = page.getByTestId('identifier-type-badge').filter({ hasText: expectedType });
        await expect(typeBadge.first()).toBeVisible({ timeout: 5000 });
    }

    test.describe('DOI Detection', () => {
        /**
         * Test cases for DOI auto-detection.
         * Each test enters a DOI in various formats and verifies the identifier type is set to "DOI"
         */

        test('detects bare DOI format: 10.5880/fidgeo.2025.072', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, '10.5880/fidgeo.2025.072', 'DOI');
        });

        test('detects DOI with URL prefix: https://doi.org/10.5880/fidgeo.2026.001', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'https://doi.org/10.5880/fidgeo.2026.001', 'DOI');
        });

        test('detects DIGIS DOI: 10.5880/digis.2025.005', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, '10.5880/digis.2025.005', 'DOI');
        });

        test('detects AGU publication DOI: https://doi.org/10.1029/2015EO022207', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'https://doi.org/10.1029/2015EO022207', 'DOI');
        });

        test('detects GFZ DOI with uppercase: 10.5880/GFZ.DMJQ.2025.005', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, '10.5880/GFZ.DMJQ.2025.005', 'DOI');
        });

        test('detects EGU abstract DOI: 10.5194/egusphere-egu25-20132', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, '10.5194/egusphere-egu25-20132', 'DOI');
        });

        test('detects DOI with doi: prefix: doi:10.1371/journal.pbio.0020449', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'doi:10.1371/journal.pbio.0020449', 'DOI');
        });

        test('detects PLOS Biology DOI: 10.1371/journal.pbio.0020449', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, '10.1371/journal.pbio.0020449', 'DOI');
        });

        test('detects legacy dx.doi.org URL: https://dx.doi.org/10.5880/fidgeo.2025.072', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'https://dx.doi.org/10.5880/fidgeo.2025.072', 'DOI');
        });

        test('detects DOI with http:// prefix: http://doi.org/10.5880/test.2025.001', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'http://doi.org/10.5880/test.2025.001', 'DOI');
        });

        test.describe('DOI edge cases', () => {
            test('handles DOI with parentheses: 10.1000/xyz(2023)001', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '10.1000/xyz(2023)001', 'DOI');
            });

            test('handles DOI with underscores: 10.5880/fidgeo_special_2025', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '10.5880/fidgeo_special_2025', 'DOI');
            });

            test('handles DOI with 5-digit registrant code: 10.12345/example.2025', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '10.12345/example.2025', 'DOI');
            });

            test('handles DOI with leading/trailing whitespace', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '  10.5880/fidgeo.2025.072  ', 'DOI');
            });
        });
    });

    test.describe('ARK Detection', () => {
        test.describe('compact ARK format', () => {
            test('detects BnF manuscript ARK: ark:12148/btv1b8449691v/f29', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'ark:12148/btv1b8449691v/f29', 'ARK');
            });

            test('detects Smithsonian specimen ARK with UUID', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'ark:65665/381440f27-3f74-4eb9-ac11-b4d633a7da3d', 'ARK');
            });

            test('detects FamilySearch genealogy ARK: ark:61903/1:1:K98H-2G2', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'ark:61903/1:1:K98H-2G2', 'ARK');
            });

            test('detects Internet Archive ARK: ark:13960/t5z64fc55', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'ark:13960/t5z64fc55', 'ARK');
            });

            test('detects UNT Digital Library ARK: ark:67531/metadc107835', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'ark:67531/metadc107835', 'ARK');
            });
        });

        test.describe('old ARK format with slash (ark:/NAAN/Name)', () => {
            test('detects ARK with slash after colon', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'ark:/12148/btv1b8449691v/f29', 'ARK');
            });
        });

        test.describe('ARK with resolver URLs', () => {
            test('detects n2t.net ARK URL', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://n2t.net/ark:/12148/btv1b8449691v/f29', 'ARK');
            });

            test('detects ark.bnf.fr ARK URL', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://ark.bnf.fr/ark:12148/btv1b8449691v/f29', 'ARK');
            });

            test('detects FamilySearch resolver ARK URL', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://www.familysearch.org/ark:/61903/1:1:K98H-2G2', 'ARK');
            });

            test('detects archive.org ARK URL', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://archive.org/details/ark:/13960/t5z64fc55', 'ARK');
            });

            test('detects data.bnf.fr ARK URL', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'http://data.bnf.fr/ark:/12148/cb166125510', 'ARK');
            });
        });
    });

    test.describe('arXiv Detection', () => {
        test.describe('arXiv new format bare', () => {
            test('detects bare arXiv ID: 2501.13958', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '2501.13958', 'arXiv');
            });

            test('detects versioned arXiv ID: 2501.13958v3', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '2501.13958v3', 'arXiv');
            });

            test('detects old arXiv format: 0704.0001', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '0704.0001', 'arXiv');
            });
        });

        test.describe('arXiv with arXiv: prefix', () => {
            test('detects arXiv:2501.13958', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'arXiv:2501.13958', 'arXiv');
            });

            test('detects arXiv:2501.13958v3', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'arXiv:2501.13958v3', 'arXiv');
            });

            test('detects arXiv:hep-th/9901001 (old format)', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'arXiv:hep-th/9901001', 'arXiv');
            });

            test('detects arXiv:astro-ph/9310023 (old format)', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'arXiv:astro-ph/9310023', 'arXiv');
            });
        });

        test.describe('arXiv old format bare (category/YYMMNNN)', () => {
            test('detects hep-th/9901001', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'hep-th/9901001', 'arXiv');
            });

            test('detects astro-ph/9310023', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'astro-ph/9310023', 'arXiv');
            });
        });

        test.describe('arXiv URLs', () => {
            test('detects abstract URL: https://arxiv.org/abs/2501.13958', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://arxiv.org/abs/2501.13958', 'arXiv');
            });

            test('detects PDF URL: https://arxiv.org/pdf/2501.13958.pdf', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://arxiv.org/pdf/2501.13958.pdf', 'arXiv');
            });

            test('detects HTML URL: https://arxiv.org/html/2501.13958v3', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://arxiv.org/html/2501.13958v3', 'arXiv');
            });

            test('detects source URL: https://arxiv.org/src/2501.05547', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://arxiv.org/src/2501.05547', 'arXiv');
            });

            test('detects old format abstract URL: https://arxiv.org/abs/hep-th/9901001', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://arxiv.org/abs/hep-th/9901001', 'arXiv');
            });
        });

        test.describe('arXiv DOIs should be detected as DOI', () => {
            test('detects arXiv DOI URL as DOI', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://doi.org/10.48550/arXiv.2501.13958', 'DOI');
            });

            test('detects bare arXiv DOI as DOI', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '10.48550/arXiv.2501.13958', 'DOI');
            });
        });
    });

    test.describe('bibcode Detection', () => {
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

        test.describe('standard journal bibcodes', () => {
            test('detects Astronomical Journal bibcode: 2024AJ....167...20Z', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '2024AJ....167...20Z', 'bibcode');
            });

            test('detects AJ bibcode with single digit page: 2024AJ....167....5L', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '2024AJ....167....5L', 'bibcode');
            });

            test('detects ApJ bibcode with Letter qualifier: 1970ApJ...161L..77K', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '1970ApJ...161L..77K', 'bibcode');
            });

            test('detects classic AJ bibcode: 1974AJ.....79..819H', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '1974AJ.....79..819H', 'bibcode');
            });

            test('detects MNRAS bibcode: 1924MNRAS..84..308E', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '1924MNRAS..84..308E', 'bibcode');
            });

            test('detects ApJ Letters bibcode: 2024ApJ...963L...2S', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '2024ApJ...963L...2S', 'bibcode');
            });

            test('detects standard ApJ bibcode: 2023ApJ...958...84B', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '2023ApJ...958...84B', 'bibcode');
            });
        });

        test.describe('bibcodes with special characters', () => {
            test('detects A&A bibcode (with ampersand): 2024A&A...687A..74T', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '2024A&A...687A..74T', 'bibcode');
            });
        });

        test.describe('special bibcode formats', () => {
            test('detects arXiv preprint tracked in ADS: 2024arXiv240413032B', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '2024arXiv240413032B', 'bibcode');
            });

            test('detects JWST proposal bibcode: 2023jwst.prop.4537H', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '2023jwst.prop.4537H', 'bibcode');
            });

            test('detects Science journal bibcode: 2024Sci...383..988G', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '2024Sci...383..988G', 'bibcode');
            });

            test('detects Nature journal bibcode: 2024Natur.625..253K', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '2024Natur.625..253K', 'bibcode');
            });
        });

        test.describe('ADS URL formats', () => {
            test('detects ui.adsabs.harvard.edu URL', async ({ page }) => {
                await addRelatedWorkAndVerifyType(
                    page,
                    'https://ui.adsabs.harvard.edu/abs/2024AJ....167...20Z',
                    'bibcode',
                );
            });

            test('detects adsabs.harvard.edu URL (without ui prefix)', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://adsabs.harvard.edu/abs/2024AJ....167...20Z', 'bibcode');
            });

            test('detects ADS URL with abstract suffix', async ({ page }) => {
                await addRelatedWorkAndVerifyType(
                    page,
                    'https://ui.adsabs.harvard.edu/abs/2024AJ....167...20Z/abstract',
                    'bibcode',
                );
            });

            test('detects ADS URL with A&A bibcode (URL encoded ampersand)', async ({ page }) => {
                await addRelatedWorkAndVerifyType(
                    page,
                    'https://ui.adsabs.harvard.edu/abs/2024A%26A...687A..74T',
                    'bibcode',
                );
            });
        });
    });

    test.describe('CSTR Detection', () => {
        /**
         * CSTR (China Science and Technology Resource) is a persistent identifier system
         * for Chinese scientific data resources.
         *
         * Format: CSTR:RA_CODE.TYPE.NAMESPACE.LOCAL_ID
         * - RA_CODE = 5-digit Registration Authority code (e.g., 31253, 50001)
         * - TYPE = 2-digit resource type code (e.g., 11 = ScienceDB, 22 = Material Science)
         * - NAMESPACE = Repository namespace
         * - LOCAL_ID = Local identifier
         */

        test.describe('CSTR with prefix (CSTR:...)', () => {
            test('detects ScienceDB standard CSTR: CSTR:31253.11.sciencedb.j00001.00123', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'CSTR:31253.11.sciencedb.j00001.00123', 'CSTR');
            });

            test('detects lowercase cstr: prefix', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'cstr:31253.11.sciencedb.j00001.00123', 'CSTR');
            });

            test('detects climate data CSTR', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'CSTR:31253.11.sciencedb.CC_000001', 'CSTR');
            });

            test('detects biodiversity CSTR with hyphen', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'CSTR:31253.11.bio-resources.BD_999999', 'CSTR');
            });

            test('detects chemical structures CSTR', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'CSTR:31253.11.chem_structures.compound_xyz', 'CSTR');
            });

            test('detects genome sequence CSTR with UUID', async ({ page }) => {
                await addRelatedWorkAndVerifyType(
                    page,
                    'CSTR:31253.11.genomedb.seq-d041e5f0-a1b2-c3d4-e5f6-789abcdef000',
                    'CSTR',
                );
            });

            test('detects material science CSTR (different RA_CODE)', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'CSTR:50001.22.material_science.data_001', 'CSTR');
            });

            test('detects research project CSTR with deep path', async ({ page }) => {
                await addRelatedWorkAndVerifyType(
                    page,
                    'CSTR:31253.11.sciencedb.research_project.2024.january.experiment_001.raw_data',
                    'CSTR',
                );
            });
        });

        test.describe('CSTR bare format (without prefix)', () => {
            test('detects bare ScienceDB CSTR: 31253.11.sciencedb.j00001.00123', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '31253.11.sciencedb.j00001.00123', 'CSTR');
            });

            test('detects bare material science CSTR', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '50001.22.material_science.data_001', 'CSTR');
            });
        });

        test.describe('CSTR with resolver URLs', () => {
            test('detects identifiers.org CSTR URL', async ({ page }) => {
                await addRelatedWorkAndVerifyType(
                    page,
                    'https://identifiers.org/cstr:31253.11.sciencedb.j00001.00123',
                    'CSTR',
                );
            });

            test('detects bioregistry.io CSTR URL', async ({ page }) => {
                await addRelatedWorkAndVerifyType(
                    page,
                    'https://bioregistry.io/cstr:31253.11.sciencedb.j00001.00123',
                    'CSTR',
                );
            });

            test('detects bioregistry.io bio-resources URL', async ({ page }) => {
                await addRelatedWorkAndVerifyType(
                    page,
                    'https://bioregistry.io/cstr:31253.11.bio-resources.BD_999999',
                    'CSTR',
                );
            });
        });
    });

    test.describe('EAN-13 Detection', () => {
        /**
         * EAN-13 (European Article Number) is a 13-digit barcode standard
         * for product identification globally.
         *
         * Format: CCXXXXXPPPPPK (Country + Manufacturer + Product + Check digit)
         */

        test.describe('EAN-13 compact format', () => {
            test('detects German product EAN-13: 4006381333931', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '4006381333931', 'EAN13');
            });

            test('detects French product EAN-13: 3595384751201', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '3595384751201', 'EAN13');
            });

            test('detects Italian product EAN-13: 8008698001248', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '8008698001248', 'EAN13');
            });

            test('detects Japanese product EAN-13: 4901234123457', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '4901234123457', 'EAN13');
            });

            test('detects ISBN as EAN-13 (978 prefix): 9780141026626', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '9780141026626', 'EAN13');
            });

            test('detects USA/Canada UPC-A with EAN-13 prefix: 0012345678905', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '0012345678905', 'EAN13');
            });

            test('detects store internal EAN-13 (20-29 range): 2012345678900', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '2012345678900', 'EAN13');
            });
        });

        test.describe('EAN-13 with hyphens', () => {
            test('detects German EAN-13 with hyphens: 400-6381-33393-1', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '400-6381-33393-1', 'EAN13');
            });

            test('detects ISBN with hyphens: 978-0-141-02662-6', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '978-0-141-02662-6', 'EAN13');
            });
        });

        test.describe('EAN-13 with spaces', () => {
            test('detects German EAN-13 with space: 4006381 333931', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '4006381 333931', 'EAN13');
            });
        });

        test.describe('EAN-13 with resolver URLs', () => {
            test('detects identifiers.org EAN-13 URL', async ({ page }) => {
                await addRelatedWorkAndVerifyType(
                    page,
                    'https://identifiers.org/ean13:4006381333931',
                    'EAN13',
                );
            });

            test('detects GS1 Digital Link URL', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://gs1.example.com/01/4006381333931', 'EAN13');
            });
        });

        test.describe('EAN-13 with URN format', () => {
            test('detects urn:ean13 format', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'urn:ean13:4006381333931', 'EAN13');
            });

            test('detects urn:gtin format', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'urn:gtin:3595384751201', 'EAN13');
            });

            test('detects urn:gtin-13 format', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'urn:gtin-13:7318120000002', 'EAN13');
            });
        });
    });

    test.describe('EISSN detection', () => {
        /**
         * EISSN (Electronic International Standard Serial Number) is an 8-digit
         * identifier for electronic serial publications.
         *
         * Format: NNNN-NNNC (where C = check digit 0-9 or X)
         */

        test.describe('EISSN standard format with hyphen', () => {
            test('detects Hearing Research Journal EISSN: 0378-5955', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '0378-5955', 'EISSN');
            });

            test('detects Nature Communications EISSN: 2041-1723', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '2041-1723', 'EISSN');
            });

            test('detects Lancet Digital Health EISSN: 2589-7500', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '2589-7500', 'EISSN');
            });

            test('detects Science Advances EISSN: 2375-2548', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '2375-2548', 'EISSN');
            });

            test('detects PLOS ONE EISSN: 1932-6203', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '1932-6203', 'EISSN');
            });

            test('detects Frontiers in Medicine EISSN with X: 2296-858X', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '2296-858X', 'EISSN');
            });

            test('detects eLife EISSN with X: 2050-084X', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '2050-084X', 'EISSN');
            });
        });

        test.describe('EISSN compact format (8 digits)', () => {
            test('detects Hearing Research compact: 03785955', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '03785955', 'EISSN');
            });

            test('detects Nature Communications compact: 20411723', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '20411723', 'EISSN');
            });

            test('detects Frontiers compact with X: 2296858X', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '2296858X', 'EISSN');
            });

            test('detects eLife compact with X: 2050084X', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '2050084X', 'EISSN');
            });
        });

        test.describe('EISSN with prefix', () => {
            test('detects EISSN prefix: EISSN 0378-5955', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'EISSN 0378-5955', 'EISSN');
            });

            test('detects e-ISSN prefix: e-ISSN 2041-1723', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'e-ISSN 2041-1723', 'EISSN');
            });

            test('detects eISSN prefix: eISSN 2589-7500', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'eISSN 2589-7500', 'EISSN');
            });

            test('detects e-ISSN: with colon: e-ISSN: 2375-2548', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'e-ISSN: 2375-2548', 'EISSN');
            });
        });

        test.describe('EISSN with URN format', () => {
            test('detects urn:issn format: urn:issn:0378-5955', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'urn:issn:0378-5955', 'EISSN');
            });

            test('detects urn:issn with X: urn:issn:2296-858X', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'urn:issn:2296-858X', 'EISSN');
            });
        });

        test.describe('EISSN with portal.issn.org URL', () => {
            test('detects portal URL: https://portal.issn.org/resource/ISSN/0378-5955', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://portal.issn.org/resource/ISSN/0378-5955', 'EISSN');
            });

            test('detects portal URL with X: https://portal.issn.org/resource/ISSN/2296-858X', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://portal.issn.org/resource/ISSN/2296-858X', 'EISSN');
            });
        });

        test.describe('EISSN with identifiers.org URL', () => {
            test('detects identifiers.org URL: https://identifiers.org/issn:0378-5955', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://identifiers.org/issn:0378-5955', 'EISSN');
            });

            test('detects identifiers.org with X: https://identifiers.org/issn:2050-084X', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://identifiers.org/issn:2050-084X', 'EISSN');
            });
        });

        test.describe('EISSN with worldcat.org URL', () => {
            test('detects worldcat URL: https://www.worldcat.org/issn/0378-5955', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://www.worldcat.org/issn/0378-5955', 'EISSN');
            });

            test('detects worldcat URL with X: https://www.worldcat.org/issn/2296-858X', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://www.worldcat.org/issn/2296-858X', 'EISSN');
            });
        });
    });

    test.describe('Handle detection', () => {
        /**
         * Handle System identifiers are persistent identifiers for digital objects.
         *
         * Format: prefix/suffix
         * - Prefix: Numeric or alphanumeric with dots (e.g., 2142, 21.T11998, 10.1594)
         * - Suffix: Alphanumeric with hyphens, underscores, dots, colons
         */

        test.describe('Handle compact format', () => {
            test('detects simple numeric prefix: 2142/103380', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '2142/103380', 'Handle');
            });

            test('detects BiCIKL specimen: 11148/btv1b8449691v', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '11148/btv1b8449691v', 'Handle');
            });

            test('detects FDO type: 21.T11998/0000-001A-3905-1', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '21.T11998/0000-001A-3905-1', 'Handle');
            });

            test('detects PIDINST: 21.T11148/7adfcd13b3b01de0d875', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '21.T11148/7adfcd13b3b01de0d875', 'Handle');
            });

            test('detects GWDG: 21.11145/8fefa88dea', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '21.11145/8fefa88dea', 'Handle');
            });

            test('detects UUID-based: 11148/d041e5f0-a1b2-c3d4-e5f6-789abcdef000', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '11148/d041e5f0-a1b2-c3d4-e5f6-789abcdef000', 'Handle');
            });

            test('detects hierarchical: 1234/test.object.climate.2024.v1', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '1234/test.object.climate.2024.v1', 'Handle');
            });

            test('detects descriptive: 2142/data_archive_collection_2024_001', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '2142/data_archive_collection_2024_001', 'Handle');
            });
        });

        test.describe('Handle with https resolver URL', () => {
            test('detects https URL: https://hdl.handle.net/2142/103380', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://hdl.handle.net/2142/103380', 'Handle');
            });

            test('detects FDO https URL: https://hdl.handle.net/21.T11998/0000-001A-3905-1', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://hdl.handle.net/21.T11998/0000-001A-3905-1', 'Handle');
            });

            test('detects GWDG https URL: https://hdl.handle.net/21.11145/8fefa88dea', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://hdl.handle.net/21.11145/8fefa88dea', 'Handle');
            });
        });

        test.describe('Handle with noredirect query parameter', () => {
            test('detects URL with noredirect: https://hdl.handle.net/2142/103380?noredirect', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://hdl.handle.net/2142/103380?noredirect', 'Handle');
            });

            test('detects FDO with noredirect: https://hdl.handle.net/21.T11998/0000-001A-3905-1?noredirect', async ({
                page,
            }) => {
                await addRelatedWorkAndVerifyType(
                    page,
                    'https://hdl.handle.net/21.T11998/0000-001A-3905-1?noredirect',
                    'Handle',
                );
            });
        });

        test.describe('Handle REST API URLs', () => {
            test('detects API URL: https://hdl.handle.net/api/handles/2142/103380', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://hdl.handle.net/api/handles/2142/103380', 'Handle');
            });

            test('detects FDO API URL: https://hdl.handle.net/api/handles/21.T11998/0000-001A-3905-1', async ({
                page,
            }) => {
                await addRelatedWorkAndVerifyType(
                    page,
                    'https://hdl.handle.net/api/handles/21.T11998/0000-001A-3905-1',
                    'Handle',
                );
            });
        });

        test.describe('Handle with hdl:// protocol', () => {
            test('detects hdl:// protocol: hdl://11148/btv1b8449691v', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'hdl://11148/btv1b8449691v', 'Handle');
            });

            test('detects hdl:// for FDO: hdl://21.T11998/0000-001A-3905-1', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'hdl://21.T11998/0000-001A-3905-1', 'Handle');
            });

            test('detects hdl:// for CORDRA: hdl://21.T11148/c2c8c452912d57a44117', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'hdl://21.T11148/c2c8c452912d57a44117', 'Handle');
            });
        });

        test.describe('Handle with URN format', () => {
            test('detects URN format: urn:handle:2142/103380', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'urn:handle:2142/103380', 'Handle');
            });

            test('detects URN for FDO: urn:handle:21.T11998/0000-001A-3905-1', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'urn:handle:21.T11998/0000-001A-3905-1', 'Handle');
            });

            test('detects URN for GWDG: urn:handle:21.11145/8fefa88dea', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'urn:handle:21.11145/8fefa88dea', 'Handle');
            });
        });

        test.describe('Handle with custom resolvers', () => {
            test('detects GWDG resolver: https://vm11.pid.gwdg.de:8445/objects/21.11145/8fefa88dea', async ({
                page,
            }) => {
                await addRelatedWorkAndVerifyType(
                    page,
                    'https://vm11.pid.gwdg.de:8445/objects/21.11145/8fefa88dea',
                    'Handle',
                );
            });
        });
    });

    test.describe('Non-DOI identifiers should not be detected as DOI', () => {
        test('detects plain URL as URL, not DOI', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'https://example.com/resource', 'URL');
        });

        test('detects Handle URL as Handle, not DOI', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'https://hdl.handle.net/11234/56789', 'Handle');
        });

        test('detects bare Handle as Handle, not DOI', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, '11234/56789', 'Handle');
        });

        test('detects ARK as ARK, not DOI', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'ark:12148/btv1b8449691v/f29', 'ARK');
        });

        test('detects arXiv as arXiv, not DOI', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'arXiv:2501.13958', 'arXiv');
        });

        test('detects bibcode as bibcode, not DOI', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, '2024AJ....167...20Z', 'bibcode');
        });

        test('detects CSTR as CSTR, not DOI', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'CSTR:31253.11.sciencedb.j00001.00123', 'CSTR');
        });

        test('detects EAN-13 as EAN13, not DOI', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, '4006381333931', 'EAN13');
        });

        test('detects EISSN as EISSN, not DOI', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, '0378-5955', 'EISSN');
        });

        test('detects Handle with FDO prefix as Handle, not DOI', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, '21.T11998/0000-001A-3905-1', 'Handle');
        });

        test('detects IGSN with igsn: prefix as IGSN, not DOI', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'igsn:AU1101', 'IGSN');
        });
    });

    test.describe('IGSN detection', () => {
        /**
         * IGSN (International Generic Sample Number) is a persistent identifier
         * for physical samples in geoscience research.
         *
         * Formats tested:
         * - Bare code: AU1101, SSH000SUA
         * - With IGSN prefix: IGSN AU1101
         * - With igsn: tag: igsn:AU1101
         * - DOI form: 10.60516/AU1101
         * - DOI URL: https://doi.org/10.60516/AU1101
         * - Legacy Handle: https://igsn.org/10.273/AU1101
         * - URN: urn:igsn:AU1101
         */

        test.describe('IGSN bare code format', () => {
            test('detects Geoscience Australia IGSN: AU1101', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'AU1101', 'IGSN');
            });

            test('detects SESAR USA IGSN: SSH000SUA', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'SSH000SUA', 'IGSN');
            });

            test('detects BGR Germany IGSN: BGRB5054RX05201', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'BGRB5054RX05201', 'IGSN');
            });

            test('detects ICDP IGSN: ICDP5054ESYI201', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'ICDP5054ESYI201', 'IGSN');
            });

            test('detects CSIRO IGSN: CSRWA275', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'CSRWA275', 'IGSN');
            });

            test('detects GFZ IGSN: GFZ000001ABC', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'GFZ000001ABC', 'IGSN');
            });

            test('detects MARUM IGSN: MBCR5034RC57001', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'MBCR5034RC57001', 'IGSN');
            });

            test('detects ARDC IGSN: ARDC2024001XYZ', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'ARDC2024001XYZ', 'IGSN');
            });
        });

        test.describe('IGSN with igsn: tag prefix', () => {
            test('detects igsn:AU1101', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'igsn:AU1101', 'IGSN');
            });

            test('detects igsn:SSH000SUA', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'igsn:SSH000SUA', 'IGSN');
            });

            test('detects igsn:BGRB5054RX05201', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'igsn:BGRB5054RX05201', 'IGSN');
            });

            test('detects igsn:ICDP5054ESYI201', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'igsn:ICDP5054ESYI201', 'IGSN');
            });
        });

        test.describe('IGSN DOI form (bare)', () => {
            test('detects 10.60516/AU1101', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '10.60516/AU1101', 'IGSN');
            });

            test('detects 10.58052/SSH000SUA', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '10.58052/SSH000SUA', 'IGSN');
            });

            test('detects 10.60510/BGRB5054RX05201', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '10.60510/BGRB5054RX05201', 'IGSN');
            });

            test('detects 10.58108/CSRWA275', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '10.58108/CSRWA275', 'IGSN');
            });

            test('detects 10.58095/MBCR5034RC57001', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '10.58095/MBCR5034RC57001', 'IGSN');
            });
        });

        test.describe('IGSN DOI URL form', () => {
            test('detects https://doi.org/10.60516/AU1101', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://doi.org/10.60516/AU1101', 'IGSN');
            });

            test('detects https://doi.org/10.58052/SSH000SUA', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://doi.org/10.58052/SSH000SUA', 'IGSN');
            });

            test('detects https://doi.org/10.60510/ICDP5054ESYI201', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://doi.org/10.60510/ICDP5054ESYI201', 'IGSN');
            });

            test('detects https://doi.org/10.58108/CSRWA275', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://doi.org/10.58108/CSRWA275', 'IGSN');
            });
        });

        test.describe('IGSN legacy Handle URL', () => {
            test('detects https://igsn.org/10.273/AU1101', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://igsn.org/10.273/AU1101', 'IGSN');
            });

            test('detects https://igsn.org/10.273/BGRB5054RX05201', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'https://igsn.org/10.273/BGRB5054RX05201', 'IGSN');
            });
        });

        test.describe('IGSN URN format', () => {
            test('detects urn:igsn:AU1101', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'urn:igsn:AU1101', 'IGSN');
            });

            test('detects urn:igsn:SSH000SUA', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'urn:igsn:SSH000SUA', 'IGSN');
            });

            test('detects urn:igsn:ICDP5054ESYI201', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'urn:igsn:ICDP5054ESYI201', 'IGSN');
            });

            test('detects urn:igsn:GFZ000001ABC', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'urn:igsn:GFZ000001ABC', 'IGSN');
            });
        });

        test.describe('IGSN case-insensitive handling', () => {
            test('detects lowercase igsn:au1101', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'igsn:au1101', 'IGSN');
            });

            test('detects lowercase igsn:ssh000sua', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, 'igsn:ssh000sua', 'IGSN');
            });
        });
    });
});
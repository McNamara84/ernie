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
        await page.waitForSelector('[data-slot="accordion-trigger"]');

        // Expand Related Work accordion
        const relatedWorkAccordion = page.locator('[data-slot="accordion-trigger"]', { hasText: /Related Work/i });
        await relatedWorkAccordion.click();
        await page.waitForTimeout(300); // Wait for accordion animation
    });

    /**
     * Helper function to add a related work and verify its identifier type
     */
    async function addRelatedWorkAndVerifyType(
        page: import('@playwright/test').Page,
        identifier: string,
        expectedType: string,
    ) {
        // Enter the identifier
        const identifierInput = page.locator('#related-identifier');
        await identifierInput.fill(identifier);

        // Wait for validation to complete (debounced)
        await page.waitForTimeout(1000);

        // Click the Add button
        const addButton = page.locator('button[aria-label="Add related work"]');
        await addButton.click();

        // Wait for the item to be added
        await page.waitForTimeout(300);

        // Find the last added item's identifier type badge
        // The badge shows the identifier type in the item list
        const items = page.locator('[role="listitem"]');
        const lastItem = items.last();
        const typeBadge = lastItem.locator('.text-xs', { hasText: expectedType });

        await expect(typeBadge).toBeVisible();
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
    });
});
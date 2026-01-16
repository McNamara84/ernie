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
    });
});
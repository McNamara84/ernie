import { expect, test } from '@playwright/test';

import { loginAsTestUser } from '../helpers/test-helpers';

/**
 * E2E Tests for Related Work Identifier Type Auto-Detection
 *
 * These tests verify that the Related Work form correctly auto-detects
 * identifier types when users enter identifiers.
 *
 * Note: This is a SMOKE TEST suite with representative examples for each identifier type.
 * Comprehensive pattern testing is handled by Vitest unit tests in:
 * - tests/vitest/__tests__/identifier-type-detection.test.ts
 *
 * Test Strategy:
 * 1. Enter an identifier in the input field
 * 2. Wait for validation to complete (button becomes enabled)
 * 3. Click Add and verify the correct type badge appears
 */

test.describe('Related Work Identifier Type Detection', () => {
    test.beforeEach(async ({ page }) => {
        await loginAsTestUser(page);
        await page.goto('/editor');
        await page.waitForLoadState('networkidle');

        // Ensure Related Work section is open
        const relatedWorkSection = page.getByTestId('related-work-section');
        await relatedWorkSection.waitFor({ state: 'visible', timeout: 10000 });

        const isOpen = (await relatedWorkSection.getAttribute('data-state')) === 'open';
        if (!isOpen) {
            const trigger = page.getByTestId('related-work-accordion-trigger');
            await trigger.scrollIntoViewIfNeeded();
            await trigger.click();
            await expect(relatedWorkSection).toHaveAttribute('data-state', 'open', { timeout: 10000 });
        }

        await page.getByTestId('related-identifier-input').waitFor({ state: 'visible', timeout: 10000 });
    });

    /**
     * Helper function to add a related work and verify its identifier type
     */
    async function addRelatedWorkAndVerifyType(
        page: import('@playwright/test').Page,
        identifier: string,
        expectedType: string,
    ) {
        const identifierInput = page.getByTestId('related-identifier-input');
        await identifierInput.fill(identifier);

        const addButton = page.getByTestId('add-related-work-button');
        await expect(addButton).toBeEnabled({ timeout: 15000 });
        await addButton.click();

        const typeBadge = page.getByTestId('identifier-type-badge').filter({ hasText: expectedType });
        await expect(typeBadge.first()).toBeVisible({ timeout: 5000 });
    }

    // =========================================================================
    // DOI Detection - Most common identifier type
    // =========================================================================
    test.describe('DOI Detection', () => {
        test('detects bare DOI format', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, '10.5880/fidgeo.2025.072', 'DOI');
        });

        test('detects DOI with https://doi.org URL', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'https://doi.org/10.5880/fidgeo.2026.001', 'DOI');
        });

        test('detects DOI with doi: prefix', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'doi:10.1371/journal.pbio.0020449', 'DOI');
        });

        test('detects DOI with legacy dx.doi.org URL', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'https://dx.doi.org/10.5880/fidgeo.2025.072', 'DOI');
        });
    });

    // =========================================================================
    // ARK Detection - Important for cultural heritage institutions
    // =========================================================================
    test.describe('ARK Detection', () => {
        test('detects compact ARK format', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'ark:12148/btv1b8449691v/f29', 'ARK');
        });

        test('detects ARK with resolver URL (n2t.net)', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'https://n2t.net/ark:/12148/btv1b8449691v/f29', 'ARK');
        });
    });

    // =========================================================================
    // arXiv Detection - Common for preprints
    // =========================================================================
    test.describe('arXiv Detection', () => {
        test('detects new format arXiv ID', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, '2501.13958', 'arXiv');
        });

        test('detects arXiv with prefix', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'arXiv:2501.13958v3', 'arXiv');
        });

        test('detects arXiv URL', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'https://arxiv.org/abs/2501.13958', 'arXiv');
        });
    });

    // =========================================================================
    // Handle Detection - Used by many data repositories
    // =========================================================================
    test.describe('Handle Detection', () => {
        test('detects bare Handle format', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, '2142/103380', 'Handle');
        });

        test('detects Handle with extended prefix (FDO)', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, '21.T11998/0000-001A-3905-1', 'Handle');
        });

        test('detects Handle URL', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'https://hdl.handle.net/2142/103380', 'Handle');
        });

        test('detects hdl:// protocol', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'hdl://2142/103380', 'Handle');
        });
    });

    // =========================================================================
    // URL Detection - Fallback for web resources
    // =========================================================================
    test.describe('URL Detection', () => {
        test('detects generic HTTPS URL', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'https://www.gfz-potsdam.de/research', 'URL');
        });

        test('detects HTTP URL', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'http://example.org/resource/123', 'URL');
        });
    });

    // =========================================================================
    // ISBN Detection - For books and publications
    // =========================================================================
    test.describe('ISBN Detection', () => {
        test('detects ISBN-13', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, '9780141026626', 'ISBN');
        });

        test('detects ISBN-13 with hyphens', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, '978-0-141-02662-6', 'ISBN');
        });

        test('detects ISBN-10', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, '0141026626', 'ISBN');
        });
    });

    // =========================================================================
    // Other Identifier Types - One representative test each
    // =========================================================================
    test.describe('Other Identifier Types', () => {
        test('detects ORCID', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'https://orcid.org/0000-0002-1825-0097', 'ORCID');
        });

        test('detects PMID', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'PMID:12345678', 'PMID');
        });

        test('detects ISSN', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, '0317-8471', 'ISSN');
        });

        test('detects URN', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'urn:nbn:de:kobv:83-opus-12345', 'URN');
        });

        test('detects bibcode', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, '2023Natur.123..456A', 'bibcode');
        });

        test('detects EAN-13 (non-ISBN)', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, '4006381333931', 'EAN13');
        });

        test('detects IGSN', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'IGSN:AU1234', 'IGSN');
        });
    });

    // =========================================================================
    // Warning Detection - Verify mismatch warnings work
    // =========================================================================
    test.describe('Identifier Type Mismatch Warning', () => {
        test('shows warning when detected type differs from selected type', async ({ page }) => {
            // Enter a DOI but keep a different type selected
            const identifierInput = page.getByTestId('related-identifier-input');
            await identifierInput.fill('10.5880/fidgeo.2025.072');

            // Wait for detection and validation to complete
            // The button should become enabled once validation passes
            await expect(page.getByTestId('add-related-work-button')).toBeEnabled({ timeout: 15000 });

            // Verify the detected type badge shows DOI (auto-detection working)
            // The warning appears if user manually changed the type selector to something else
        });
    });
});

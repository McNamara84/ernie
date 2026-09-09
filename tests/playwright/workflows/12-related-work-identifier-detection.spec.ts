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
 * 1. Create a complete Related Work card
 * 2. Enter an identifier and leave the input field
 * 3. Verify the detected type and blur-driven enrichment in that card
 */

test.describe('Related Work Identifier Type Detection', () => {
    test.beforeEach(async ({ page }) => {
        await page.route('**/api/v1/related-identifiers/citation-label*', async (route) => {
            const requestUrl = new URL(route.request().url());
            const identifier = requestUrl.searchParams.get('identifier') ?? '';

            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    citation: `Resolved citation for ${identifier}`,
                    identifier,
                    identifier_type: requestUrl.searchParams.get('identifierType'),
                }),
            });
        });

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

        const emptyState = page.getByTestId('related-work-empty-state');
        await expect(emptyState).toBeVisible({ timeout: 10000 });
        await emptyState.getByRole('button', { name: 'Add Related Work' }).click();
        await expect(page.getByTestId('related-work-identifier-input')).toBeVisible({ timeout: 10000 });
        await expect(page.getByLabel('Citation label')).toBeVisible();
    });

    /**
     * Helper function to add a related work and verify its identifier type
     */
    async function addRelatedWorkAndVerifyType(page: import('@playwright/test').Page, identifier: string, expectedType: string) {
        const identifierInput = page.getByTestId('related-work-identifier-input');
        await identifierInput.fill(identifier);
        await identifierInput.press('Tab');

        const typeBadge = page.getByTestId('identifier-type-badge').filter({ hasText: expectedType });
        await expect(typeBadge.first()).toBeVisible({ timeout: 5000 });
    }

    test('keeps the complete empty card when the Related Work section is collapsed and reopened', async ({ page }) => {
        await expect(page.getByRole('button', { name: 'Add Related Work' })).toBeDisabled();

        const trigger = page.getByTestId('related-work-accordion-trigger');
        await trigger.click();
        await expect(page.getByTestId('related-work-identifier-input')).toBeHidden();

        await trigger.click();
        await expect(page.getByTestId('related-work-identifier-input')).toBeVisible();
        await expect(page.getByLabel('Citation label')).toBeVisible();
    });

    test('resolves a DOI citation label and shows its preview after identifier blur', async ({ page }) => {
        const identifierInput = page.getByTestId('related-work-identifier-input');
        await identifierInput.fill('https://doi.org/10.5880/fidgeo.2025.072');
        await identifierInput.press('Tab');

        await expect(identifierInput).toHaveValue('10.5880/fidgeo.2025.072');
        await expect(page.getByLabel('Citation label')).toHaveValue('Resolved citation for 10.5880/fidgeo.2025.072');
        await expect(page.getByRole('link', { name: /10\.5880\/fidgeo\.2025\.072/i })).toHaveAttribute(
            'href',
            'https://doi.org/10.5880/fidgeo.2025.072',
        );
        await expect(page.getByText(/Did you mean .* instead\?/i)).toHaveCount(0);
    });

    test('keeps the long relation-type dropdown stable in a compact viewport', async ({ page }) => {
        await page.setViewportSize({ width: 988, height: 676 });
        const trigger = page.locator('#related-work-0-relation-type');
        await trigger.scrollIntoViewIfNeeded();
        await trigger.click();

        const listbox = page.getByRole('listbox');
        await expect(listbox).toBeVisible();
        await listbox.evaluate(async (element) => {
            await Promise.allSettled(element.getAnimations({ subtree: true }).map((animation) => animation.finished));
        });
        const before = await listbox.boundingBox();
        expect(before).not.toBeNull();

        await listbox.hover();
        await page.mouse.wheel(0, 900);
        const after = await listbox.boundingBox();
        expect(after).not.toBeNull();
        const maximumSubpixelShift = 4;
        expect(Math.abs((after?.x ?? 0) - (before?.x ?? 0))).toBeLessThan(maximumSubpixelShift);
        expect(Math.abs((after?.y ?? 0) - (before?.y ?? 0))).toBeLessThan(maximumSubpixelShift);
        expect((after?.y ?? 0) + (after?.height ?? 0)).toBeLessThanOrEqual(676);

        const lastRelationOption = page.getByRole('option').last();
        await lastRelationOption.scrollIntoViewIfNeeded();
        const relationLabel = (await lastRelationOption.textContent())?.trim();
        expect(relationLabel).toBeTruthy();
        await lastRelationOption.click();
        await expect(trigger).toContainText(relationLabel ?? '');

        const resourceTypeTrigger = page.getByTestId('resource-type-select');
        await resourceTypeTrigger.scrollIntoViewIfNeeded();
        await resourceTypeTrigger.click();

        const resourceTypeListbox = page.getByRole('listbox');
        await expect(resourceTypeListbox).toBeVisible();
        await resourceTypeListbox.evaluate(async (element) => {
            await Promise.allSettled(element.getAnimations({ subtree: true }).map((animation) => animation.finished));
        });
        const resourceTypeBounds = await resourceTypeListbox.boundingBox();
        expect(resourceTypeBounds).not.toBeNull();
        expect((resourceTypeBounds?.y ?? 0) + (resourceTypeBounds?.height ?? 0)).toBeLessThanOrEqual(676);

        const lastResourceTypeOption = page.getByRole('option').last();
        await lastResourceTypeOption.scrollIntoViewIfNeeded();
        const resourceTypeLabel = (await lastResourceTypeOption.textContent())?.trim();
        expect(resourceTypeLabel).toBeTruthy();
        await lastResourceTypeOption.click();
        await expect(resourceTypeTrigger).toContainText(resourceTypeLabel ?? '');
    });

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
        test('detects PMID', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'PMID:12345678', 'PMID');
        });

        test('detects EISSN (standard ISSN format)', async ({ page }) => {
            // Note: Standard ISSN format (XXXX-XXXX) is detected as EISSN
            await addRelatedWorkAndVerifyType(page, '0317-8471', 'EISSN');
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
});

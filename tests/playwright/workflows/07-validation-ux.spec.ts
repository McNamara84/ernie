import { expect } from '@playwright/test';

import { test } from '../fixtures/authenticated';
import { DataCiteFormPage } from '../helpers/page-objects/DataCiteFormPage';

/**
 * E2E Tests for DataCite Form Validation UX
 *
 * Tests cover the complete validation system including:
 * - Inline field validation (errors, warnings, success)
 * - Section status badges (accordion headers)
 * - Save button tooltip (missing required fields)
 * - Auto-scroll to first invalid section
 * - Form submission flow with validation
 * Session setup is shared per worker; every scenario keeps its own context.
 * Observable validation, dropdown, and accordion states replace fixed pauses.
 */

test.describe('DataCite Form Validation UX', () => {
    let formPage: DataCiteFormPage;

    test.beforeEach(async ({ page }) => {
        // Initialize page object
        formPage = new DataCiteFormPage(page);

        // Navigate to editor
        await formPage.goto();
        await formPage.waitForFormLoad();
    });

    test.describe('Accordion Bulk Controls', () => {
        test('keeps Resource Information visible while collapsing and expanding the other field groups', async () => {
            await expect(formPage.resourceInfoSection).toBeVisible();
            await expect(formPage.yearInput).toBeVisible();
            await expect(formPage.resourceInfoSection.locator('[data-slot="accordion-trigger"]')).toHaveCount(0);

            if ((await formPage.collapseAllButtons.count()) > 0) {
                await formPage.collapseAllButtons.first().click();
            }

            await expect(formPage.resourceInfoSection).toBeVisible();
            await expect(formPage.yearInput).toBeVisible();
            await expect(formPage.authorsAccordion).toHaveAttribute('aria-expanded', 'false');
            await expect(formPage.fundingAccordion).toHaveAttribute('aria-expanded', 'false');
            await expect(formPage.expandAllButtons.first()).toBeVisible();

            await formPage.expandAllButtons.first().click();

            await expect(formPage.resourceInfoSection).toBeVisible();
            await expect(formPage.authorsAccordion).toHaveAttribute('aria-expanded', 'true');
            await expect(formPage.fundingAccordion).toHaveAttribute('aria-expanded', 'true');
            await expect(formPage.collapseAllButtons.first()).toBeVisible();
        });
    });

    test.describe('Inline Field Validation', () => {
        test('shows error for invalid year (out of range)', async () => {
            // Fill with invalid year (too early)
            await formPage.yearInput.fill('1899');
            await formPage.yearInput.blur();

            // Should show error styling
            await formPage.expectValidationError(formPage.yearInput);

            // Should have error message
            const messages = await formPage.getFieldValidationMessages(formPage.yearInput);
            expect(messages.length).toBeGreaterThan(0);
            expect(messages.some((msg) => msg.includes('1900') || msg.includes('range'))).toBeTruthy();
        });

        test('shows success for valid year', async () => {
            // Verify the invalid-to-valid transition instead of accepting an untouched field.
            await formPage.yearInput.fill('1800');
            await formPage.yearInput.blur();
            await formPage.expectValidationError(formPage.yearInput);
            await formPage.yearInput.fill('2024');
            await formPage.yearInput.blur();

            // Should show success styling
            await formPage.expectValidationSuccess(formPage.yearInput);
        });

        test('validates DOI format on blur', async ({ page }) => {
            const waitForValidation = (doi: string) => page.waitForResponse((response) =>
                new URL(response.url()).pathname === '/api/v1/doi/validate'
                && response.request().method() === 'POST'
                && response.request().postDataJSON()?.doi === doi,
            );

            // Fill with invalid DOI format
            const invalidValidation = waitForValidation('not-a-doi');
            await formPage.doiInput.fill('not-a-doi');
            await formPage.doiInput.blur();

            // expectValidationError waits for aria-invalid="true" with auto-retry
            await formPage.expectValidationError(formPage.doiInput);

            // Local format feedback precedes the asynchronous availability check.
            // Synchronize with its real response before editing the disabled field.
            const invalidResponse = await invalidValidation;
            expect(invalidResponse.status()).toBe(422);
            expect(await invalidResponse.json()).toMatchObject({ is_valid_format: false, exists: false });
            await expect(formPage.doiInput).toBeEnabled();

            // Clear and fill with valid DOI format
            const validValidation = waitForValidation('10.82433/test-dataset-2024');
            await formPage.doiInput.clear();
            await formPage.doiInput.fill('10.82433/test-dataset-2024');
            await formPage.doiInput.blur();

            const validResponse = await validValidation;
            expect(validResponse.status()).toBe(200);
            expect(await validResponse.json()).toMatchObject({ is_valid_format: true, exists: false });
            await expect(formPage.doiInput).toBeEnabled();

            // expectValidationSuccess waits for aria-invalid to NOT be "true"
            await formPage.expectValidationSuccess(formPage.doiInput);
        });

        test('limits version input to 50 characters and accepts DataCite-style values', async () => {
            const maxLengthVersion = '1234567890'.repeat(5);
            const overlongVersion = `${maxLengthVersion}1`;

            // Browser input is capped at 50 characters via maxlength.
            await formPage.versionInput.fill(overlongVersion);
            await formPage.versionInput.blur();

            await expect(formPage.versionInput).toHaveValue(maxLengthVersion);
            await formPage.expectValidationSuccess(formPage.versionInput);

            // Valid version: DataCite-style major.minor value
            await formPage.versionInput.clear();
            await formPage.versionInput.fill('1.0');
            await formPage.versionInput.blur();

            await formPage.expectValidationSuccess(formPage.versionInput);
        });

        test('shows None as a placeholder while keeping a missing version input empty', async () => {
            await expect(formPage.versionInput).toHaveValue('');
            await expect(formPage.versionInput).toHaveAttribute('placeholder', 'None');

            const placeholderIsShown = await formPage.versionInput.evaluate((input: HTMLInputElement) => input.matches(':placeholder-shown'));
            expect(placeholderIsShown).toBe(true);

            await formPage.versionInput.fill('2.1');

            await expect(formPage.versionInput).toHaveValue('2.1');
            expect(await formPage.versionInput.evaluate((input: HTMLInputElement) => input.matches(':placeholder-shown'))).toBe(false);
        });

        test('submits null for a missing version and the entered version string in draft save requests', async ({ page }) => {
            const submittedVersions: unknown[] = [];
            const saveDraftButton = page.getByTestId('save-draft-button');

            await page.route(
                (url) => url.pathname === '/editor/resources/draft',
                async (route) => {
                    const payload = route.request().postDataJSON() as { version?: unknown };
                    submittedVersions.push(payload.version);

                    await route.fulfill({
                        status: 422,
                        contentType: 'application/json',
                        body: JSON.stringify({ message: 'Request intercepted by the version payload test.' }),
                    });
                },
            );

            await formPage.mainTitleInput.fill('Version payload regression test');
            await expect(formPage.versionInput).toHaveValue('');
            await expect(saveDraftButton).toBeEnabled();

            await saveDraftButton.click();
            await expect.poll(() => submittedVersions.length).toBe(1);
            expect(submittedVersions[0]).toBeNull();

            await expect(saveDraftButton).toBeEnabled();
            await formPage.versionInput.fill('2.1');
            await saveDraftButton.click();
            await expect.poll(() => submittedVersions.length).toBe(2);
            expect(submittedVersions[1]).toBe('2.1');
        });

        test('validates main title length', async () => {
            // Too short (empty)
            await formPage.mainTitleInput.fill('');
            await formPage.mainTitleInput.blur();

            await formPage.expectValidationError(formPage.mainTitleInput);

            // Valid length
            await formPage.mainTitleInput.fill('Valid Dataset Title');
            await formPage.mainTitleInput.blur();

            await formPage.expectValidationSuccess(formPage.mainTitleInput);
        });

        test('accepts short abstracts and enforces only the maximum length', async () => {
            await formPage.expandAccordion(formPage.descriptionsAccordion);

            const shortText = 'This is too short';
            await formPage.abstractTextarea.fill(shortText);
            await formPage.abstractTextarea.blur();

            await expect(formPage.abstractCharacterCount).toContainText(String(shortText.length));
            await expect(formPage.abstractCharacterCount).toContainText('of 17,500');
            await formPage.expectValidationSuccess(formPage.abstractTextarea);

            const overlongText = 'x'.repeat(17_501);
            await formPage.abstractTextarea.fill(overlongText);
            await formPage.abstractTextarea.blur();

            await expect(formPage.abstractCharacterCount).toContainText('17,501');
            await formPage.expectValidationError(formPage.abstractTextarea);
        });
    });

    test.describe('Accordion Status Badges', () => {
        test('shows invalid badge when Resource Info section has missing required fields', async () => {
            // Clear any pre-filled data
            await formPage.clearAllFields();

            // Resource Info should show invalid (yellow warning) due to missing fields
            await formPage.expectSectionStatus(formPage.resourceInfoSection, 'Section incomplete or has errors');
        });

        test('updates badge to valid when all required fields are filled', async () => {
            await formPage.fillResourceInfo('Complete Dataset Title');

            // Badge should update to valid (green checkmark)
            await formPage.expectSectionStatus(formPage.resourceInfoSection, 'Section complete');
        });

        test('shows invalid badge for Licenses section without primary license', async () => {
            await formPage.clearAllFields();

            await formPage.expectSectionStatus(formPage.licensesAccordion, 'Section incomplete or has errors');
        });

        test('shows optional-empty badge for Contributors section', async () => {
            // Contributors are optional, so badge should be gray circle
            await formPage.expectSectionStatus(formPage.contributorsAccordion, 'Optional section');
        });

        test('badges update reactively when form data changes', async () => {
            await formPage.clearAllFields();

            // Initially invalid
            await formPage.expectSectionStatus(formPage.descriptionsAccordion, 'Section incomplete or has errors');

            // Fill abstract to make it valid
            await formPage.expandAccordion(formPage.descriptionsAccordion);
            await formPage.abstractTextarea.fill(
                'This is a comprehensive abstract that meets all validation requirements with more than fifty characters.',
            );
            await formPage.abstractTextarea.blur();

            // Badge should update to valid
            await formPage.expectSectionStatus(formPage.descriptionsAccordion, 'Section complete');
        });
    });

    test.describe('Save Button Tooltip', () => {
        test('shows enabled Save button even when required fields are missing', async () => {
            await formPage.clearAllFields();

            // Save button should always be enabled (Issue #538)
            const isDisabled = await formPage.isSaveButtonDisabled();
            expect(isDisabled).toBeFalsy();
        });

        test('displays validation error list when clicking Save with missing required fields', async () => {
            await formPage.clearAllFields();

            // Click Save — should show validation errors in ValidationAlert
            const alertText = await formPage.clickSaveAndWaitForValidationAlert();

            // Should mention required fields
            expect(alertText.toLowerCase()).toContain('required');

            // Should list specific missing fields
            expect(alertText).toContain('Main Title');
            expect(alertText).toContain('Year');
            expect(alertText).toContain('Resource Type');
        });
    });

    test.describe('Auto-Scroll to Validation Errors', () => {
        test('scrolls to first invalid section on submit attempt', async ({ page }) => {
            await formPage.clearAllFields();

            // Collapse all accordions first
            await formPage.collapseAccordion(formPage.licensesAccordion);
            await formPage.collapseAccordion(formPage.authorsAccordion);
            await formPage.collapseAccordion(formPage.descriptionsAccordion);

            // Scroll to bottom of page
            await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));

            // Click Save to trigger auto-scroll to first invalid section
            await formPage.clickSaveAndWaitForValidationAlert();

            // Resource Info should be the first invalid section (verify via badge)
            await formPage.expectSectionStatus(formPage.resourceInfoSection, 'Section incomplete or has errors');
            await expect(formPage.resourceInfoSection).toBeInViewport();
            await expect(formPage.yearInput).toBeVisible();
        });

        test('opens correct accordion section when navigating to errors', async ({ page }) => {
            await formPage.fillResourceInfo('Test Dataset');

            await formPage.collapseAccordion(formPage.licensesAccordion);
            await formPage.clickSaveAndWaitForValidationAlert();
            await page.getByTestId('error-group-licenses-rights').getByRole('button').first().click();
            await expect(formPage.licensesAccordion).toHaveAttribute('aria-expanded', 'true');
            await expect(formPage.primaryLicenseSelect).toBeVisible();
            await formPage.expectSectionStatus(formPage.licensesAccordion, 'Section incomplete or has errors');
        });
    });

    test.describe('Complete Form Submission Flow', () => {
        test('shows validation errors when submitting with missing required fields', async () => {
            await formPage.clearAllFields();

            // Save button should be enabled (Issue #538)
            const isDisabled = await formPage.isSaveButtonDisabled();
            expect(isDisabled).toBeFalsy();

            // Click Save — should show validation error list instead of submitting
            const alertText = await formPage.clickSaveAndWaitForValidationAlert();
            expect(alertText.toLowerCase()).toContain('required');
        });

        test('allows submission when all validations pass', async ({ page }) => {
            await page.route((url) => url.pathname === '/editor/resources', async (route) => {
                await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ message: 'Validated test submission.' }) });
            });
            await formPage.fillAllRequiredFields();
            const [submitted] = await Promise.all([
                page.waitForRequest((request) => request.method() === 'POST' && new URL(request.url()).pathname === '/editor/resources'),
                formPage.clickSave(),
            ]);
            const payload = submitted.postDataJSON();
            expect(payload).toMatchObject({ year: 2024, accessLevel: 'open', authors: [expect.objectContaining({ lastName: 'Testauthor' })] });
            expect(payload.titles).toContainEqual(expect.objectContaining({ title: 'Test Dataset for Validation E2E' }));
            expect(payload.datacenter_id).toBeGreaterThan(0);
            expect(payload.resourceType).toBeGreaterThan(0);
            expect(payload.licenses).toHaveLength(1);
            await expect(page.getByTestId('global-validation-alert')).not.toBeVisible();
        });

        test('shows validation feedback across multiple sections simultaneously', async () => {
            await formPage.clearAllFields();

            // Multiple sections should show invalid badges
            await formPage.expectSectionStatus(formPage.resourceInfoSection, 'Section incomplete or has errors');
            await formPage.expectSectionStatus(formPage.licensesAccordion, 'Section incomplete or has errors');
            await formPage.expectSectionStatus(formPage.descriptionsAccordion, 'Section incomplete or has errors');

            await formPage.fillResourceInfo('Complete Title');

            // Resource Info should now be valid
            await formPage.expectSectionStatus(formPage.resourceInfoSection, 'Section complete');

            // Others should still be invalid
            await formPage.expectSectionStatus(formPage.licensesAccordion, 'Section incomplete or has errors');
        });
    });

    test.describe('Validation Accessibility', () => {
        test('status badges have proper ARIA labels', async () => {
            // Check that badges have accessible labels
            const resourceInfoBadge = formPage.resourceInfoSection.locator('svg[aria-label]');
            const ariaLabel = await resourceInfoBadge.getAttribute('aria-label');

            expect(ariaLabel).toBeTruthy();
            expect(ariaLabel).toMatch(/complete|incomplete|optional/i);
        });

        test('validation messages are associated with form fields', async () => {
            // Fill with invalid data
            await formPage.yearInput.fill('1800');
            await formPage.yearInput.blur();

            // Check that validation message appears near the field
            const messages = await formPage.getFieldValidationMessages(formPage.yearInput);
            expect(messages.length).toBeGreaterThan(0);
        });

        test('form can be navigated with keyboard', async ({ page }) => {
            // Focus main title input directly (more reliable than blind tabbing)
            await formPage.mainTitleInput.focus();
            await expect(formPage.mainTitleInput).toBeFocused();

            // Type in focused field
            await page.keyboard.type('Keyboard Navigation Test');
            await expect(formPage.mainTitleInput).toHaveValue('Keyboard Navigation Test');

            // Blur by tabbing away
            await page.keyboard.press('Tab');
            await expect(formPage.mainTitleInput).not.toBeFocused();
            await expect(formPage.mainTitleInput).toHaveValue('Keyboard Navigation Test');
        });
    });
});

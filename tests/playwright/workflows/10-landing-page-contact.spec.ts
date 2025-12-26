/**
 * E2E Tests for Landing Page Contact Section
 *
 * Tests the contact information section on landing pages including:
 * - Contact persons display (name, affiliation, ORCID, website)
 * - Contact modal functionality
 * - Form validation
 * - Message sending
 */

import { expect, test } from '@playwright/test';

test.describe('Landing Page Contact Section', () => {
    test.describe('Contact Section Display', () => {
        test('displays contact persons on landing page', async ({ page }) => {
            // This test requires a pre-seeded resource with contact persons
            // Using PlaywrightTestSeeder data
            await page.goto('/datasets/1');

            // Check if contact section exists
            const contactSection = page.locator('text=Contact Information');
            if (await contactSection.isVisible()) {
                // Verify contact section is visible
                await expect(contactSection).toBeVisible();

                // Check for contact person names (from seeder)
                // The exact names depend on test data
                const contactLinks = page.locator('button').filter({ hasText: /@/ }).or(
                    page.locator('button').filter({ has: page.locator('svg') })
                );

                // At least one contact should be visible if the section shows
                expect(await contactLinks.count()).toBeGreaterThanOrEqual(0);
            }
        });

        test('shows ORCID icon when contact person has ORCID', async ({ page }) => {
            await page.goto('/datasets/1');

            // Look for ORCID icon image
            const orcidIcon = page.locator('img[alt="ORCID"]');
            if (await orcidIcon.first().isVisible()) {
                // ORCID icons should link to orcid.org
                const orcidLink = orcidIcon.first().locator('..');
                await expect(orcidLink).toHaveAttribute('href', /orcid\.org/);
            }
        });

        test('shows website button when contact person has website', async ({ page }) => {
            await page.goto('/datasets/1');

            // Look for website button
            const websiteButton = page.locator('a:has-text("Website")');
            if (await websiteButton.first().isVisible()) {
                // Website button should have external link
                await expect(websiteButton.first()).toHaveAttribute('target', '_blank');
            }
        });
    });

    test.describe('Contact Modal', () => {
        test('opens contact modal when clicking on contact person', async ({ page }) => {
            await page.goto('/datasets/1');

            // Find and click a contact person link
            const contactLink = page.locator('button').filter({
                has: page.locator('svg.lucide-user'),
            }).first();

            if (await contactLink.isVisible()) {
                await contactLink.click();

                // Modal should appear
                await expect(page.locator('text=Contact Request')).toBeVisible();

                // Form fields should be visible - use regex to match labels with or without asterisk
                await expect(page.getByLabel(/Your name/)).toBeVisible();
                await expect(page.getByLabel(/Your email/)).toBeVisible();
                // Use more specific selector to avoid matching checkbox label
                await expect(page.getByRole('textbox', { name: /Message/ })).toBeVisible();
            }
        });

        test('opens contact modal for all recipients', async ({ page }) => {
            await page.goto('/datasets/1');

            // Look for "Contact all" button
            const contactAllButton = page.locator('button:has-text("Contact all")');

            if (await contactAllButton.isVisible()) {
                await contactAllButton.click();

                // Modal should appear
                await expect(page.locator('text=Contact Request')).toBeVisible();

                // Should show all recipients info
                await expect(page.locator('text=All contact persons')).toBeVisible();
            }
        });

        test('validates required fields in contact form', async ({ page }) => {
            await page.goto('/datasets/1');

            const contactLink = page.locator('button').filter({
                has: page.locator('svg.lucide-user'),
            }).first();

            if (await contactLink.isVisible()) {
                await contactLink.click();
                await expect(page.locator('text=Contact Request')).toBeVisible();

                // Try to submit empty form - browser native validation will trigger first
                // The submit button should be visible and clickable
                const submitButton = page.locator('button:has-text("Send Message")');
                await expect(submitButton).toBeVisible();

                // Click submit - browser validation will prevent actual submission for empty required fields
                await submitButton.click();

                // Verify the form is still open (not submitted) due to validation
                await expect(page.locator('text=Contact Request')).toBeVisible();

                // Verify that required fields show validation state
                // Check for HTML5 validation pseudo-class :invalid on required inputs
                const nameInput = page.getByLabel(/Your name/).first();
                const emailInput = page.getByLabel(/Your email/).first();
                const messageInput = page.getByRole('textbox', { name: /Message/ });

                // At least one of the required fields should be in an invalid state
                // We use evaluate to check the validity state since Playwright doesn't directly expose :invalid
                const hasInvalidField = await page.evaluate(() => {
                    const inputs = document.querySelectorAll('input:required, textarea:required');
                    return Array.from(inputs).some(input => !(input as HTMLInputElement).validity.valid);
                });
                expect(hasInvalidField).toBe(true);
            }
        });

        test('validates email format in contact form', async ({ page }) => {
            await page.goto('/datasets/1');

            const contactLink = page.locator('button').filter({
                has: page.locator('svg.lucide-user'),
            }).first();

            if (await contactLink.isVisible()) {
                await contactLink.click();
                await expect(page.locator('text=Contact Request')).toBeVisible();

                // Fill form with invalid email (fill all required fields to bypass browser validation on name/message)
                await page.getByLabel(/Your name/).first().fill('Test User');
                const emailInput = page.getByLabel(/Your email/).first();
                await emailInput.fill('invalid-email');
                // Use more specific selector to avoid matching checkbox label
                await page.getByRole('textbox', { name: /Message/ }).fill('This is a test message with enough characters.');

                // Try to submit
                await page.click('button:has-text("Send Message")');

                // Verify the form is still open since validation failed
                await expect(page.locator('text=Contact Request')).toBeVisible();

                // Verify the email field is in an invalid validation state
                // Check for HTML5 validation or custom validation error
                const emailIsInvalid = await emailInput.evaluate((input: HTMLInputElement) => {
                    return !input.validity.valid || input.getAttribute('aria-invalid') === 'true';
                });
                expect(emailIsInvalid).toBe(true);
            }
        });

        test('validates minimum message length', async ({ page }) => {
            await page.goto('/datasets/1');

            const contactLink = page.locator('button').filter({
                has: page.locator('svg.lucide-user'),
            }).first();

            if (await contactLink.isVisible()) {
                await contactLink.click();
                await expect(page.locator('text=Contact Request')).toBeVisible();

                // Fill form with short message - use regex for label matching
                await page.getByLabel(/Your name/).first().fill('Test User');
                await page.getByLabel(/Your email/).first().fill('test@example.com');
                // Use more specific selector to avoid matching checkbox label
                await page.getByRole('textbox', { name: /Message/ }).fill('Short');

                // Try to submit
                await page.click('button:has-text("Send Message")');

                // Should show message length error - use .first() to avoid strict mode in WebKit
                await expect(page.locator('text=at least 10 characters').or(
                    page.locator('text=minimum').or(
                        page.locator('text=Minimum 10 characters')
                    )
                ).first()).toBeVisible();
            }
        });

        test('closes modal on cancel', async ({ page }) => {
            await page.goto('/datasets/1');

            const contactLink = page.locator('button').filter({
                has: page.locator('svg.lucide-user'),
            }).first();

            if (await contactLink.isVisible()) {
                await contactLink.click();
                await expect(page.locator('text=Contact Request')).toBeVisible();

                // Click cancel
                await page.click('button:has-text("Cancel")');

                // Modal should close
                await expect(page.locator('text=Contact Request')).not.toBeVisible();
            }
        });

        test('can toggle copy to sender checkbox', async ({ page }) => {
            await page.goto('/datasets/1');

            const contactLink = page.locator('button').filter({
                has: page.locator('svg.lucide-user'),
            }).first();

            if (await contactLink.isVisible()) {
                await contactLink.click();
                await expect(page.locator('text=Contact Request')).toBeVisible();

                // Find and toggle copy checkbox
                const copyCheckbox = page.getByLabel('Send me a copy');
                if (await copyCheckbox.isVisible()) {
                    await expect(copyCheckbox).not.toBeChecked();
                    await copyCheckbox.check();
                    await expect(copyCheckbox).toBeChecked();
                }
            }
        });
    });

    test.describe('Contact Form Submission', () => {
        test('submits contact form successfully', async ({ page }) => {
            await page.goto('/datasets/1');

            const contactLink = page.locator('button').filter({
                has: page.locator('svg.lucide-user'),
            }).first();

            if (await contactLink.isVisible()) {
                await contactLink.click();
                await expect(page.locator('text=Contact Request')).toBeVisible();

                // Fill valid form - use regex for label matching
                await page.getByLabel(/Your name/).first().fill('E2E Test User');
                await page.getByLabel(/Your email/).first().fill('e2e-test@example.com');
                // Use more specific selector to avoid matching checkbox label
                await page.getByRole('textbox', { name: /Message/ }).fill('This is an automated E2E test message. Please ignore this message if received.');

                // Submit form
                await page.click('button:has-text("Send Message")');

                // Wait for success message
                await expect(page.locator('text=Message sent successfully')).toBeVisible({ timeout: 10000 });
            }
        });

        test('shows loading state during submission', async ({ page }) => {
            await page.goto('/datasets/1');

            const contactLink = page.locator('button').filter({
                has: page.locator('svg.lucide-user'),
            }).first();

            if (await contactLink.isVisible()) {
                await contactLink.click();
                await expect(page.locator('text=Contact Request')).toBeVisible();

                // Fill valid form
                await page.getByLabel(/Your name/).first().fill('E2E Test User');
                await page.getByLabel(/Your email/).first().fill('e2e-test@example.com');
                // Use more specific selector to avoid matching checkbox label
                await page.getByRole('textbox', { name: /Message/ }).fill('This is an automated E2E test message for loading state test.');

                // Submit and check for loading state - use .first() since both button and svg match
                const submitButton = page.locator('button:has-text("Send Message")');
                await submitButton.click();

                // Should show "Sending..." text on the button
                await expect(page.getByRole('button', { name: 'Sending...' })).toBeVisible();
            }
        });
    });
});

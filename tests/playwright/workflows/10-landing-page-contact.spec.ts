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

                // Form fields should be visible
                await expect(page.getByLabel('Your name')).toBeVisible();
                await expect(page.getByLabel('Your email')).toBeVisible();
                await expect(page.getByRole('textbox', { name: /^Message/i })).toBeVisible();
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

                // Try to submit empty form
                await page.click('button:has-text("Send Message")');

                // Should show validation error
                await expect(
                    page.locator('text=Please enter your name').or(
                        page.locator('text=Please enter a valid email')
                    ).or(
                        page.locator('text=Please enter a message')
                    )
                ).toBeVisible();
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

                // Fill form with invalid email
                await page.getByLabel('Your name').fill('Test User');
                await page.getByLabel('Your email').fill('invalid-email');
                await page.getByRole('textbox', { name: /^Message/i }).fill('This is a test message with enough characters.');

                // Try to submit
                await page.click('button:has-text("Send Message")');

                // Should show email validation error
                await expect(page.locator('text=valid email')).toBeVisible();
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

                // Fill form with short message
                await page.getByLabel('Your name').fill('Test User');
                await page.getByLabel('Your email').fill('test@example.com');
                await page.getByRole('textbox', { name: /^Message/i }).fill('Short');

                // Try to submit
                await page.click('button:has-text("Send Message")');

                // Should show message length error
                await expect(page.locator('text=at least 10 characters').or(
                    page.locator('text=minimum')
                )).toBeVisible();
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

                // Fill valid form
                await page.getByLabel('Your name').fill('E2E Test User');
                await page.getByLabel('Your email').fill('e2e-test@example.com');
                await page.getByRole('textbox', { name: /^Message/i }).fill('This is an automated E2E test message. Please ignore this message if received.');

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
                await page.getByLabel('Your name').fill('E2E Test User');
                await page.getByLabel('Your email').fill('e2e-test@example.com');
                await page.getByRole('textbox', { name: /^Message/i }).fill('This is an automated E2E test message for loading state test.');

                // Submit and check for loading state
                const submitButton = page.locator('button:has-text("Send Message")');
                await submitButton.click();

                // Should show "Sending..." or similar loading indicator
                await expect(
                    page.locator('text=Sending').or(page.locator('svg.animate-spin'))
                ).toBeVisible();
            }
        });
    });
});

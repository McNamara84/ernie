import { expect, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';
import { LoginPage } from '../helpers/page-objects';

/**
 * Bug #4: User Creation 500 Error
 *
 * When an Admin or Group Leader tries to create a new user under /users,
 * a 500 error occurs instead of a confirmation message.
 *
 * Console errors observed:
 * - "Tabs is changing from uncontrolled to controlled"
 * - "users:1 Failed to load resource: the server responded with a status of 500 ()"
 */

test.describe('Bug #4: User Creation 500 Error', () => {
    test.beforeEach(async ({ page }) => {
        // Login using page object (matches other working tests)
        const loginPage = new LoginPage(page);
        await loginPage.goto();
        await loginPage.login(TEST_USER_EMAIL, TEST_USER_PASSWORD);
        await page.waitForURL(/\/dashboard/, { timeout: 30000 });
    });

    test('Admin can navigate to /users page', async ({ page }) => {
        // Navigate to users page
        await page.goto('/users');

        // Should load without error
        await expect(page.getByRole('heading', { name: /User Management/i })).toBeVisible({ timeout: 10000 });

        // Check that the Add User button is visible for admin
        await expect(page.getByRole('button', { name: /Add User/i })).toBeVisible();
    });

    test('Admin can open Add User dialog', async ({ page }) => {
        await page.goto('/users');
        await page.waitForLoadState('networkidle');

        // Click Add User button
        await page.getByRole('button', { name: /Add User/i }).click();

        // Dialog should open
        await expect(page.getByRole('dialog')).toBeVisible();
        await expect(page.getByText('Add New User')).toBeVisible();

        // Form fields should be visible
        await expect(page.getByLabel('Name')).toBeVisible();
        await expect(page.getByLabel('Email')).toBeVisible();
    });

    test('Admin can create a new user without 500 error', async ({ page }) => {
        await page.goto('/users');
        await page.waitForLoadState('networkidle');

        // Track 500 errors with a boolean flag
        let received500Error = false;
        
        page.on('response', (response) => {
            if (response.status() === 500) {
                received500Error = true;
                console.error(`500 error on: ${response.url()}`);
            }
        });

        // Click Add User button
        await page.getByRole('button', { name: /Add User/i }).click();
        await expect(page.getByRole('dialog')).toBeVisible();

        // Fill in the form with a unique email
        const timestamp = Date.now();
        const testName = `Test User ${timestamp}`;
        const testEmail = `testuser_${timestamp}@example.com`;

        await page.getByLabel('Name').fill(testName);
        await page.getByLabel('Email').fill(testEmail);

        // Submit the form
        await page.getByRole('button', { name: /Create User/i }).click();

        // Wait for response - should NOT get 500 error
        // Either success toast or warning toast should appear
        const toastLocator = page.locator('[data-sonner-toast]').first();
        
        // Wait for some response
        await page.waitForResponse(
            (response) => response.url().includes('/users') && response.request().method() === 'POST',
            { timeout: 10000 }
        ).catch(() => {
            // Response might have already happened
        });

        // Wait for UI to stabilize and check for toast
        await toastLocator.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {
            // Toast may not appear if dialog shows validation error or page reloads
        });

        // Check dialog state after request
        const dialogStillOpen = await page.getByRole('dialog').isVisible();
        
        if (!dialogStillOpen) {
            // Dialog closed = request completed successfully (no 500 error).
            // Success indicators (toast, message) may not be visible due to page reload timing.
            // The key assertion is that no 500 error occurred (checked at the end).
            console.log('Dialog closed after user creation - request completed');
        } else {
            // Dialog still open - check for validation errors vs 500 error
            const validationError = page.locator('.text-destructive');
            const validationErrorCount = await validationError.count();
            const hasValidationError = validationErrorCount > 0 && await validationError.isVisible();
            
            if (!hasValidationError) {
                // No validation error and dialog still open - check for server errors
                const pageContent = await page.content();
                expect(pageContent).not.toContain('500');
                expect(pageContent).not.toContain('Server Error');
            }
            // Validation error is acceptable (email might already exist)
        }
        
        // Final assertion: no 500 errors should have occurred
        expect(received500Error).toBe(false);
    });

    test('Create user request returns proper response (not 500)', async ({ page }) => {
        await page.goto('/users');
        await page.waitForLoadState('networkidle');

        // Click Add User button
        await page.getByRole('button', { name: /Add User/i }).click();
        await expect(page.getByRole('dialog')).toBeVisible();

        // Fill in the form
        const timestamp = Date.now();
        await page.getByLabel('Name').fill(`Bug Test User ${timestamp}`);
        await page.getByLabel('Email').fill(`bugtest_${timestamp}@example.com`);

        // Intercept the POST request
        const responsePromise = page.waitForResponse(
            (response) => response.url().includes('/users') && response.request().method() === 'POST',
            { timeout: 15000 }
        );

        // Submit the form
        await page.getByRole('button', { name: /Create User/i }).click();

        // Wait for and check the response
        const response = await responsePromise;
        const status = response.status();

        // The response should NOT be 500
        // Expected: 302 (redirect on success) or 422 (validation error) or 200 (Inertia response)
        expect(status).not.toBe(500);
        expect([200, 302, 303, 422]).toContain(status);
    });
});

import { expect, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';

/**
 * User Creation Tests
 *
 * Tests for the Add User feature that allows Admins and Group Leaders
 * to create new user accounts from the User Management page.
 */

test.describe('User Creation', () => {
    test.beforeEach(async ({ page }) => {
        // Login as admin/test user
        await page.goto('/login');
        await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
        await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();
        await page.waitForURL(/\/dashboard/, { timeout: 15000 });
    });

    test('admin can see Add User button on user management page', async ({ page }) => {
        await page.goto('/users');

        // Verify the Add User button is visible
        const addUserButton = page.getByRole('button', { name: 'Add User' });
        await expect(addUserButton).toBeVisible();
    });

    test('admin can open Add User dialog', async ({ page }) => {
        await page.goto('/users');

        // Click Add User button
        await page.getByRole('button', { name: 'Add User' }).click();

        // Verify dialog is open
        await expect(page.getByRole('dialog')).toBeVisible();
        await expect(page.getByText('Add New User')).toBeVisible();
        await expect(page.getByText('Create a new user account')).toBeVisible();
        // Verify the role info text mentions Beginner role
        await expect(page.getByText('Role Hierarchy')).toBeVisible();
    });

    test('Add User dialog has name and email fields', async ({ page }) => {
        await page.goto('/users');
        await page.getByRole('button', { name: 'Add User' }).click();

        // Verify form fields are present
        await expect(page.getByLabel('Name')).toBeVisible();
        await expect(page.getByLabel('Email')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Create User' })).toBeVisible();
    });

    test('admin can create a new user', async ({ page }) => {
        await page.goto('/users');

        // Generate unique email and name to avoid conflicts
        const timestamp = Date.now();
        const uniqueEmail = `testuser-${timestamp}@example.com`;
        const userName = `Test User ${timestamp}`;

        // Open dialog
        await page.getByRole('button', { name: 'Add User' }).click();
        await expect(page.getByRole('dialog')).toBeVisible();

        // Fill form
        await page.getByLabel('Name').fill(userName);
        await page.getByLabel('Email').fill(uniqueEmail);

        // Submit
        await page.getByRole('button', { name: 'Create User' }).click();

        // Wait for success - dialog should close and toast should appear
        await expect(page.getByRole('dialog')).not.toBeVisible({ timeout: 10000 });

        // Verify user appears in the table by finding the row with unique email
        const userRow = page.getByRole('row').filter({ hasText: uniqueEmail });
        await expect(userRow).toBeVisible();
        await expect(userRow.getByRole('cell', { name: userName })).toBeVisible();
    });

    test('new user is created with Beginner role', async ({ page }) => {
        await page.goto('/users');

        const uniqueEmail = `beginner-${Date.now()}@example.com`;
        const userName = 'New Beginner User';

        // Create user
        await page.getByRole('button', { name: 'Add User' }).click();
        await page.getByLabel('Name').fill(userName);
        await page.getByLabel('Email').fill(uniqueEmail);
        await page.getByRole('button', { name: 'Create User' }).click();

        // Wait for dialog to close
        await expect(page.getByRole('dialog')).not.toBeVisible({ timeout: 10000 });

        // Find the row with the new user and verify role badge (use locator for the badge element)
        const userRow = page.getByRole('row').filter({ hasText: uniqueEmail });
        await expect(userRow).toBeVisible();
        // The role badge is a span with data-slot="badge" containing exact text "Beginner"
        await expect(userRow.locator('[data-slot="badge"]', { hasText: 'Beginner' })).toBeVisible();
    });

    test('shows validation error for empty name', async ({ page }) => {
        await page.goto('/users');

        await page.getByRole('button', { name: 'Add User' }).click();

        // Only fill email, leave name empty
        await page.getByLabel('Email').fill('valid@example.com');

        // Try to submit - HTML5 validation should prevent submission
        await page.getByRole('button', { name: 'Create User' }).click();

        // Dialog should still be open since form is invalid
        await expect(page.getByRole('dialog')).toBeVisible();
    });

    test('shows validation error for empty email', async ({ page }) => {
        await page.goto('/users');

        await page.getByRole('button', { name: 'Add User' }).click();

        // Only fill name, leave email empty
        await page.getByLabel('Name').fill('Test User');

        // Try to submit - HTML5 validation should prevent submission
        await page.getByRole('button', { name: 'Create User' }).click();

        // Dialog should still be open since form is invalid
        await expect(page.getByRole('dialog')).toBeVisible();
    });

    test('shows validation error for invalid email format', async ({ page }) => {
        await page.goto('/users');

        await page.getByRole('button', { name: 'Add User' }).click();

        // Fill with invalid email
        await page.getByLabel('Name').fill('Test User');
        await page.getByLabel('Email').fill('not-an-email');

        // Try to submit
        await page.getByRole('button', { name: 'Create User' }).click();

        // Dialog should still be open - HTML5 email validation or server validation
        await expect(page.getByRole('dialog')).toBeVisible();
    });

    test('shows validation error for duplicate email', async ({ page }) => {
        await page.goto('/users');

        await page.getByRole('button', { name: 'Add User' }).click();

        // Try to create user with existing email
        await page.getByLabel('Name').fill('Duplicate User');
        await page.getByLabel('Email').fill(TEST_USER_EMAIL); // Existing user's email

        await page.getByRole('button', { name: 'Create User' }).click();

        // Should show error message about duplicate email
        await expect(page.getByText('A user with this email address already exists')).toBeVisible({ timeout: 5000 });
    });

    test('dialog can be closed without creating user', async ({ page }) => {
        await page.goto('/users');

        // Count existing users
        const initialRowCount = await page.getByRole('row').count();

        // Open dialog
        await page.getByRole('button', { name: 'Add User' }).click();
        await expect(page.getByRole('dialog')).toBeVisible();

        // Fill some data
        await page.getByLabel('Name').fill('Should Not Be Created');
        await page.getByLabel('Email').fill('shouldnot@example.com');

        // Close dialog via X button
        await page.getByRole('button', { name: 'Close' }).click();

        // Dialog should be closed
        await expect(page.getByRole('dialog')).not.toBeVisible();

        // User count should remain the same
        const finalRowCount = await page.getByRole('row').count();
        expect(finalRowCount).toBe(initialRowCount);
    });

    test('dialog form resets when reopened', async ({ page }) => {
        await page.goto('/users');

        // Open dialog and fill data
        await page.getByRole('button', { name: 'Add User' }).click();
        await page.getByLabel('Name').fill('Test Name');
        await page.getByLabel('Email').fill('test@example.org');

        // Close dialog
        await page.getByRole('button', { name: 'Close' }).click();
        await expect(page.getByRole('dialog')).not.toBeVisible();

        // Reopen dialog
        await page.getByRole('button', { name: 'Add User' }).click();

        // Fields should be empty
        await expect(page.getByLabel('Name')).toHaveValue('');
        await expect(page.getByLabel('Email')).toHaveValue('');
    });
});

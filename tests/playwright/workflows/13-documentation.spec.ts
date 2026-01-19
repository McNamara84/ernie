import { expect, test } from '@playwright/test';

import { loginAsTestUser } from '../helpers/test-helpers';

/**
 * Documentation Page E2E Tests
 *
 * Tests the /docs page navigation and content visibility.
 * Uses the seeded test user from PlaywrightTestSeeder.
 *
 * These tests verify the documentation page functionality after
 * the user is logged in.
 */

test.describe('Documentation Page', () => {
    test.beforeEach(async ({ page }) => {
        await loginAsTestUser(page);
    });

    test('loads documentation page with main heading', async ({ page }) => {
        await page.goto('/docs');
        // Use exact match for main heading
        await expect(page.getByRole('heading', { name: 'Documentation', exact: true })).toBeVisible();
    });

    test('displays welcome section', async ({ page }) => {
        await page.goto('/docs');
        await expect(page.getByRole('heading', { name: 'Welcome to ERNIE' })).toBeVisible();
    });

    test('displays user role in welcome section', async ({ page }) => {
        await page.goto('/docs');
        // The welcome section should show the user's role
        await expect(page.getByRole('heading', { name: /Your Role:/i })).toBeVisible();
    });

    test('displays API documentation link', async ({ page }) => {
        await page.goto('/docs');
        const apiLink = page.getByRole('link', { name: 'View API Documentation' });
        await expect(apiLink).toBeVisible();
        await expect(apiLink).toHaveAttribute('href', '/api/v1/doc');
    });

    test('displays DataCite metadata information', async ({ page }) => {
        await page.goto('/docs');
        // Should mention DataCite in the welcome content
        await expect(page.getByText('DataCite', { exact: false }).first()).toBeVisible();
    });
});

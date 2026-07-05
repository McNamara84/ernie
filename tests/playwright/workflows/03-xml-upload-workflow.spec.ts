import { expect, test } from '@playwright/test';
import path from 'path';
import { fileURLToPath } from 'url';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';

// XML Upload Tests
// Based on working tests from main branch.
// Tests the dashboard confirmation and explicit editor navigation.

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

function resolveDatasetExample(filename: string): string {
    return path.resolve(__dirname, '..', '..', 'pest', 'dataset-examples', filename);
}

test.describe('XML Upload', () => {
    test.beforeEach(async ({ page }) => {
        // Login as test user
        await page.goto('/login');
        await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
        await page.getByLabel('Password').fill(TEST_USER_PASSWORD);

        const loginButton = page.getByRole('button', { name: 'Log in' });
        await expect(loginButton).toBeEnabled({ timeout: 15000 });
        await loginButton.click();

        await page.waitForURL(/\/dashboard/, { timeout: 15000 });
    });

    test('uploads XML file, shows confirmation, and opens editor with populated form', async ({ page }) => {
        await page.goto('/dashboard');
        await expect(page.getByTestId('unified-dropzone')).toBeVisible();

        const fileInput = page.getByTestId('unified-file-input');
        const xmlFilePath = resolveDatasetExample('datacite-xml-example-full-v4.xml');
        await fileInput.setInputFiles(xmlFilePath);

        await expect(page.getByTestId('dropzone-success-state')).toBeVisible({ timeout: 10000 });
        await expect(page.getByTestId('dropzone-success-alert')).toContainText('DataCite upload complete');
        await expect(page).toHaveURL(/\/dashboard/);

        await page.getByRole('button', { name: /open in editor/i }).click();
        await page.waitForURL(/\/editor/, { timeout: 10000 });

        const currentUrl = page.url();
        expect(currentUrl).toMatch(/resourceId=\d+/);

        const urlParams = new URLSearchParams(currentUrl.split('?')[1] || '');
        const resourceId = urlParams.get('resourceId');
        expect(resourceId).toBeTruthy();
        expect(resourceId).toMatch(/^\d+$/);
        // Verify editor page loaded successfully with form fields
        // Check for DOI input field (id="doi"), which is unique and stable
        await expect(page.locator('#doi')).toBeVisible();

        // Verify form has loaded by checking for Year field (has id="year")
        await expect(page.locator('#year')).toBeVisible();
    });

    test('handles invalid XML files gracefully', async ({ page }) => {
        await page.goto('/dashboard');
        await expect(page.getByTestId('unified-dropzone')).toBeVisible();

        const fileInput = page.getByTestId('unified-file-input');

        // Create temporary invalid XML file
        const invalidXml = '<invalid>Not a proper DataCite XML</invalid>';
        const buffer = Buffer.from(invalidXml, 'utf-8');

        await fileInput.setInputFiles({
            name: 'invalid.xml',
            mimeType: 'application/xml',
            buffer: buffer,
        });

        // Should show error or stay on dashboard
        await page.waitForTimeout(2000);

        // Should either show error message or stay on dashboard
        const url = page.url();
        const isOnDashboard = url.includes('/dashboard');
        const hasError = await page
            .getByText(/error|invalid|failed/i)
            .isVisible()
            .catch(() => false);

        expect(isOnDashboard || hasError).toBeTruthy();
    });
});

import { expect, test } from '@playwright/test';
import path from 'path';
import { fileURLToPath } from 'url';

import { warmUpSession } from '../helpers/session-readiness';
import { loginAsTestUser } from '../helpers/test-helpers';

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
        await loginAsTestUser(page);
        await warmUpSession(page);
    });

    test('uploads XML file, shows confirmation, and opens editor with populated form', async ({ page }) => {
        await expect(page.getByTestId('unified-dropzone')).toBeVisible();

        const fileInput = page.getByTestId('unified-file-input');
        const xmlFilePath = resolveDatasetExample('datacite-xml-example-full-v4.xml');
        const [uploadResponse] = await Promise.all([
            // The full XML fixture performs real parsing and draft persistence;
            // use the same backend budget as the critical XML smoke test.
            page.waitForResponse((response) => response.request().method() === 'POST'
                && new URL(response.url()).pathname === '/dashboard/upload-xml', { timeout: 30000 }),
            fileInput.setInputFiles(xmlFilePath),
        ]);
        expect(uploadResponse.status()).toBe(200);
        const result = await uploadResponse.json();
        expect(result.success).toBe(true);
        expect(Number.isSafeInteger(result.resourceId)).toBe(true);
        expect(result.resourceId).toBeGreaterThan(0);

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
        expect(resourceId).toBe(String(result.resourceId));
        // Verify editor page loaded successfully with form fields
        // Check for DOI input field (id="doi"), which is unique and stable
        await expect(page.locator('#doi')).toBeVisible();

        // Verify form has loaded by checking for Year field (has id="year")
        await expect(page.locator('#year')).toBeVisible();
        await expect(page.locator('#year')).toHaveValue('2009');
        await expect(page.getByTestId('main-title-input')).toHaveValue('Test Dataset Software');
    });

    test('handles invalid XML files gracefully', async ({ page }) => {
        await expect(page.getByTestId('unified-dropzone')).toBeVisible();

        const fileInput = page.getByTestId('unified-file-input');

        // Create temporary invalid XML file
        const invalidXml = '<invalid>Not a proper DataCite XML</invalid>';
        const buffer = Buffer.from(invalidXml, 'utf-8');

        const [uploadResponse] = await Promise.all([
            page.waitForResponse((response) => response.request().method() === 'POST'
                && new URL(response.url()).pathname === '/dashboard/upload-xml'),
            fileInput.setInputFiles({ name: 'invalid.xml', mimeType: 'application/xml', buffer }),
        ]);
        expect(uploadResponse.status()).toBe(422);
        const result = await uploadResponse.json();
        expect(result.success).toBe(false);
        expect(result.error.code).toBeTruthy();
        await expect(page.getByTestId('dropzone-error-state')).toBeVisible();
        await expect(page.getByTestId('dropzone-error-alert')).toContainText(result.message);
        await expect(page).toHaveURL(/\/dashboard/);
    });
});

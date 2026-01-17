import { expect, test } from '@playwright/test';
import path from 'path';
import { fileURLToPath } from 'url';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';

/**
 * Critical E2E Smoke Tests
 * 
 * These tests verify critical end-to-end workflows that require real browser interaction.
 * Simple page load tests are now handled by Pest Browser Tests (tests/pest/Browser/SmokeTest.php).
 * 
 * Tests here focus on:
 * - File uploads (XML)
 * - Complex UI interactions
 * - Multi-step workflows
 */

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

function resolveDatasetExample(filename: string): string {
  return path.resolve(__dirname, '..', '..', 'pest', 'dataset-examples', filename);
}

test.describe('Critical E2E Workflows', () => {
  test('user can upload XML file and access editor form', async ({ page }) => {
    // Login first
    await page.goto('/login');
    await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
    await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL(/\/dashboard/, { timeout: 15000 });
    
    // Go to dashboard and upload XML
    await page.goto('/dashboard');
    await expect(page.getByTestId('unified-dropzone')).toBeVisible();
    
    const fileInput = page.getByTestId('unified-file-input');
    const xmlFilePath = resolveDatasetExample('datacite-xml-example-full-v4.xml');
    await fileInput.setInputFiles(xmlFilePath);
    
    // Verify redirect to editor with session key (session-based workflow)
    // The 30s timeout accounts for XML parsing, session creation, and database operations.
    // In Docker environments, first requests after container start may be slower due to
    // cache warming and container initialization overhead.
    await page.waitForURL(/\/editor/, { timeout: 30000 });
    
    const currentUrl = page.url();
    // With session-based workflow, only xmlSession parameter is passed
    expect(currentUrl).toMatch(/xmlSession=xml_upload_/);
    
    // Verify editor page loaded successfully by checking for DOI field label
    await expect(page.getByText('DOI', { exact: true })).toBeVisible();
  });
});

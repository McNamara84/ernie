import { expect, test } from '@playwright/test';
import path from 'path';
import { fileURLToPath } from 'url';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';

// XML Upload Tests
// Based on working tests from main branch.
// Tests URL parameters, not DOM elements.

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
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL(/\/dashboard/, { timeout: 15000 });
  });

  test('uploads XML file and redirects to editor with populated form', async ({ page }) => {
    await page.goto('/dashboard');
    await expect(page.locator('text=Dropzone for XML files')).toBeVisible();

    const fileInput = page.locator('input[type="file"][accept=".xml"]');
    const xmlFilePath = resolveDatasetExample('datacite-xml-example-full-v4.xml');
    await fileInput.setInputFiles(xmlFilePath);

    // Wait for redirect to editor page
    await page.waitForURL(/\/editor/, { timeout: 10000 });

    const currentUrl = page.url();
    expect(currentUrl).toMatch(/doi=/);
    expect(currentUrl).toMatch(/year=/);

    // Validate URL parameters contain XML data
    const urlParams = new URLSearchParams(currentUrl.split('?')[1] || '');
    
    expect(urlParams.get('doi')).toMatch(/10\.82433/);
    expect(urlParams.get('year')).toBe('2024');
    expect(urlParams.get('resourceType')).toBeTruthy();
    
    const hasTitle = Array.from(urlParams.keys()).some(key => key.includes('titles'));
    expect(hasTitle).toBeTruthy();
  });

  test('handles invalid XML files gracefully', async ({ page }) => {
    await page.goto('/dashboard');

    const fileInput = page.locator('input[type="file"][accept=".xml"]');
    
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
    const hasError = await page.getByText(/error|invalid|failed/i).isVisible().catch(() => false);
    
    expect(isOnDashboard || hasError).toBeTruthy();
  });
});

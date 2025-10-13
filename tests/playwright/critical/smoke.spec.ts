import { expect, test } from '@playwright/test';
import path from 'path';
import { fileURLToPath } from 'url';

import { TEST_USER_EMAIL, TEST_USER_GREETING, TEST_USER_PASSWORD } from '../constants';

/**
 * Critical Smoke Tests
 * 
 * Simple, fast tests to verify core functionality works.
 * Based on working tests from main branch.
 * 
 * Pattern: Dashboard → XML Upload → Curation with URL params
 */

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

function resolveDatasetExample(filename: string): string {
  return path.resolve(__dirname, '..', '..', 'pest', 'dataset-examples', filename);
}

test.describe('Critical Smoke Tests', () => {
  test('user can login and access dashboard', async ({ page }) => {
    // Navigate to login
    await page.goto('/login');
    
    // Perform login
    await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
    await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    
    // Verify redirect to dashboard
    await page.waitForURL(/\/dashboard/, { timeout: 15000 });
    
    // Verify dashboard is accessible
    await expect(page.getByText(TEST_USER_GREETING)).toBeVisible();
  });

  test('user can upload XML file and access curation form', async ({ page }) => {
    // Login first
    await page.goto('/login');
    await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
    await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL(/\/dashboard/, { timeout: 15000 });
    
    // Go to dashboard and upload XML
    await page.goto('/dashboard');
    await expect(page.locator('text=Dropzone for XML files')).toBeVisible();
    
    const fileInput = page.locator('input[type="file"][accept=".xml"]');
    const xmlFilePath = resolveDatasetExample('datacite-example-full-v4.xml');
    await fileInput.setInputFiles(xmlFilePath);
    
    // Verify redirect to curation with URL params
    await page.waitForURL(/\/curation/, { timeout: 10000 });
    
    const currentUrl = page.url();
    expect(currentUrl).toMatch(/doi=/);
    expect(currentUrl).toMatch(/year=/);
  });

  test('navigation between dashboard and settings works', async ({ page }) => {
    // Login
    await page.goto('/login');
    await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
    await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL(/\/dashboard/, { timeout: 15000 });
    
    // Navigate to settings
    await page.goto('/settings');
    await expect(page).toHaveURL(/\/settings/);
    
    // Navigate back to dashboard
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/dashboard/);
  });
});

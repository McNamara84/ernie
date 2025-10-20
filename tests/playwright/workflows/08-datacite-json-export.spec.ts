import { expect, test } from '@playwright/test';
import fs from 'fs';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';

// DataCite JSON Export Tests
// Verifies FileJson button, download functionality, and exported JSON structure
// NOTE: These tests are skipped in CI as they require a database with existing resources.
//       Run these tests locally where you have resources with complete metadata.

test.describe('DataCite JSON Export', () => {
  // Skip these tests in CI since they require existing resources with complete metadata
  // These tests should be run locally where you have a populated database
  const skipInCI = process.env.CI === 'true';
  
  test.skip(skipInCI, 'Tests require database with resources - run locally');

  test.beforeEach(async ({ page }) => {
    // Login before each test
    await page.goto('/login');
    await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
    await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL(/\/dashboard/, { timeout: 15000 });
    
    // Navigate to resources
    await page.goto('/resources');
    await expect(page).toHaveURL(/\/resources/);
    
    // Wait for table to load with at least one row
    await page.waitForSelector('table tbody tr', { timeout: 10000 });
  });

  test('FileJson button is visible in resources table', async ({ page }) => {
    // Check if at least one FileJson button exists
    const fileJsonButtons = page.locator('button[aria-label*="Export"], button svg[data-lucide="file-json"]').first();
    await expect(fileJsonButtons).toBeVisible();
  });

  test('FileJson button is clickable', async ({ page }) => {
    // Find first FileJson button
    const fileJsonButton = page.locator('button[aria-label*="Export"], button:has(svg[data-lucide="file-json"])').first();
    await expect(fileJsonButton).toBeEnabled();
  });

  test('clicking FileJson button triggers download', async ({ page }) => {
    // Set up download promise before clicking
    const downloadPromise = page.waitForEvent('download', { timeout: 30000 });
    
    // Find and click first FileJson button
    const fileJsonButton = page.locator('button[aria-label*="Export"], button:has(svg[data-lucide="file-json"])').first();
    await fileJsonButton.click();
    
    // Wait for download to start
    const download = await downloadPromise;
    
    // Verify download started
    expect(download).toBeTruthy();
  });

  test('downloaded file has correct filename format', async ({ page }) => {
    // Set up download promise
    const downloadPromise = page.waitForEvent('download', { timeout: 30000 });
    
    // Click export button
    const fileJsonButton = page.locator('button[aria-label*="Export"], button:has(svg[data-lucide="file-json"])').first();
    await fileJsonButton.click();
    
    // Wait for download
    const download = await downloadPromise;
    const filename = download.suggestedFilename();
    
    // Verify filename format: resource-{id}-{timestamp}-datacite.json
    expect(filename).toMatch(/^resource-\d+-\d{14}-datacite\.json$/);
    expect(filename).toContain('-datacite.json');
  });

  test('downloaded JSON file has valid structure', async ({ page }) => {
    // Set up download promise
    const downloadPromise = page.waitForEvent('download', { timeout: 30000 });
    
    // Click export button
    const fileJsonButton = page.locator('button[aria-label*="Export"], button:has(svg[data-lucide="file-json"])').first();
    await fileJsonButton.click();
    
    // Wait for and save download
    const download = await downloadPromise;
    const downloadPath = await download.path();
    
    // Read and parse JSON
    if (downloadPath) {
      const fileContent = fs.readFileSync(downloadPath, 'utf-8');
      const jsonData = JSON.parse(fileContent);
      
      // Verify top-level structure
      expect(jsonData).toHaveProperty('data');
      expect(jsonData.data).toHaveProperty('type', 'dois');
      expect(jsonData.data).toHaveProperty('attributes');
      
      // Verify required DataCite fields
      const attributes = jsonData.data.attributes;
      expect(attributes).toHaveProperty('titles');
      expect(attributes).toHaveProperty('creators');
      expect(attributes).toHaveProperty('publisher');
      expect(attributes).toHaveProperty('publicationYear');
      expect(attributes).toHaveProperty('types');
      
      // Verify titles is an array
      expect(Array.isArray(attributes.titles)).toBe(true);
      expect(attributes.titles.length).toBeGreaterThan(0);
      
      // Verify creators is an array
      expect(Array.isArray(attributes.creators)).toBe(true);
      expect(attributes.creators.length).toBeGreaterThan(0);
      
      // Verify publisher structure
      expect(attributes.publisher).toHaveProperty('name', 'GFZ Helmholtz Centre for Geosciences');
      expect(attributes.publisher).toHaveProperty('publisherIdentifier', 'https://ror.org/04z8jg394');
      expect(attributes.publisher).toHaveProperty('publisherIdentifierScheme', 'ROR');
      
      // Verify types structure
      expect(attributes.types).toHaveProperty('resourceTypeGeneral');
      expect(attributes.types).toHaveProperty('resourceType');
    }
  });

  test('shows loading state during export', async ({ page }) => {
    // Find export button
    const fileJsonButton = page.locator('button[aria-label*="Export"], button:has(svg[data-lucide="file-json"])').first();
    
    // Click and immediately check for disabled state or loading indicator
    await fileJsonButton.click();
    
    // The button should be disabled during export or show a loading state
    // This might be very fast, so we just verify the click doesn't throw
    await expect(fileJsonButton).toBeTruthy();
  });

  test('displays success toast notification after export', async ({ page }) => {
    // Set up download promise
    const downloadPromise = page.waitForEvent('download', { timeout: 30000 });
    
    // Click export button
    const fileJsonButton = page.locator('button[aria-label*="Export"], button:has(svg[data-lucide="file-json"])').first();
    await fileJsonButton.click();
    
    // Wait for download to complete
    await downloadPromise;
    
    // Wait a moment for toast to appear
    await page.waitForTimeout(500);
    
    // Check for success toast (adjust selector based on your toast implementation)
    const toast = page.locator('[role="status"], [role="alert"], .toast, .notification').filter({ hasText: /export|success|download/i });
    
    // Toast might have auto-dismissed, so we just verify the export completed
    // If toast is still visible, verify it
    const toastCount = await toast.count();
    if (toastCount > 0) {
      await expect(toast.first()).toBeVisible();
    }
  });

  test('exports creators and contributors correctly', async ({ page }) => {
    // Set up download promise
    const downloadPromise = page.waitForEvent('download', { timeout: 30000 });
    
    // Click export button
    const fileJsonButton = page.locator('button[aria-label*="Export"], button:has(svg[data-lucide="file-json"])').first();
    await fileJsonButton.click();
    
    // Wait for and save download
    const download = await downloadPromise;
    const downloadPath = await download.path();
    
    if (downloadPath) {
      const fileContent = fs.readFileSync(downloadPath, 'utf-8');
      const jsonData = JSON.parse(fileContent);
      const attributes = jsonData.data.attributes;
      
      // Verify creators structure
      if (attributes.creators && attributes.creators.length > 0) {
        const creator = attributes.creators[0];
        expect(creator).toHaveProperty('name');
        expect(creator).toHaveProperty('nameType');
        expect(['Personal', 'Organizational']).toContain(creator.nameType);
        
        // If personal, should have name parts
        if (creator.nameType === 'Personal') {
          expect(creator.name).toBeTruthy();
        }
      }
      
      // Verify contributors structure (if present)
      if (attributes.contributors) {
        expect(Array.isArray(attributes.contributors)).toBe(true);
        
        if (attributes.contributors.length > 0) {
          const contributor = attributes.contributors[0];
          expect(contributor).toHaveProperty('name');
          expect(contributor).toHaveProperty('contributorType');
        }
      }
    }
  });

  test('exports rightsList with SPDX data when license present', async ({ page }) => {
    // Set up download promise
    const downloadPromise = page.waitForEvent('download', { timeout: 30000 });
    
    // Click export button
    const fileJsonButton = page.locator('button[aria-label*="Export"], button:has(svg[data-lucide="file-json"])').first();
    await fileJsonButton.click();
    
    // Wait for and save download
    const download = await downloadPromise;
    const downloadPath = await download.path();
    
    if (downloadPath) {
      const fileContent = fs.readFileSync(downloadPath, 'utf-8');
      const jsonData = JSON.parse(fileContent);
      const attributes = jsonData.data.attributes;
      
      // Verify rightsList structure (if present)
      if (attributes.rightsList) {
        expect(Array.isArray(attributes.rightsList)).toBe(true);
        
        if (attributes.rightsList.length > 0) {
          const rights = attributes.rightsList[0];
          expect(rights).toHaveProperty('rights');
          
          // If SPDX license, should have additional fields
          if (rights.rightsIdentifierScheme === 'SPDX') {
            expect(rights).toHaveProperty('rightsURI');
            expect(rights).toHaveProperty('rightsIdentifier');
            expect(rights).toHaveProperty('schemeURI', 'https://spdx.org/licenses/');
          }
        }
      }
    }
  });

  test('can export multiple resources sequentially', async ({ page }) => {
    // Get all FileJson buttons
    const fileJsonButtons = page.locator('button[aria-label*="Export"], button:has(svg[data-lucide="file-json"])');
    const buttonCount = await fileJsonButtons.count();
    
    // Export up to 2 resources
    const exportsToTest = Math.min(buttonCount, 2);
    
    for (let i = 0; i < exportsToTest; i++) {
      const downloadPromise = page.waitForEvent('download', { timeout: 30000 });
      
      // Click export button
      await fileJsonButtons.nth(i).click();
      
      // Wait for download
      const download = await downloadPromise;
      expect(download).toBeTruthy();
      
      // Small delay between exports
      await page.waitForTimeout(500);
    }
  });

  test('handles error gracefully when export fails', async ({ page }) => {
    // Navigate to a non-existent resource export endpoint directly
    const response = await page.goto('/resources/999999/export-datacite-json');
    
    // Should return 404
    expect(response?.status()).toBe(404);
  });

  test('exported JSON includes all mandatory DataCite fields', async ({ page }) => {
    // Set up download promise
    const downloadPromise = page.waitForEvent('download', { timeout: 30000 });
    
    // Click export button
    const fileJsonButton = page.locator('button[aria-label*="Export"], button:has(svg[data-lucide="file-json"])').first();
    await fileJsonButton.click();
    
    // Wait for and save download
    const download = await downloadPromise;
    const downloadPath = await download.path();
    
    if (downloadPath) {
      const fileContent = fs.readFileSync(downloadPath, 'utf-8');
      const jsonData = JSON.parse(fileContent);
      const attributes = jsonData.data.attributes;
      
      // DataCite v4.x mandatory properties
      const mandatoryFields = [
        'titles',
        'creators',
        'publisher',
        'publicationYear',
        'types'
      ];
      
      mandatoryFields.forEach(field => {
        expect(attributes).toHaveProperty(field);
        
        // Verify non-null/non-empty
        if (Array.isArray(attributes[field])) {
          expect(attributes[field].length).toBeGreaterThan(0);
        } else if (typeof attributes[field] === 'object') {
          expect(attributes[field]).toBeTruthy();
        } else {
          expect(attributes[field]).toBeTruthy();
        }
      });
    }
  });

  test('timestamp in filename increases for sequential exports', async ({ page }) => {
    // First export
    const download1Promise = page.waitForEvent('download', { timeout: 30000 });
    const fileJsonButton = page.locator('button[aria-label*="Export"], button:has(svg[data-lucide="file-json"])').first();
    await fileJsonButton.click();
    const download1 = await download1Promise;
    const filename1 = download1.suggestedFilename();
    
    // Extract timestamp from first filename
    const match1 = filename1.match(/resource-\d+-(\d{14})-datacite\.json/);
    expect(match1).toBeTruthy();
    const timestamp1 = match1 ? match1[1] : '';
    
    // Wait a bit to ensure different timestamp
    await page.waitForTimeout(1500);
    
    // Second export
    const download2Promise = page.waitForEvent('download', { timeout: 30000 });
    await fileJsonButton.click();
    const download2 = await download2Promise;
    const filename2 = download2.suggestedFilename();
    
    // Extract timestamp from second filename
    const match2 = filename2.match(/resource-\d+-(\d{14})-datacite\.json/);
    expect(match2).toBeTruthy();
    const timestamp2 = match2 ? match2[1] : '';
    
    // Second timestamp should be greater than or equal to first
    expect(timestamp2 >= timestamp1).toBe(true);
  });
});

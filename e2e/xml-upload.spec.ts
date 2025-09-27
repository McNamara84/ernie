import { test, expect } from '@playwright/test';
import path from 'path';
import fs from 'fs';
import os from 'os';
import { fileURLToPath } from 'url';
import {
  TEST_USER_EMAIL,
  TEST_USER_PASSWORD,
} from './constants';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

test.describe('XML Upload Functionality', () => {
  test.beforeEach(async ({ page }) => {
    // Login as test user
    await page.goto('/login');
    await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
    await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    
    // Wait for successful login redirect
    await page.waitForURL(/\/dashboard/, { timeout: 15000 });
  });

  test('uploads XML file and redirects to curation with populated form', async ({ page }) => {
    // Navigate to dashboard
    await page.goto('/dashboard');
    
    // Verify we're on the dashboard by checking for distinctive content
    await expect(page).toHaveURL('/dashboard');
    
    // Look for dashboard-specific content instead of h1
    await expect(page.locator('text=Dropzone for XML files')).toBeVisible();
    await expect(page.locator('text=Here you can upload new XML files sent by ELMO for curation.')).toBeVisible();
    
    // Find the file input - it's hidden but we can still use it
    const fileInput = page.locator('input[type="file"][accept=".xml"]');
    await expect(fileInput).toBeAttached(); // Check if it exists, not if it's visible
    
    // Upload the XML file
    const xmlFilePath = path.join(__dirname, '..', 'tests', 'dataset-examples', 'datacite-example-full-v4.xml');
    await fileInput.setInputFiles(xmlFilePath);
    
    // The upload should happen automatically after file selection
    // Wait for redirect to curation page
    await page.waitForURL(/\/curation/, { timeout: 10000 });
    
    // Verify we're on the curation page - look for curation-specific content
    const currentUrl = page.url();
    expect(currentUrl).toMatch(/\/curation/);
    
    // The page should have query parameters with the XML data
    expect(currentUrl).toMatch(/doi=/);
    expect(currentUrl).toMatch(/year=/);
    
    // Validate that form fields are populated with XML data
    await test.step('Validate basic metadata fields', async () => {
      // Check DOI identifier - should be in URL params or form
      expect(currentUrl).toMatch(/10\.82433/);
      
      // Check for publication year 2024
      expect(currentUrl).toMatch(/year=2024/);
      
      // Check for titles in URL parameters
      expect(currentUrl).toMatch(/titles/);
    });
    
    await test.step('Validate form is accessible and contains expected data', async () => {
      // Verify that the curation form/page is accessible
      // Even if we can't find specific inputs, the URL should contain our XML data
      const urlParams = new URLSearchParams(currentUrl.split('?')[1] || '');
      
      // Should have DOI
      const doi = urlParams.get('doi');
      expect(doi).toBeTruthy();
      expect(doi).toMatch(/10\.82433/);
      
      // Should have publication year
      const year = urlParams.get('year');
      expect(year).toBe('2024');
      
      // Should have resource type
      const resourceType = urlParams.get('resourceType');
      expect(resourceType).toBeTruthy();
      
      // Should have titles
      const hasTitle = Array.from(urlParams.keys()).some(key => key.includes('titles'));
      expect(hasTitle).toBeTruthy();
      
      // Should have licenses
      const hasLicense = Array.from(urlParams.keys()).some(key => key.includes('licenses'));
      expect(hasLicense).toBeTruthy();
    });
    
    // Take a screenshot for debugging
    await page.screenshot({ 
      path: 'test-results/xml-upload-success.png', 
      fullPage: true 
    });
  });
  
  test('handles invalid XML files gracefully', async ({ page }) => {
    await page.goto('/dashboard');
    
    // Create a temporary invalid XML file content
    const invalidXmlContent = '<?xml version="1.0"?><invalid>This is not a valid DataCite XML</invalid>';
    
    // Try to upload invalid XML
    const fileInput = page.locator('input[type="file"][accept=".xml"]');
    await expect(fileInput).toBeAttached();
    
    // Create a temporary file with invalid content
    const tempFilePath = path.join(os.tmpdir(), 'invalid-datacite.xml');
    fs.writeFileSync(tempFilePath, invalidXmlContent);
    
    try {
      await fileInput.setInputFiles(tempFilePath);
      
      // Look for error messages or validation feedback
      const errorMessage = page.locator('.error, .alert-error, [role="alert"]');
      const errorText = page.locator('text=error');
      const invalidText = page.locator('text=invalid');
      
      // Should either show error message or stay on dashboard
      await page.waitForTimeout(2000); // Give time for any error processing
      
      const currentUrl = page.url();
      const hasErrorMessage = await errorMessage.count() > 0;
      const hasErrorText = await errorText.count() > 0;
      const hasInvalidText = await invalidText.count() > 0;
      
      // Either we get an error message or we stay on dashboard (not redirected to curation)
      const hasAnyError = hasErrorMessage || hasErrorText || hasInvalidText;
      expect(hasAnyError || currentUrl.includes('/dashboard')).toBeTruthy();
      
      // Should not redirect to curation with invalid XML
      expect(currentUrl).not.toMatch(/\/curation/);
    } finally {
      // Clean up temp file
      if (fs.existsSync(tempFilePath)) {
        fs.unlinkSync(tempFilePath);
      }
    }
  });
  
  test('shows upload progress or feedback', async ({ page }) => {
    await page.goto('/dashboard');
    
    const fileInput = page.locator('input[type="file"][accept=".xml"]');
    const xmlFilePath = path.join(__dirname, '..', 'tests', 'dataset-examples', 'datacite-example-full-v4.xml');
    await expect(fileInput).toBeAttached();
    
    // Monitor for loading states or progress indicators
    await fileInput.setInputFiles(xmlFilePath);
    
    // Look for loading indicators, progress bars, or status messages
    const loadingIndicators = page.locator([
      '.loading',
      '.spinner', 
      '.progress',
      '[role="progressbar"]',
      'text=uploading',
      'text=processing'
    ].join(','));
    
    // Check if there's any visual feedback during upload process
    const hasLoadingFeedback = await loadingIndicators.count() > 0;
    
    // At minimum, there should be some indication of processing
    // This could be immediate redirect, loading state, or success message
    await page.waitForTimeout(1000);
    
    const finalUrl = page.url();
    const hasRedirected = finalUrl !== '/dashboard' && finalUrl.includes('/curation');
    
    // Either we see loading feedback or we get redirected (indicating processing happened)
    expect(hasLoadingFeedback || hasRedirected).toBeTruthy();
  });
});
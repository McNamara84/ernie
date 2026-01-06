/**
 * Local Bug Reproduction Test
 * 
 * This test reproduces the bugs found on Stage locally.
 * Run with: npx playwright test --config=playwright.docker.config.ts --headed --grep "BUG 1"
 */

import { test, expect } from '@playwright/test';
import * as path from 'path';
import * as fs from 'fs';

// Test credentials for local Docker environment
const LOCAL_TEST_EMAIL = 'test@example.com';
const LOCAL_TEST_PASSWORD = 'password';

// Helper function to resolve dataset example paths (ES Module compatible)
function resolveDatasetExample(filename: string): string {
  const possiblePaths = [
    path.resolve(process.cwd(), 'tests', 'pest', 'dataset-examples', filename),
    path.resolve('tests', 'pest', 'dataset-examples', filename),
  ];
  
  for (const p of possiblePaths) {
    if (fs.existsSync(p)) {
      return p;
    }
  }
  
  // Fallback
  return possiblePaths[0];
}

// Test XML file path
const XML_FILE = resolveDatasetExample('datacite-example-dataset-v4.xml');

test.describe('Bug Reproduction - Local', () => {
  
  test('BUG 1 & 2: Date parsing and GCMD keywords prevent save', async ({ page }) => {
    // Collect console errors
    const consoleErrors: string[] = [];
    page.on('console', msg => {
      if (msg.type() === 'error') {
        consoleErrors.push(msg.text());
      }
    });

    // Step 1: Login
    console.log('Step 1: Logging in...');
    await page.goto('/login');
    await page.waitForLoadState('networkidle');
    
    await page.getByLabel('Email address').fill(LOCAL_TEST_EMAIL);
    await page.getByLabel('Password').fill(LOCAL_TEST_PASSWORD);
    
    await Promise.all([
      page.waitForURL(/\/dashboard/, { timeout: 30000 }),
      page.getByRole('button', { name: 'Log in' }).click(),
    ]);
    console.log('✓ Login successful');

    // Step 2: Upload XML
    console.log('Step 2: Uploading XML file...');
    await page.goto('/dashboard');
    await expect(page.locator('text=Dropzone for XML files')).toBeVisible();
    
    const fileInput = page.locator('input[type="file"][accept=".xml"]');
    await fileInput.setInputFiles(XML_FILE);
    console.log('✓ XML file uploaded');

    // Step 3: Wait for editor
    console.log('Step 3: Waiting for editor...');
    await page.waitForURL(/\/editor/, { timeout: 30000 });
    
    const saveButton = page.getByRole('button', { name: /Save to database/i });
    await expect(saveButton).toBeVisible({ timeout: 30000 });
    console.log('✓ Editor loaded');

    // Step 4: Take screenshot before save
    await page.screenshot({ path: 'test-results/local-before-save.png', fullPage: true });

    // Step 5: Try to save
    console.log('Step 4: Attempting to save...');
    await saveButton.scrollIntoViewIfNeeded();
    await saveButton.click();
    
    // Wait for response
    await page.waitForTimeout(5000);
    
    // Take screenshot after save
    await page.screenshot({ path: 'test-results/local-after-save.png', fullPage: true });

    // Check for errors
    const errorMessages = page.locator('.text-red-500, .text-destructive, [role="alert"], .text-red-600, [data-slot="form-message"]');
    const errorCount = await errorMessages.count();
    
    const currentUrl = page.url();
    console.log(`Current URL after save: ${currentUrl}`);

    if (errorCount > 0) {
      console.log(`\n⚠️ Found ${errorCount} error message(s):`);
      
      // Get all error texts - show all errors for debugging
      const allErrors: string[] = [];
      for (let i = 0; i < errorCount; i++) {
        const errorText = await errorMessages.nth(i).textContent().catch(() => '');
        if (errorText && errorText.length > 2) {
          allErrors.push(errorText.trim());
        }
      }
      
      // Print all unique errors for debugging
      const uniqueErrors = [...new Set(allErrors)];
      console.log('\n--- ALL VALIDATION ERRORS ---');
      uniqueErrors.forEach((err, i) => {
        // Only show first 500 chars of each error to avoid spam
        const truncated = err.length > 500 ? err.substring(0, 500) + '...' : err;
        console.log(`${i + 1}. ${truncated}`);
      });
      console.log('--- END ERRORS ---\n');
      
      // Parse and categorize errors
      const firstErrorFull = allErrors[0] || '';
      
      // Check for date errors
      const dateErrors = firstErrorFull.match(/dates\.\d+\.(startDate|endDate) field must be a valid date/g) || [];
      if (dateErrors.length > 0) {
        console.log(`\n🔴 BUG 1 REPRODUCED: Date parsing errors (${dateErrors.length} errors)`);
        dateErrors.slice(0, 3).forEach(e => console.log(`   - ${e}`));
      }
      
      // Check for GCMD errors
      const gcmdTextErrors = firstErrorFull.match(/gcmdKeywords\.\d+\.text field is required/g) || [];
      const gcmdPathErrors = firstErrorFull.match(/gcmdKeywords\.\d+\.path field must be a string/g) || [];
      if (gcmdTextErrors.length > 0 || gcmdPathErrors.length > 0) {
        console.log(`\n🔴 BUG 2 REPRODUCED: GCMD keyword errors`);
        console.log(`   - ${gcmdTextErrors.length} missing 'text' fields`);
        console.log(`   - ${gcmdPathErrors.length} missing 'path' fields`);
      }
      
      // Save still on editor page?
      if (currentUrl.includes('/editor')) {
        // Check if it's the known date/GCMD bugs or other issues
        const hasDateBug = dateErrors.length > 0;
        const hasGcmdBug = gcmdTextErrors.length > 0 || gcmdPathErrors.length > 0;
        
        if (hasDateBug || hasGcmdBug) {
          console.log('\n❌ SAVE FAILED - Original bugs still present');
        } else {
          console.log('\n⚠️ SAVE FAILED - But date/GCMD bugs appear to be FIXED!');
          console.log('   New validation errors may be due to other missing required fields.');
        }
        
        // Log console errors
        if (consoleErrors.length > 0) {
          console.log('\nBrowser console errors:');
          consoleErrors.forEach(e => console.log(`   - ${e}`));
        }
        
        // This test is meant to REPRODUCE bugs, so we expect it to fail
        // Mark as reproduced
        console.log('\n✅ BUGS SUCCESSFULLY REPRODUCED LOCALLY');
        console.log('   Screenshots saved to test-results/local-*.png');
      }
    } else if (currentUrl.includes('/resources')) {
      console.log('\n✅ Save succeeded - Bugs may have been fixed!');
    }
  });

  test.skip('Debug: Check date parsing in form', async ({ page }) => {
    // This test can be used to debug specific form fields
    // Skip by default, enable when needed
    
    await page.goto('/login');
    await page.getByLabel('Email address').fill(LOCAL_TEST_EMAIL);
    await page.getByLabel('Password').fill(LOCAL_TEST_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL(/\/dashboard/);
    
    // Upload XML
    const fileInput = page.locator('input[type="file"][accept=".xml"]');
    await fileInput.setInputFiles(XML_FILE);
    await page.waitForURL(/\/editor/);
    
    // Open Dates accordion and inspect
    const datesAccordion = page.locator('[data-slot="accordion-trigger"]', { hasText: /^Dates$/i });
    await datesAccordion.click();
    await page.waitForTimeout(500);
    
    // Screenshot the dates section
    await page.screenshot({ path: 'test-results/debug-dates-form.png', fullPage: true });
    
    // Log all date-related inputs
    const dateInputs = page.locator('[data-slot="accordion-content"]').filter({ hasText: /^Dates$/i }).locator('input');
    const count = await dateInputs.count();
    console.log(`Found ${count} inputs in Dates section`);
    
    for (let i = 0; i < count; i++) {
      const input = dateInputs.nth(i);
      const name = await input.getAttribute('name') || 'no-name';
      const value = await input.inputValue();
      const placeholder = await input.getAttribute('placeholder') || '';
      console.log(`  Input ${i}: name="${name}", value="${value}", placeholder="${placeholder}"`);
    }
  });
});

import { expect, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from './constants';

test.describe('Curation - Dates Saving and Loading', () => {
  test.beforeEach(async ({ page }) => {
    // Login
    await page.goto('/login');
    await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
    await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL(/\/dashboard/);
    
    // Navigate to curation page
    await page.goto('/curation');
    await page.waitForLoadState('networkidle');
  });

  test('saves and loads dates correctly', async ({ page }) => {
    await test.step('Fill required fields', async () => {
      // Fill Main Title
      await page.fill('input[id="title-0"]', 'Test Resource with Dates');
      
      // Select Resource Type
      await page.click('button[role="combobox"][aria-label*="Resource Type"]');
      await page.waitForSelector('div[role="option"]');
      await page.click('div[role="option"]:has-text("Dataset")');
      
      // Select Language
      await page.click('button[role="combobox"][aria-label*="Language"]');
      await page.waitForSelector('div[role="option"]');
      await page.click('div[role="option"]:has-text("English")');
      
      // Fill Year
      await page.fill('input[id="year"]', '2024');
      
      // Fill License
      await page.click('button[role="combobox"][aria-label*="License"]');
      await page.waitForSelector('div[role="option"]');
      await page.click('div[role="option"]:has-text("CC0")');
      
      // Fill Author
      await page.fill('input[id="author-0-lastName"]', 'Tester');
    });

    await test.step('Fill dates', async () => {
      // Open Dates accordion
      const datesAccordion = page.locator('button:has-text("Dates")');
      const isExpanded = await datesAccordion.getAttribute('aria-expanded');
      if (isExpanded !== 'true') {
        await datesAccordion.click();
        await page.waitForTimeout(300); // Wait for accordion animation
      }

      // Fill the "Created" date (required)
      const createdStartDate = page.locator('input[id$="-startDate"]').first();
      await createdStartDate.fill('2024-01-15');
      
      // Verify the date type is "Created"
      const dateTypeSelect = page.locator('button[id$="-dateType"]').first();
      const dateTypeText = await dateTypeSelect.textContent();
      expect(dateTypeText).toContain('Created');

      // Add another date
      await page.click('button[aria-label="Add date"]');
      
      // Fill the second date with both start and end dates
      const secondStartDate = page.locator('input[id$="-startDate"]').nth(1);
      const secondEndDate = page.locator('input[id$="-endDate"]').nth(1);
      const secondDateType = page.locator('button[id$="-dateType"]').nth(1);
      
      await secondStartDate.fill('2024-03-01');
      await secondEndDate.fill('2024-03-31');
      
      // Change date type to "Collected"
      await secondDateType.click();
      await page.waitForSelector('div[role="option"]');
      await page.click('div[role="option"]:has-text("Collected")');
    });

    await test.step('Fill abstract (required)', async () => {
      // Open Descriptions accordion
      const descriptionsAccordion = page.locator('button:has-text("Descriptions")');
      const isExpanded = await descriptionsAccordion.getAttribute('aria-expanded');
      if (isExpanded !== 'true') {
        await descriptionsAccordion.click();
        await page.waitForTimeout(300);
      }

      // Add abstract description
      await page.click('button:has-text("Add description")');
      
      // Select "Abstract" type
      const descTypeButton = page.locator('button[id^="description-"][id$="-type"]').first();
      await descTypeButton.click();
      await page.waitForSelector('div[role="option"]');
      await page.click('div[role="option"]:has-text("Abstract")');
      
      // Fill abstract text
      const abstractTextarea = page.locator('textarea[id^="description-"][id$="-value"]').first();
      await abstractTextarea.fill('This is a test abstract for date testing.');
    });

    let resourceId: string | null = null;

    await test.step('Save to database', async () => {
      // Click "Save to database" button
      const saveButton = page.locator('button[type="submit"]:has-text("Save to database")');
      
      // Wait for the button to be enabled
      await expect(saveButton).toBeEnabled({ timeout: 10000 });
      
      // Listen for the response
      const responsePromise = page.waitForResponse(
        response => response.url().includes('/curation/resources') && response.status() === 200
      );
      
      await saveButton.click();
      
      // Wait for successful response
      const response = await responsePromise;
      const responseData = await response.json();
      
      // Extract resource ID from response
      resourceId = responseData.resource?.id?.toString() || null;
      expect(resourceId).not.toBeNull();
      
      // Wait for success dialog
      await expect(page.locator('div[role="dialog"]:has-text("Successfully saved resource")')).toBeVisible({ timeout: 5000 });
      
      // Close the dialog
      await page.click('button:has-text("Close")');
    });

    await test.step('Navigate to resources page and edit the saved resource', async () => {
      // Navigate to resources page
      await page.goto('/resources');
      await page.waitForLoadState('networkidle');
      
      // Find and click edit button for our resource
      const editButton = page.locator(`button[aria-label*="Edit Test Resource with Dates"]`).first();
      await editButton.click();
      
      // Wait for navigation to curation page with query parameters
      await page.waitForURL(/\/curation\?/);
      await page.waitForLoadState('networkidle');
    });

    await test.step('Verify dates are loaded correctly', async () => {
      // Open Dates accordion
      const datesAccordion = page.locator('button:has-text("Dates")');
      const isExpanded = await datesAccordion.getAttribute('aria-expanded');
      if (isExpanded !== 'true') {
        await datesAccordion.click();
        await page.waitForTimeout(300);
      }

      // Verify first date (Created)
      const firstStartDate = page.locator('input[id$="-startDate"]').first();
      const firstStartValue = await firstStartDate.inputValue();
      expect(firstStartValue).toBe('2024-01-15');
      
      const firstDateType = page.locator('button[id$="-dateType"]').first();
      const firstDateTypeText = await firstDateType.textContent();
      expect(firstDateTypeText?.toLowerCase()).toContain('created');

      // Verify second date (Collected)
      const secondStartDate = page.locator('input[id$="-startDate"]').nth(1);
      const secondStartValue = await secondStartDate.inputValue();
      expect(secondStartValue).toBe('2024-03-01');
      
      const secondEndDate = page.locator('input[id$="-endDate"]').nth(1);
      const secondEndValue = await secondEndDate.inputValue();
      expect(secondEndValue).toBe('2024-03-31');
      
      const secondDateType = page.locator('button[id$="-dateType"]').nth(1);
      const secondDateTypeText = await secondDateType.textContent();
      expect(secondDateTypeText?.toLowerCase()).toContain('collected');
    });

    await test.step('Cleanup: Delete the test resource', async () => {
      if (resourceId) {
        await page.goto('/resources');
        await page.waitForLoadState('networkidle');
        
        // Find and click delete button
        const deleteButton = page.locator(`button[aria-label*="Delete Test Resource with Dates"]`).first();
        await deleteButton.click();
        
        // Confirm deletion
        await page.waitForSelector('div[role="dialog"]');
        await page.click('button:has-text("Delete")');
        
        // Wait for deletion to complete
        await page.waitForTimeout(1000);
      }
    });
  });
});

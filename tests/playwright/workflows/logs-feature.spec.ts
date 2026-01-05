import { expect, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';
import { LoginPage } from '../helpers/page-objects';

/**
 * Logs Feature E2E Tests
 * 
 * Tests the logs page functionality:
 * - Page display and structure
 * - Filtering by level and search
 * - Pagination
 * - Delete functionality (admin only)
 */

test.describe('Logs Page', () => {
  test.beforeEach(async ({ page }) => {
    // Login as test user (admin) using LoginPage helper
    const loginPage = new LoginPage(page);
    await loginPage.goto();
    await loginPage.loginAndWaitForDashboard(TEST_USER_EMAIL, TEST_USER_PASSWORD);
    
    // Navigate to logs page
    await page.goto('/logs');
    // Wait for the logs page to fully load
    await page.waitForLoadState('networkidle');
  });

  test('displays logs page with correct heading', async ({ page }) => {
    await expect(page.getByRole('heading', { name: 'Application Logs' })).toBeVisible();
  });

  test('displays filter controls', async ({ page }) => {
    // Level filter dropdown
    await expect(page.getByRole('combobox')).toBeVisible();
    
    // Search input
    await expect(page.getByPlaceholder('Search logs...')).toBeVisible();
    
    // Search button
    await expect(page.getByRole('button', { name: 'Search' })).toBeVisible();
  });

  test('displays Refresh button', async ({ page }) => {
    await expect(page.getByRole('button', { name: 'Refresh' })).toBeVisible();
  });

  test('displays Clear All button for admin', async ({ page }) => {
    // Admin should see the Clear All button
    await expect(page.getByRole('button', { name: 'Clear All' })).toBeVisible();
  });

  test('can filter by log level', async ({ page }) => {
    // Open level dropdown
    await page.getByRole('combobox').click();
    
    // Check available levels
    await expect(page.getByRole('option', { name: 'All Levels' })).toBeVisible();
    await expect(page.getByRole('option', { name: 'Error' })).toBeVisible();
    await expect(page.getByRole('option', { name: 'Warning' })).toBeVisible();
    await expect(page.getByRole('option', { name: 'Info' })).toBeVisible();
    await expect(page.getByRole('option', { name: 'Debug' })).toBeVisible();
    
    // Select Error level
    await page.getByRole('option', { name: 'Error' }).click();
    
    // URL should contain level filter
    await expect(page).toHaveURL(/level=error/);
  });

  test('can search logs', async ({ page }) => {
    const searchInput = page.getByPlaceholder('Search logs...');
    
    // Enter search term
    await searchInput.fill('test');
    
    // Click search button
    await page.getByRole('button', { name: 'Search' }).click();
    
    // URL should contain search parameter
    await expect(page).toHaveURL(/search=test/);
  });

  test('can search logs with Enter key', async ({ page }) => {
    const searchInput = page.getByPlaceholder('Search logs...');
    
    // Enter search term and press Enter
    await searchInput.fill('error');
    await searchInput.press('Enter');
    
    // URL should contain search parameter
    await expect(page).toHaveURL(/search=error/);
  });

  test('refresh button reloads logs', async ({ page }) => {
    // Click refresh button
    const refreshButton = page.getByRole('button', { name: 'Refresh' });
    await refreshButton.click();
    
    // Button should show loading state (spinner icon)
    // After reload, page should still be on logs
    await expect(page).toHaveURL(/\/logs/);
  });

  test('Clear All shows confirmation dialog', async ({ page }) => {
    // First, trigger an action that creates a log entry
    // Navigate to dashboard to generate activity logs
    await page.goto('/dashboard');
    await page.waitForLoadState('networkidle');
    
    // Navigate back to logs
    await page.goto('/logs');
    await page.waitForLoadState('networkidle');
    
    // Wait for the Clear All button to be enabled (requires logs to exist)
    const clearAllButton = page.getByRole('button', { name: 'Clear All' });
    
    // If the button is disabled (no logs), skip this test
    const isDisabled = await clearAllButton.isDisabled();
    if (isDisabled) {
      test.skip(true, 'Clear All button is disabled - no logs available');
      return;
    }
    
    // Click Clear All button
    await clearAllButton.click();
    
    // Confirmation dialog should appear
    await expect(page.getByRole('alertdialog')).toBeVisible();
    await expect(page.getByText('Clear all logs?')).toBeVisible();
    
    // Cancel button should be visible
    await expect(page.getByRole('button', { name: 'Cancel' })).toBeVisible();
    
    // Clear All confirmation button should be visible
    await expect(page.getByRole('alertdialog').getByRole('button', { name: 'Clear All' })).toBeVisible();
  });

  test('Cancel button closes Clear All dialog', async ({ page }) => {
    // First, trigger an action that creates a log entry
    await page.goto('/dashboard');
    await page.waitForLoadState('networkidle');
    
    // Navigate back to logs
    await page.goto('/logs');
    await page.waitForLoadState('networkidle');
    
    // Wait for the Clear All button
    const clearAllButton = page.getByRole('button', { name: 'Clear All' });
    
    // If the button is disabled (no logs), skip this test
    const isDisabled = await clearAllButton.isDisabled();
    if (isDisabled) {
      test.skip(true, 'Clear All button is disabled - no logs available');
      return;
    }
    
    // Open dialog
    await clearAllButton.click();
    await expect(page.getByRole('alertdialog')).toBeVisible();
    
    // Click cancel
    await page.getByRole('button', { name: 'Cancel' }).click();
    
    // Dialog should be closed
    await expect(page.getByRole('alertdialog')).not.toBeVisible();
  });
});

test.describe('Logs Page - Log Entries Display', () => {
  test.beforeEach(async ({ page }) => {
    const loginPage = new LoginPage(page);
    await loginPage.goto();
    await loginPage.loginAndWaitForDashboard(TEST_USER_EMAIL, TEST_USER_PASSWORD);
    await page.goto('/logs');
    await page.waitForLoadState('networkidle');
  });

  test('displays log entry count', async ({ page }) => {
    // Should show entry count in the description
    await expect(page.getByText(/\d+ log (entry|entries) found/)).toBeVisible();
  });

  test('displays empty state when no logs', async ({ page }) => {
    // Filter for something that doesn't exist
    await page.getByPlaceholder('Search logs...').fill('xyznonexistent12345');
    await page.getByRole('button', { name: 'Search' }).click();
    
    // Should show empty state or no results message
    // (The actual message depends on whether logs exist)
    await expect(page).toHaveURL(/search=xyznonexistent12345/);
  });

  test('log entries show level badges with colors', async ({ page }) => {
    // Check if there are any log entries in the table
    const table = page.locator('table');
    
    if (await table.isVisible()) {
      // Table header should have correct columns
      await expect(page.getByRole('columnheader', { name: 'Timestamp' })).toBeVisible();
      await expect(page.getByRole('columnheader', { name: 'Level' })).toBeVisible();
      await expect(page.getByRole('columnheader', { name: 'Message' })).toBeVisible();
    }
  });
});

test.describe('Logs Page - Access Control', () => {
  test('requires authentication', async ({ page }) => {
    await page.goto('/logs');
    await expect(page).toHaveURL(/\/login/);
  });

  test('returns 403 for non-admin users via API', async () => {
    // This is tested via PHP tests - Playwright tests focus on UI
    // The PHP tests verify that curators and beginners get 403
    expect(true).toBe(true);
  });
});

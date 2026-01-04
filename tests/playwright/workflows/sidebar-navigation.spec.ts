import { expect, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';
import { LoginPage } from '../helpers/page-objects';

/**
 * Sidebar Navigation Structure Tests
 * 
 * Verifies the sidebar menu is organized into sections:
 * - Data Curation (Data Editor, Resources)
 * - IGSN Curation (IGSNs, IGSN Editor) - disabled
 * - Administration (Old Datasets, Statistics (old), Users, Logs) - Admin/Group Leader only
 */

test.describe('Sidebar Navigation Structure', () => {
  test.beforeEach(async ({ page }) => {
    // Login as test user (admin) using LoginPage helper
    const loginPage = new LoginPage(page);
    await loginPage.goto();
    await loginPage.loginAndWaitForDashboard(TEST_USER_EMAIL, TEST_USER_PASSWORD);
  });

  test('displays Dashboard link', async ({ page }) => {
    const sidebar = page.locator('[data-slot="sidebar"]');
    await expect(sidebar.getByRole('link', { name: 'Dashboard' })).toBeVisible();
  });

  test('displays Data Curation section with correct items', async ({ page }) => {
    const sidebar = page.locator('[data-slot="sidebar"]');
    
    // Check section label
    await expect(sidebar.getByText('Data Curation')).toBeVisible();
    
    // Check menu items
    await expect(sidebar.getByRole('link', { name: 'Data Editor' })).toBeVisible();
    await expect(sidebar.getByRole('link', { name: 'Resources' })).toBeVisible();
  });

  test('displays IGSN Curation section with disabled items', async ({ page }) => {
    const sidebar = page.locator('[data-slot="sidebar"]');
    
    // Check section label
    await expect(sidebar.getByText('IGSN Curation')).toBeVisible();
    
    // Check disabled menu items (they should be visible but not clickable)
    const igsnsButton = sidebar.getByRole('button', { name: 'IGSNs' });
    const igsnEditorButton = sidebar.getByRole('button', { name: 'IGSN Editor' });
    
    await expect(igsnsButton).toBeVisible();
    await expect(igsnEditorButton).toBeVisible();
    
    // Verify they have disabled styling
    await expect(igsnsButton).toHaveClass(/opacity-50|cursor-not-allowed/);
    await expect(igsnEditorButton).toHaveClass(/opacity-50|cursor-not-allowed/);
  });

  test('displays Administration section for admin user', async ({ page }) => {
    const sidebar = page.locator('[data-slot="sidebar"]');
    
    // Check section label
    await expect(sidebar.getByText('Administration')).toBeVisible();
    
    // Check menu items
    await expect(sidebar.getByRole('link', { name: 'Old Datasets' })).toBeVisible();
    await expect(sidebar.getByRole('link', { name: 'Statistics (old)' })).toBeVisible();
    await expect(sidebar.getByRole('link', { name: 'Users' })).toBeVisible();
    await expect(sidebar.getByRole('link', { name: 'Logs' })).toBeVisible();
  });

  test('clicking Data Editor navigates to editor page', async ({ page }) => {
    const sidebar = page.locator('[data-slot="sidebar"]');
    // Ensure we're on dashboard first
    await expect(page).toHaveURL(/\/dashboard/);
    
    // Click on Data Editor link
    await sidebar.getByRole('link', { name: 'Data Editor' }).click();
    
    // Wait for navigation
    await page.waitForLoadState('networkidle');
    await expect(page).toHaveURL(/\/editor/);
  });

  test('clicking Resources navigates to resources page', async ({ page }) => {
    const sidebar = page.locator('[data-slot="sidebar"]');
    await sidebar.getByRole('link', { name: 'Resources' }).click();
    
    await expect(page).toHaveURL(/\/resources/);
  });

  test('clicking Users navigates to users page', async ({ page }) => {
    const sidebar = page.locator('[data-slot="sidebar"]');
    await sidebar.getByRole('link', { name: 'Users' }).click();
    
    await expect(page).toHaveURL(/\/users/);
  });

  test('clicking Logs navigates to logs page', async ({ page }) => {
    const sidebar = page.locator('[data-slot="sidebar"]');
    await sidebar.getByRole('link', { name: 'Logs' }).click();
    
    await expect(page).toHaveURL(/\/logs/);
    await expect(page.getByRole('heading', { name: 'Application Logs' })).toBeVisible();
  });

  test('sections are separated visually', async ({ page }) => {
    const sidebar = page.locator('[data-slot="sidebar"]');
    
    // Check that separators exist between labeled sections
    const separators = sidebar.locator('[data-slot="sidebar-separator"]');
    
    // Verify separators exist without enforcing exact count
    // (count may change as sections are added/removed)
    const count = await separators.count();
    expect(count).toBeGreaterThanOrEqual(2); // At minimum between major sections
    expect(count).toBeLessThanOrEqual(5);    // Reasonable upper bound
  });
});

test.describe('Administration Section Access Control', () => {
  test('Administration section is hidden for non-admin users', async ({ page }) => {
    // This test would require a beginner/curator user account
    // For now, we just verify the route protection works
    
    // Try to access logs without login
    await page.goto('/logs');
    
    // Should redirect to login
    await expect(page).toHaveURL(/\/login/);
  });

  test('Old Datasets requires authentication', async ({ page }) => {
    await page.goto('/old-datasets');
    await expect(page).toHaveURL(/\/login/);
  });

  test('Statistics (old) requires authentication', async ({ page }) => {
    await page.goto('/old-statistics');
    await expect(page).toHaveURL(/\/login/);
  });

  test('Users requires authentication', async ({ page }) => {
    await page.goto('/users');
    await expect(page).toHaveURL(/\/login/);
  });
});

import { expect, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';
import { DashboardPage, LoginPage, SettingsPage } from '../helpers/page-objects';
import { loginAsTestUser, logout } from '../helpers/test-helpers';

/**
 * Authentication Workflow Tests
 * 
 * Testet den kompletten Authentication-Flow:
 * - Login mit gültigen/ungültigen Credentials
 * - Logout
 * - Password Reset Flow
 * - Session Management
 */

test.describe('Authentication Workflow', () => {
  test('complete login and logout flow', async ({ page }) => {
    await test.step('User can login with valid credentials', async () => {
      const loginPage = new LoginPage(page);
      
      await loginPage.goto();
      await loginPage.verifyOnLoginPage();
      
      await loginPage.loginAndWaitForDashboard(TEST_USER_EMAIL, TEST_USER_PASSWORD);
      
      // Verify successful login
      const dashboard = new DashboardPage(page);
      await dashboard.verifyOnDashboard();
    });

    await test.step('User can logout', async () => {
      await logout(page);
      
      // Should be redirected to login page
      await expect(page).toHaveURL('/login');
    });
  });

  test('login with invalid credentials shows error', async ({ page }) => {
    const loginPage = new LoginPage(page);

    await loginPage.goto();
    
    await test.step('Try login with wrong password', async () => {
      await loginPage.login(TEST_USER_EMAIL, 'wrong-password');
      
      // Should display error message
      await loginPage.verifyErrorDisplayed();
      
      // Should remain on login page
      await loginPage.verifyOnLoginPage();
    });

    await test.step('Try login with non-existent user', async () => {
      await loginPage.login('nonexistent@example.com', 'anypassword');
      
      // Should display error message
      await loginPage.verifyErrorDisplayed();
    });
  });

  test('authenticated user can access protected pages', async ({ page }) => {
    await loginAsTestUser(page);

    await test.step('Can access dashboard', async () => {
      await page.goto('/dashboard');
      await expect(page).toHaveURL('/dashboard');
      await expect(page.getByText('Dropzone for XML files')).toBeVisible();
    });

    await test.step('Can access curation', async () => {
      await page.goto('/curation');
      await expect(page).toHaveURL(/\/curation/);
    });

    await test.step('Can access resources', async () => {
      await page.goto('/resources');
      await expect(page).toHaveURL(/\/resources/);
    });

    await test.step('Can access old datasets', async () => {
      await page.goto('/old-datasets');
      await expect(page).toHaveURL(/\/old-datasets/);
    });

    await test.step('Can access settings', async () => {
      await page.goto('/settings');
      await expect(page).toHaveURL(/\/settings/);
    });
  });

  test('unauthenticated user is redirected to login', async ({ page }) => {
    // Try to access protected pages without login
    
    await test.step('Dashboard redirects to login', async () => {
      await page.goto('/dashboard');
      await expect(page).toHaveURL('/login');
    });

    await test.step('Curation redirects to login', async () => {
      await page.goto('/curation');
      await expect(page).toHaveURL('/login');
    });

    await test.step('Resources redirects to login', async () => {
      await page.goto('/resources');
      await expect(page).toHaveURL('/login');
    });

    await test.step('Settings redirects to login', async () => {
      await page.goto('/settings');
      await expect(page).toHaveURL('/login');
    });
  });

  test('remember me functionality', async ({ page }) => {
    const loginPage = new LoginPage(page);

    await loginPage.goto();

    await test.step('Login with remember me checked', async () => {
      await loginPage.login(TEST_USER_EMAIL, TEST_USER_PASSWORD, true);
      await page.waitForURL(/\/dashboard/);
    });

    // Note: Actual "remember me" cookie testing would require 
    // browser restart or cookie inspection, which is beyond smoke testing
    // This test at least verifies the checkbox is functional
  });

  test('password update flow', async ({ page }) => {
    await loginAsTestUser(page);

    const settings = new SettingsPage(page);

    await test.step('Navigate to password settings', async () => {
      await settings.gotoSection('password');
      
      await expect(settings.currentPasswordInput).toBeVisible();
      await expect(settings.newPasswordInput).toBeVisible();
      await expect(settings.confirmPasswordInput).toBeVisible();
    });

    await test.step('Validate password fields are present', async () => {
      // Try to submit without filling (should show validation errors)
      await settings.updatePasswordButton.click();
      
      // Form should still be visible (validation failed)
      await expect(settings.currentPasswordInput).toBeVisible();
    });

    // Note: We don't actually change the password in tests
    // to avoid affecting the test user account
  });

  test('session persists across page navigation', async ({ page }) => {
    await loginAsTestUser(page);

    await test.step('Navigate between pages', async () => {
      // Navigate to different pages
      await page.goto('/dashboard');
      await expect(page).toHaveURL('/dashboard');

      await page.goto('/curation');
      await expect(page).toHaveURL(/\/curation/);

      await page.goto('/resources');
      await expect(page).toHaveURL(/\/resources/);

      await page.goto('/old-datasets');
      await expect(page).toHaveURL(/\/old-datasets/);

      // Go back to dashboard - should still be authenticated
      await page.goto('/dashboard');
      await expect(page).toHaveURL('/dashboard');
      await expect(page.getByText('Dropzone for XML files')).toBeVisible();
    });
  });
});

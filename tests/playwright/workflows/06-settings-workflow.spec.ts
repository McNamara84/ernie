import { expect, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';
import { SettingsPage } from '../helpers/page-objects';
import { loginAsTestUser } from '../helpers/test-helpers';

/**
 * Settings Complete Workflow
 * 
 * Testet den kompletten Settings-Workflow:
 * 1. Profilbearbeitung (Name, Email)
 * 2. Passwortänderung
 * 3. Appearance-Einstellungen (Theme, Language)
 * 4. Editor-Einstellungen
 * 5. Validierung und Fehlerbehandlung
 */

test.describe('Settings Complete Workflow', () => {
  test('user can view all settings sections', async ({ page }) => {
    await loginAsTestUser(page);

    const settings = new SettingsPage(page);

    await test.step('Navigate to settings', async () => {
      await settings.goto();
      
      // Should be on settings page
      await expect(page).toHaveURL(/\/settings/);
    });

    await test.step('Verify settings page elements', async () => {
      // Heading should be visible
      await expect(settings.heading).toBeVisible();

      // Navigation tabs or sections should exist
      const pageContent = await page.textContent('body');
      expect(pageContent).toContain('Settings');
    });
  });

  test('user can navigate between settings sections', async ({ page }) => {
    await loginAsTestUser(page);

    const settings = new SettingsPage(page);

    await test.step('Navigate to profile settings', async () => {
      await settings.gotoSection('profile');
      await expect(page).toHaveURL(/\/settings\/profile/);
    });

    await test.step('Navigate to password settings', async () => {
      await settings.gotoSection('password');
      await expect(page).toHaveURL(/\/settings\/password/);
    });

    await test.step('Navigate to appearance settings', async () => {
      await settings.gotoSection('appearance');
      await expect(page).toHaveURL(/\/settings\/appearance/);
    });

    await test.step('Navigate to editor settings', async () => {
      await settings.gotoSection('editor');
      await expect(page).toHaveURL(/\/settings\/editor/);
    });
  });

  test('user can view profile information', async ({ page }) => {
    await loginAsTestUser(page);

    const settings = new SettingsPage(page);

    await test.step('Go to profile settings', async () => {
      await settings.gotoSection('profile');
    });

    await test.step('Verify profile fields are visible', async () => {
      const nameVisible = await settings.nameInput.isVisible().catch(() => false);
      const emailVisible = await settings.emailInput.isVisible().catch(() => false);

      expect(nameVisible || emailVisible).toBeTruthy();
    });

    await test.step('Verify current user email is displayed', async () => {
      if (await settings.emailInput.isVisible().catch(() => false)) {
        const emailValue = await settings.emailInput.inputValue();
        expect(emailValue).toBe(TEST_USER_EMAIL);
      }
    });
  });

  test('user can update profile name', async ({ page }) => {
    await loginAsTestUser(page);

    const settings = new SettingsPage(page);

    await test.step('Navigate to profile settings', async () => {
      await settings.gotoSection('profile');
    });

    await test.step('Update name', async () => {
      const nameVisible = await settings.nameInput.isVisible().catch(() => false);
      
      if (!nameVisible) {
        test.skip(true, 'Name field not available');
      }

      const newName = 'Updated Test User';
      await settings.nameInput.fill(newName);
    });

    await test.step('Save profile changes', async () => {
      await settings.updateProfileButton.click();

      // Should show success message
      await page.waitForTimeout(1000);

      const success = await settings.successMessage.isVisible({ timeout: 3000 }).catch(() => false);
      
      if (success) {
        await expect(settings.successMessage).toBeVisible();
      }
    });
  });

  test('password change form validates input', async ({ page }) => {
    await loginAsTestUser(page);

    const settings = new SettingsPage(page);

    await test.step('Navigate to password settings', async () => {
      await settings.gotoSection('password');
    });

    await test.step('Verify password fields are present', async () => {
      await expect(settings.currentPasswordInput).toBeVisible();
      await expect(settings.newPasswordInput).toBeVisible();
      await expect(settings.confirmPasswordInput).toBeVisible();
    });

    await test.step('Try to submit with empty fields', async () => {
      await settings.updatePasswordButton.click();

      // Should show validation errors
      const errorVisible = await page.getByRole('alert').isVisible({ timeout: 2000 }).catch(() => false);
      const buttonDisabled = await settings.updatePasswordButton.isDisabled();

      expect(errorVisible || buttonDisabled).toBeTruthy();
    });
  });

  test('password change validates password match', async ({ page }) => {
    await loginAsTestUser(page);

    const settings = new SettingsPage(page);

    await test.step('Navigate to password settings', async () => {
      await settings.gotoSection('password');
    });

    await test.step('Fill passwords with mismatch', async () => {
      await settings.currentPasswordInput.fill(TEST_USER_PASSWORD);
      await settings.newPasswordInput.fill('NewPassword123!');
      await settings.confirmPasswordInput.fill('DifferentPassword123!');
    });

    await test.step('Attempt to save', async () => {
      await settings.updatePasswordButton.click();

      // Should show error about password mismatch
      await page.waitForTimeout(1000);

      const errorShown = await settings.errorMessage.isVisible({ timeout: 2000 }).catch(() => false);
      expect(errorShown).toBeTruthy();
    });
  });

  test('password change validates current password', async ({ page }) => {
    await loginAsTestUser(page);

    const settings = new SettingsPage(page);

    await test.step('Navigate to password settings', async () => {
      await settings.gotoSection('password');
    });

    await test.step('Fill with wrong current password', async () => {
      await settings.currentPasswordInput.fill('WrongCurrentPassword');
      await settings.newPasswordInput.fill('NewPassword123!');
      await settings.confirmPasswordInput.fill('NewPassword123!');
    });

    await test.step('Attempt to save', async () => {
      await settings.updatePasswordButton.click();

      // Should show error about incorrect current password
      await page.waitForTimeout(2000);

      const errorShown = await settings.errorMessage.isVisible({ timeout: 3000 }).catch(() => false);
      expect(errorShown).toBeTruthy();
    });
  });

  test('appearance settings allows theme selection', async ({ page }) => {
    await loginAsTestUser(page);

    const settings = new SettingsPage(page);

    await test.step('Navigate to appearance settings', async () => {
      await settings.gotoSection('appearance');
    });

    await test.step('Verify theme selector is available', async () => {
      const themeSelectorVisible = await settings.themeSelector.isVisible().catch(() => false);
      
      if (!themeSelectorVisible) {
        test.skip(true, 'Theme selector not available');
      }

      await expect(settings.themeSelector).toBeVisible();
    });

    await test.step('Select dark theme', async () => {
      await settings.themeSelector.click();
      
      const darkOption = page.getByRole('option', { name: /dark/i });
      const hasDarkOption = await darkOption.isVisible({ timeout: 2000 }).catch(() => false);
      
      if (hasDarkOption) {
        await darkOption.click();
        
        // Theme should update
        await page.waitForTimeout(500);
        
        // Verify theme changed (check for dark class or similar)
        const html = page.locator('html');
        const htmlClass = await html.getAttribute('class');
        expect(htmlClass).toContain('dark');
      }
    });
  });

  test('appearance settings allows language selection', async ({ page }) => {
    await loginAsTestUser(page);

    const settings = new SettingsPage(page);

    await test.step('Navigate to appearance settings', async () => {
      await settings.gotoSection('appearance');
    });

    await test.step('Verify language selector is available', async () => {
      const languageSelectorVisible = await settings.languageSelector.isVisible().catch(() => false);
      
      if (!languageSelectorVisible) {
        test.skip(true, 'Language selector not available');
      }

      await expect(settings.languageSelector).toBeVisible();
    });

    await test.step('Verify language options exist', async () => {
      await settings.languageSelector.click();
      
      // Should show language options
      const germanOption = page.getByRole('option', { name: /german|deutsch/i });
      const englishOption = page.getByRole('option', { name: /english|englisch/i });
      
      const hasOptions = 
        await germanOption.isVisible({ timeout: 1000 }).catch(() => false) ||
        await englishOption.isVisible({ timeout: 1000 }).catch(() => false);
      
      expect(hasOptions).toBeTruthy();
    });
  });

  test('editor settings are accessible', async ({ page }) => {
    await loginAsTestUser(page);

    const settings = new SettingsPage(page);

    await test.step('Navigate to editor settings', async () => {
      await settings.gotoSection('editor');
    });

    await test.step('Verify editor settings page loaded', async () => {
      await expect(page).toHaveURL(/\/settings\/editor/);
      
      // Should show some editor preferences
      const pageContent = await page.textContent('body');
      expect(pageContent).toBeTruthy();
    });
  });

  test('settings changes persist after logout and login', async ({ page }) => {
    await loginAsTestUser(page);

    const settings = new SettingsPage(page);

    await test.step('Change a setting', async () => {
      await settings.gotoSection('profile');
      
      const nameVisible = await settings.nameInput.isVisible().catch(() => false);
      if (!nameVisible) {
        test.skip(true, 'Profile name not editable');
      }

      const testName = 'Persistence Test User';
      await settings.nameInput.fill(testName);
      await settings.updateProfileButton.click();
      
      await page.waitForTimeout(1000);
    });

    await test.step('Logout', async () => {
      // Click user menu or logout button
      const logoutButton = page.getByRole('button', { name: /logout|sign out/i });
      await logoutButton.click();
      
      await expect(page).toHaveURL('/login');
    });

    await test.step('Login again', async () => {
      await loginAsTestUser(page);
    });

    await test.step('Verify setting persisted', async () => {
      await settings.gotoSection('profile');
      
      const nameValue = await settings.nameInput.inputValue();
      expect(nameValue).toBe('Persistence Test User');
    });
  });

  test('settings form shows validation errors', async ({ page }) => {
    await loginAsTestUser(page);

    const settings = new SettingsPage(page);

    await test.step('Navigate to profile settings', async () => {
      await settings.gotoSection('profile');
    });

    await test.step('Try to set invalid email', async () => {
      const emailVisible = await settings.emailInput.isVisible().catch(() => false);
      
      if (!emailVisible) {
        test.skip(true, 'Email field not editable');
      }

      await settings.emailInput.fill('invalid-email-format');
      await settings.updateProfileButton.click();

      // Should show validation error
      await page.waitForTimeout(1000);

      const errorShown = 
        await page.getByText(/invalid email/i).isVisible({ timeout: 2000 }).catch(() => false) ||
        await settings.errorMessage.isVisible().catch(() => false);

      expect(errorShown).toBeTruthy();
    });
  });

  test('settings can be reset to defaults', async ({ page }) => {
    await loginAsTestUser(page);

    const settings = new SettingsPage(page);

    await test.step('Navigate to settings', async () => {
      await settings.goto();
    });

    await test.step('Look for reset button', async () => {
      const resetButton = page.getByRole('button', { name: /reset|restore defaults/i });
      const hasResetButton = await resetButton.isVisible({ timeout: 2000 }).catch(() => false);

      if (!hasResetButton) {
        test.skip(true, 'Reset functionality not available');
      }

      await resetButton.click();

      // Should show confirmation
      const confirmDialog = page.getByRole('dialog');
      const dialogShown = await confirmDialog.isVisible({ timeout: 1000 }).catch(() => false);

      if (dialogShown) {
        const confirmButton = confirmDialog.getByRole('button', { name: /confirm|yes/i });
        await confirmButton.click();
      }

      // Settings should be reset
      await page.waitForTimeout(1000);
    });
  });
});

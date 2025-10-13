import { expect, type Locator, type Page } from '@playwright/test';

/**
 * Page Object Model for Settings pages
 * 
 * Handles interactions with user settings including profile,
 * password, appearance, and other preferences.
 */
export class SettingsPage {
  readonly page: Page;
  readonly heading: Locator;
  
  // Profile settings
  readonly nameInput: Locator;
  readonly emailInput: Locator;
  readonly updateProfileButton: Locator;
  
  // Password settings
  readonly currentPasswordInput: Locator;
  readonly newPasswordInput: Locator;
  readonly confirmPasswordInput: Locator;
  readonly updatePasswordButton: Locator;
  
  // Appearance settings
  readonly themeSelector: Locator;
  readonly languageSelector: Locator;
  
  // Common elements
  readonly successMessage: Locator;
  readonly errorMessage: Locator;

  constructor(page: Page) {
    this.page = page;
    this.heading = page.getByRole('heading', { name: /Settings/i });
    
    // Profile
    this.nameInput = page.getByLabel('Name');
    this.emailInput = page.getByLabel('Email');
    this.updateProfileButton = page.getByRole('button', { name: 'Update Profile' });
    
    // Password
    this.currentPasswordInput = page.getByLabel('Current Password');
    this.newPasswordInput = page.getByLabel('New Password');
    this.confirmPasswordInput = page.getByLabel('Confirm Password');
    this.updatePasswordButton = page.getByRole('button', { name: 'Update Password' });
    
    // Appearance
    this.themeSelector = page.getByLabel('Theme');
    this.languageSelector = page.getByLabel('Language');
    
    // Messages
    this.successMessage = page.getByRole('status').filter({ hasText: /success/i });
    this.errorMessage = page.getByRole('alert');
  }

  /**
   * Navigate to settings page (profile by default)
   */
  async goto() {
    await this.page.goto('/settings');
    // Wait for Inertia.js/React hydration
    await this.page.waitForLoadState('networkidle');
    await expect(this.heading).toBeVisible({ timeout: 30000 });
  }

  /**
   * Navigate to a specific settings section
   * @param section - Section name (profile, password, appearance, etc.)
   */
  async gotoSection(section: 'profile' | 'password' | 'appearance' | 'editor') {
    await this.page.goto(`/settings/${section}`);
    // Wait for Inertia.js/React hydration
    await this.page.waitForLoadState('networkidle');
    await expect(this.heading).toBeVisible({ timeout: 30000 });
  }

  /**
   * Update profile information
   * @param name - New name
   * @param email - New email (optional)
   */
  async updateProfile(name: string, email?: string) {
    await this.gotoSection('profile');
    
    await this.nameInput.fill(name);
    if (email) {
      await this.emailInput.fill(email);
    }
    
    await this.updateProfileButton.click();
  }

  /**
   * Change password
   * @param currentPassword - Current password
   * @param newPassword - New password
   * @param confirmPassword - Confirm new password
   */
  async changePassword(currentPassword: string, newPassword: string, confirmPassword?: string) {
    await this.gotoSection('password');
    
    await this.currentPasswordInput.fill(currentPassword);
    await this.newPasswordInput.fill(newPassword);
    await this.confirmPasswordInput.fill(confirmPassword || newPassword);
    
    await this.updatePasswordButton.click();
  }

  /**
   * Change theme
   * @param theme - Theme name (light, dark, system)
   */
  async changeTheme(theme: 'light' | 'dark' | 'system') {
    await this.gotoSection('appearance');
    await this.themeSelector.selectOption(theme);
    
    // Wait for theme to apply
    await this.page.waitForTimeout(300);
  }

  /**
   * Change language
   * @param language - Language code (en, de, etc.)
   */
  async changeLanguage(language: string) {
    await this.gotoSection('appearance');
    await this.languageSelector.selectOption(language);
    
    // Wait for language to apply
    await this.page.waitForTimeout(300);
  }

  /**
   * Verify success message is displayed
   * @param message - Expected success message text (optional)
   */
  async verifySuccess(message?: string) {
    await expect(this.successMessage).toBeVisible();
    
    if (message) {
      await expect(this.successMessage).toContainText(message);
    }
  }

  /**
   * Verify error message is displayed
   * @param message - Expected error message text (optional)
   */
  async verifyError(message?: string) {
    await expect(this.errorMessage).toBeVisible();
    
    if (message) {
      await expect(this.errorMessage).toContainText(message);
    }
  }
}

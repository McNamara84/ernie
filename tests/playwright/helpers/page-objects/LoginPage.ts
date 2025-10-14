import { expect, type Locator, type Page } from '@playwright/test';

/**
 * Page Object Model for the Login page
 * 
 * Handles all interactions with the login form and navigation to/from login.
 */
export class LoginPage {
  readonly page: Page;
  readonly emailInput: Locator;
  readonly passwordInput: Locator;
  readonly loginButton: Locator;
  readonly errorMessage: Locator;
  readonly forgotPasswordLink: Locator;
  readonly rememberMeCheckbox: Locator;

  constructor(page: Page) {
    this.page = page;
    this.emailInput = page.getByLabel('Email address');
    this.passwordInput = page.getByLabel('Password');
    this.loginButton = page.getByRole('button', { name: 'Log in' });
    // Laravel Breeze uses <p> tags with text-red-600 class for errors, not role="alert"
    this.errorMessage = page.locator('p.text-red-600, p[class*="text-red"]').first();
    this.forgotPasswordLink = page.getByRole('link', { name: 'Forgot password' });
    this.rememberMeCheckbox = page.getByLabel('Remember me');
  }

  /**
   * Navigate to the login page
   */
  async goto() {
    await this.page.goto('/login');
  }

  /**
   * Perform login with given credentials
   * @param email - User email
   * @param password - User password
   * @param rememberMe - Whether to check "Remember me" checkbox
   */
  async login(email: string, password: string, rememberMe = false) {
    await this.emailInput.fill(email);
    await this.passwordInput.fill(password);
    
    if (rememberMe) {
      await this.rememberMeCheckbox.check();
    }
    
    await this.loginButton.click();
  }

  /**
   * Perform login and wait for successful redirect to dashboard
   * @param email - User email
   * @param password - User password
   */
  async loginAndWaitForDashboard(email: string, password: string) {
    await this.login(email, password);
    await this.page.waitForURL(/\/dashboard/, { timeout: 15000 });
  }

  /**
   * Verify that we're on the login page
   */
  async verifyOnLoginPage() {
    await expect(this.page).toHaveURL('/login');
    await expect(this.loginButton).toBeVisible();
  }

  /**
   * Verify that an error message is displayed
   */
  async verifyErrorDisplayed(errorText?: string) {
    await expect(this.errorMessage).toBeVisible();
    
    if (errorText) {
      await expect(this.errorMessage).toContainText(errorText);
    }
  }

  /**
   * Click the "Forgot password" link
   */
  async clickForgotPassword() {
    await this.forgotPasswordLink.click();
  }
}

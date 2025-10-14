import { expect, type Locator, type Page } from '@playwright/test';

/**
 * Page Object Model for the Dashboard page
 * 
 * Handles navigation and interactions on the main dashboard.
 */
export class DashboardPage {
  readonly page: Page;
  readonly heading: Locator;
  readonly xmlDropzone: Locator;
  readonly xmlFileInput: Locator;
  readonly dropzoneDescription: Locator;
  readonly navigationMenu: Locator;

  constructor(page: Page) {
    this.page = page;
    this.heading = page.getByRole('heading', { name: 'Dashboard' });
    this.xmlDropzone = page.locator('text=Dropzone for XML files');
    this.xmlFileInput = page.locator('input[type="file"][accept=".xml"]');
    this.dropzoneDescription = page.locator('text=Here you can upload new XML files sent by ELMO for curation.');
    this.navigationMenu = page.getByRole('navigation');
  }

  /**
   * Navigate to the dashboard
   */
  async goto() {
    await this.page.goto('/dashboard');
  }

  /**
   * Verify that we're on the dashboard page
   */
  async verifyOnDashboard() {
    await expect(this.page).toHaveURL('/dashboard');
    await expect(this.xmlDropzone).toBeVisible();
    await expect(this.dropzoneDescription).toBeVisible();
  }

  /**
   * Upload an XML file via the dropzone
   * @param filePath - Absolute path to the XML file
   */
  async uploadXmlFile(filePath: string) {
    await expect(this.xmlFileInput).toBeAttached();
    await this.xmlFileInput.setInputFiles(filePath);
  }

  /**
   * Navigate to a specific page via the main navigation
   * @param pageName - Name of the page to navigate to
   */
  async navigateTo(pageName: 'Old Datasets' | 'Curation' | 'Resources' | 'Settings') {
    const link = this.page.getByRole('link', { name: pageName });
    await link.click();
  }

  /**
   * Verify navigation menu is visible and functional
   */
  async verifyNavigationVisible() {
    await expect(this.navigationMenu).toBeVisible();
    
    // Verify key navigation items are present (use first() to avoid strict mode violation with breadcrumbs)
    await expect(this.page.getByRole('link', { name: 'Dashboard' }).first()).toBeVisible();
    await expect(this.page.getByRole('link', { name: 'Old Datasets' }).first()).toBeVisible();
    await expect(this.page.getByRole('link', { name: 'Curation' }).first()).toBeVisible();
  }
}

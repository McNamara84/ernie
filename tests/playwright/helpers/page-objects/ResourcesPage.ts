import { expect, type Locator, type Page } from '@playwright/test';

/**
 * Page Object Model for the Resources page
 * 
 * Handles all interactions with the resources management interface.
 */
export class ResourcesPage {
  readonly page: Page;
  readonly heading: Locator;
  readonly resourceTable: Locator;
  readonly searchInput: Locator;
  readonly createButton: Locator;
  readonly noResourcesMessage: Locator;

  constructor(page: Page) {
    this.page = page;
    this.heading = page.getByRole('heading', { name: 'Resources' });
    this.resourceTable = page.getByRole('table');
    this.searchInput = page.getByPlaceholder('Search resources...');
    this.createButton = page.getByRole('button', { name: 'Create Resource' });
    this.noResourcesMessage = page.getByText('No resources found');
  }

  /**
   * Navigate to the resources page
   */
  async goto() {
    await this.page.goto('/resources');
  }

  /**
   * Verify that we're on the resources page
   */
  async verifyOnResourcesPage() {
    await expect(this.page).toHaveURL(/\/resources/);
    await expect(this.heading).toBeVisible();
  }

  /**
   * Search for resources
   * @param searchTerm - Text to search for
   */
  async search(searchTerm: string) {
    await this.searchInput.fill(searchTerm);
    await this.page.waitForTimeout(500);
  }

  /**
   * Click the create resource button
   */
  async createResource() {
    await this.createButton.click();
    await this.page.waitForURL(/\/curation/);
  }

  /**
   * Get resource row by index
   * @param index - 0-based index of the resource
   */
  getResourceRow(index: number) {
    return this.resourceTable.locator('tbody tr').nth(index);
  }

  /**
   * Edit a resource by index
   * @param index - 0-based index of the resource
   */
  async editResource(index: number) {
    const row = this.getResourceRow(index);
    const editButton = row.getByRole('button', { name: /Edit/i });
    await editButton.click();
    await this.page.waitForURL(/\/curation/);
  }

  /**
   * Delete a resource by index
   * @param index - 0-based index of the resource
   * @param confirm - Whether to confirm the deletion
   */
  async deleteResource(index: number, confirm: boolean = true) {
    const row = this.getResourceRow(index);
    const deleteButton = row.getByRole('button', { name: /Delete/i });
    await deleteButton.click();
    
    // Handle confirmation dialog
    if (confirm) {
      const confirmButton = this.page.getByRole('button', { name: /Confirm|Yes|Delete/i });
      await confirmButton.click();
    } else {
      const cancelButton = this.page.getByRole('button', { name: /Cancel|No/i });
      await cancelButton.click();
    }
  }

  /**
   * Verify that resources are displayed
   */
  async verifyResourcesDisplayed() {
    await expect(this.resourceTable).toBeVisible();
    const rows = this.resourceTable.locator('tbody tr');
    await expect(rows).not.toHaveCount(0);
  }

  /**
   * Verify that a specific resource exists in the list
   * @param doi - DOI of the resource to find
   */
  async verifyResourceExists(doi: string) {
    const resourceRow = this.page.getByRole('cell', { name: doi });
    await expect(resourceRow).toBeVisible();
  }
}

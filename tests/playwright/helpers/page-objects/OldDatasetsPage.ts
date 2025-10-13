import { expect, type Locator, type Page } from '@playwright/test';

/**
 * Sort configuration for old datasets
 */
export interface OldDatasetSort {
  field: 'title' | 'doi' | 'year' | 'curator' | 'status' | 'created' | 'updated';
  direction: 'asc' | 'desc';
}

/**
 * Filter configuration for old datasets
 */
export interface OldDatasetFilters {
  search?: string;
  status?: string[];
  curator?: string[];
  resourceType?: string[];
  yearFrom?: number;
  yearTo?: number;
}

/**
 * Page Object Model for the Old Datasets page
 * 
 * Handles all interactions with the legacy datasets overview,
 * including filtering, sorting, and loading data into curation.
 */
export class OldDatasetsPage {
  readonly page: Page;
  readonly heading: Locator;
  readonly datasetTable: Locator;
  readonly searchInput: Locator;
  readonly statusFilter: Locator;
  readonly curatorFilter: Locator;
  readonly resourceTypeFilter: Locator;
  readonly errorAlert: Locator;
  readonly noDataMessage: Locator;

  constructor(page: Page) {
    this.page = page;
    this.heading = page.getByRole('heading', { name: 'Old Datasets' });
    this.datasetTable = page.getByTestId('dataset-table');
    this.searchInput = page.getByPlaceholder('Search datasets...');
    this.statusFilter = page.getByLabel('Status');
    this.curatorFilter = page.getByLabel('Curator');
    this.resourceTypeFilter = page.getByLabel('Resource Type');
    this.errorAlert = page.getByRole('alert');
    this.noDataMessage = page.getByText('No datasets available');
  }

  /**
   * Navigate to the old datasets page
   */
  async goto() {
    await this.page.goto('/old-datasets');
  }

  /**
   * Verify that we're on the old datasets page
   */
  async verifyOnOldDatasetsPage() {
    await expect(this.page).toHaveURL(/\/old-datasets/);
    await expect(this.heading).toBeVisible();
  }

  /**
   * Verify database connection error is displayed
   */
  async verifyDatabaseError() {
    await expect(this.errorAlert).toBeVisible();
    await expect(this.errorAlert).toContainText('Datenbankverbindung fehlgeschlagen');
    await expect(this.noDataMessage).toBeVisible();
  }

  /**
   * Apply search filter
   * @param searchTerm - Text to search for
   */
  async search(searchTerm: string) {
    await this.searchInput.fill(searchTerm);
    // Wait for debounce/results to update
    await this.page.waitForTimeout(500);
  }

  /**
   * Apply filters to the dataset list
   * @param filters - Filter configuration object
   */
  async applyFilters(filters: OldDatasetFilters) {
    if (filters.search) {
      await this.search(filters.search);
    }

    if (filters.status && filters.status.length > 0) {
      for (const status of filters.status) {
        await this.statusFilter.selectOption(status);
      }
    }

    if (filters.resourceType && filters.resourceType.length > 0) {
      for (const type of filters.resourceType) {
        await this.resourceTypeFilter.selectOption(type);
      }
    }

    // Wait for filters to apply
    await this.page.waitForTimeout(500);
  }

  /**
   * Sort datasets by clicking column header
   * @param field - Field to sort by
   */
  async sortBy(field: OldDatasetSort['field']) {
    const columnHeader = this.page.getByRole('columnheader', { 
      name: new RegExp(field, 'i') 
    });
    await columnHeader.click();
    
    // Wait for sort to apply
    await this.page.waitForTimeout(500);
  }

  /**
   * Get the first dataset row
   */
  getFirstDatasetRow() {
    return this.datasetTable.locator('tbody tr').first();
  }

  /**
   * Load authors from a dataset into the curation form
   * @param datasetIndex - Index of the dataset (0-based)
   */
  async loadAuthors(datasetIndex: number = 0) {
    const loadButton = this.page
      .locator('button')
      .filter({ hasText: /Load Authors/i })
      .nth(datasetIndex);
    
    await loadButton.click();
    
    // Wait for navigation to curation page
    await this.page.waitForURL(/\/curation/, { timeout: 10000 });
  }

  /**
   * Load dates from a dataset into the curation form
   * @param datasetIndex - Index of the dataset (0-based)
   */
  async loadDates(datasetIndex: number = 0) {
    const loadButton = this.page
      .locator('button')
      .filter({ hasText: /Load Dates/i })
      .nth(datasetIndex);
    
    await loadButton.click();
    
    // Wait for navigation to curation page
    await this.page.waitForURL(/\/curation/, { timeout: 10000 });
  }

  /**
   * Load descriptions from a dataset into the curation form
   * @param datasetIndex - Index of the dataset (0-based)
   */
  async loadDescriptions(datasetIndex: number = 0) {
    const loadButton = this.page
      .locator('button')
      .filter({ hasText: /Load Descriptions/i })
      .nth(datasetIndex);
    
    await loadButton.click();
    
    // Wait for navigation to curation page
    await this.page.waitForURL(/\/curation/, { timeout: 10000 });
  }

  /**
   * Load contributors from a dataset into the curation form
   * @param datasetIndex - Index of the dataset (0-based)
   */
  async loadContributors(datasetIndex: number = 0) {
    const loadButton = this.page
      .locator('button')
      .filter({ hasText: /Load Contributors/i })
      .nth(datasetIndex);
    
    await loadButton.click();
    
    // Wait for navigation to curation page
    await this.page.waitForURL(/\/curation/, { timeout: 10000 });
  }

  /**
   * Verify that datasets are displayed in the table
   */
  async verifyDatasetsDisplayed() {
    await expect(this.datasetTable).toBeVisible();
    
    const rows = this.datasetTable.locator('tbody tr');
    await expect(rows).not.toHaveCount(0);
  }

  /**
   * Get dataset year from a specific row
   * @param rowIndex - Index of the row (0-based)
   */
  async getDatasetYear(rowIndex: number = 0): Promise<string> {
    const yearCell = this.datasetTable
      .locator('tbody tr')
      .nth(rowIndex)
      .locator('[data-testid="dataset-year"]');
    
    return (await yearCell.textContent()) || '';
  }
}

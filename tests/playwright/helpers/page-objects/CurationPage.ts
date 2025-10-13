import { expect, type Locator, type Page } from '@playwright/test';

/**
 * Author type for the curation form
 */
export type AuthorType = 'Person' | 'Institution';

/**
 * Page Object Model for the Curation page
 * 
 * Handles all interactions with the curation form including
 * authors, titles, descriptions, dates, and controlled vocabularies.
 */
export class CurationPage {
  readonly page: Page;
  readonly heading: Locator;
  
  // Accordion sections
  readonly authorsAccordion: Locator;
  readonly titlesAccordion: Locator;
  readonly descriptionsAccordion: Locator;
  readonly datesAccordion: Locator;
  readonly vocabulariesAccordion: Locator;
  
  // Common buttons
  readonly saveButton: Locator;
  readonly cancelButton: Locator;

  constructor(page: Page) {
    this.page = page;
    this.heading = page.getByRole('heading', { name: /Curation/i });
    
    // Accordion triggers
    this.authorsAccordion = page.getByRole('button', { name: 'Authors' });
    this.titlesAccordion = page.getByRole('button', { name: 'Titles' });
    this.descriptionsAccordion = page.getByRole('button', { name: 'Descriptions' });
    this.datesAccordion = page.getByRole('button', { name: 'Dates' });
    this.vocabulariesAccordion = page.getByRole('button', { name: 'Controlled Vocabularies' });
    
    this.saveButton = page.getByRole('button', { name: /Save|Submit/i });
    this.cancelButton = page.getByRole('button', { name: 'Cancel' });
  }

  /**
   * Navigate to the curation page
   */
  async goto() {
    await this.page.goto('/curation');
  }

  /**
   * Navigate to curation with query parameters
   * @param params - URL query parameters
   */
  async gotoWithParams(params: Record<string, string>) {
    const queryString = new URLSearchParams(params).toString();
    await this.page.goto(`/curation?${queryString}`);
  }

  /**
   * Verify that we're on the curation page
   */
  async verifyOnCurationPage() {
    await expect(this.page).toHaveURL(/\/curation/);
    // Wait for Inertia.js/React hydration to complete
    await this.page.waitForLoadState('networkidle');
    // Wait for page content (check if accordions OR save button exist)
    // Some pages might not have accordions but should have save button
    const pageContentVisible = this.authorsAccordion.or(this.titlesAccordion).or(this.saveButton);
    await expect(pageContentVisible).toBeVisible({ timeout: 30000 });
  }

  /**
   * Open an accordion section if not already open
   * @param section - The accordion button locator
   */
  async openAccordion(section: Locator) {
    // Wait for accordion to exist first (with longer timeout for CI)
    await expect(section).toBeVisible({ timeout: 30000 });
    const isExpanded = await section.getAttribute('aria-expanded');
    if (isExpanded === 'false') {
      await section.click();
      await expect(section).toHaveAttribute('aria-expanded', 'true');
    }
  }

  /**
   * Close an accordion section if open
   * @param section - The accordion button locator
   */
  async closeAccordion(section: Locator) {
    const isExpanded = await section.getAttribute('aria-expanded');
    if (isExpanded === 'true') {
      await section.click();
      await expect(section).toHaveAttribute('aria-expanded', 'false');
    }
  }

  /**
   * Get the author region (accordion content) by index
   * @param index - 0-based index of the author
   */
  getAuthorRegion(index: number) {
    return this.page.getByRole('region', { name: `Author ${index + 1}` });
  }

  /**
   * Add a new author row
   */
  async addAuthor() {
    await this.openAccordion(this.authorsAccordion);
    const addButton = this.page.getByRole('button', { name: 'Add author' }).first();
    await addButton.click();
  }

  /**
   * Remove an author by index
   * @param index - 0-based index of the author to remove
   */
  async removeAuthor(index: number) {
    await this.openAccordion(this.authorsAccordion);
    const deleteButtons = this.page.getByRole('button', { name: /Remove author/ });
    await deleteButtons.nth(index).click();
  }

  /**
   * Fill author details
   * @param index - 0-based index of the author
   * @param data - Author data to fill
   */
  async fillAuthor(index: number, data: {
    type?: AuthorType;
    firstName?: string;
    lastName?: string;
    orcid?: string;
    institutionName?: string;
    isContactPerson?: boolean;
    email?: string;
    website?: string;
  }) {
    await this.openAccordion(this.authorsAccordion);
    const authorRegion = this.getAuthorRegion(index);

    if (data.type) {
      const typeSelector = authorRegion.getByRole('combobox', { name: 'Author type' });
      await typeSelector.click();
      await this.page.getByRole('option', { name: data.type }).click();
    }

    if (data.type === 'Person' || !data.type) {
      if (data.firstName) {
        await authorRegion.getByLabel('First name').fill(data.firstName);
      }
      if (data.lastName) {
        await authorRegion.getByLabel('Last name').fill(data.lastName);
      }
      if (data.orcid) {
        await authorRegion.getByLabel('ORCID').fill(data.orcid);
      }
      if (data.isContactPerson !== undefined) {
        const checkbox = authorRegion.getByLabel('Contact person');
        if (data.isContactPerson) {
          await checkbox.check();
        } else {
          await checkbox.uncheck();
        }
      }
      if (data.email) {
        await authorRegion.getByLabel('Email').fill(data.email);
      }
      if (data.website) {
        await authorRegion.getByLabel('Website').fill(data.website);
      }
    } else if (data.type === 'Institution') {
      if (data.institutionName) {
        await authorRegion.getByLabel('Institution name').fill(data.institutionName);
      }
    }
  }

  /**
   * Add a new title row
   */
  async addTitle() {
    await this.openAccordion(this.titlesAccordion);
    const addButton = this.page.getByRole('button', { name: 'Add title' }).first();
    await addButton.click();
  }

  /**
   * Remove a title by index
   * @param index - 0-based index of the title to remove
   */
  async removeTitle(index: number) {
    await this.openAccordion(this.titlesAccordion);
    const deleteButtons = this.page.getByRole('button', { name: /Remove title/ });
    await deleteButtons.nth(index).click();
  }

  /**
   * Open the controlled vocabularies section
   */
  async openVocabularies() {
    await this.openAccordion(this.vocabulariesAccordion);
  }

  /**
   * Search for a vocabulary term
   * @param searchTerm - Term to search for
   */
  async searchVocabulary(searchTerm: string) {
    await this.openVocabularies();
    const searchInput = this.page.getByPlaceholder('Search vocabularies...');
    await searchInput.fill(searchTerm);
    
    // Wait for search results
    await this.page.waitForTimeout(300);
  }

  /**
   * Select a vocabulary keyword
   * @param keyword - The keyword text to select
   */
  async selectVocabularyKeyword(keyword: string) {
    await this.openVocabularies();
    const keywordButton = this.page.getByRole('button', { name: keyword });
    await keywordButton.click();
  }

  /**
   * Switch vocabulary tab
   * @param tabName - Name of the tab (Science Keywords, Platforms, Instruments)
   */
  async switchVocabularyTab(tabName: string) {
    await this.openVocabularies();
    const tab = this.page.getByRole('tab', { name: tabName });
    await tab.click();
  }

  /**
   * Verify that a vocabulary keyword is selected
   * @param keyword - The keyword text
   */
  async verifyVocabularySelected(keyword: string) {
    const selectedTag = this.page.getByText(keyword);
    await expect(selectedTag).toBeVisible();
  }

  /**
   * Save the curation form
   */
  async save() {
    await this.saveButton.click();
  }

  /**
   * Cancel the curation form
   */
  async cancel() {
    await this.cancelButton.click();
  }

  /**
   * Verify that form data was populated from URL parameters
   * @param expectedData - Expected form data
   */
  async verifyFormPopulatedFromUrl(expectedData: {
    doi?: string;
    year?: string;
  }) {
    const currentUrl = this.page.url();
    
    if (expectedData.doi) {
      expect(currentUrl).toContain(`doi=${encodeURIComponent(expectedData.doi)}`);
    }
    
    if (expectedData.year) {
      expect(currentUrl).toContain(`year=${expectedData.year}`);
    }
  }

  /**
   * Verify author data is filled
   * @param index - 0-based index of the author
   * @param expectedData - Expected author data
   */
  async verifyAuthorData(index: number, expectedData: {
    lastName?: string;
    firstName?: string;
  }) {
    await this.openAccordion(this.authorsAccordion);
    const authorRegion = this.getAuthorRegion(index);
    
    if (expectedData.lastName) {
      await expect(authorRegion.getByLabel('Last name')).toHaveValue(expectedData.lastName);
    }
    
    if (expectedData.firstName) {
      await expect(authorRegion.getByLabel('First name')).toHaveValue(expectedData.firstName);
    }
  }

  /**
   * Fill title details
   * @param index - 0-based index of the title
   * @param data - Title data to fill
   */
  async fillTitle(index: number, data: {
    title: string;
    language?: string;
    type?: string;
  }) {
    await this.openAccordion(this.titlesAccordion);
    
    const titleInput = this.page.getByLabel('Title').nth(index);
    await titleInput.fill(data.title);
    
    if (data.language) {
      const languageSelect = this.page.getByLabel('Language').nth(index);
      await languageSelect.click();
      await this.page.getByRole('option', { name: data.language }).click();
    }
    
    if (data.type) {
      const typeSelect = this.page.getByLabel('Title Type').nth(index);
      await typeSelect.click();
      await this.page.getByRole('option', { name: data.type }).click();
    }
  }

  /**
   * Add a new description row
   */
  async addDescription() {
    await this.openAccordion(this.descriptionsAccordion);
    const addButton = this.page.getByRole('button', { name: 'Add description' }).first();
    await addButton.click();
  }

  /**
   * Fill description details
   * @param index - 0-based index of the description
   * @param data - Description data to fill
   */
  async fillDescription(index: number, data: {
    description: string;
    language?: string;
    type?: string;
  }) {
    await this.openAccordion(this.descriptionsAccordion);
    
    const descriptionInput = this.page.getByLabel('Description').nth(index);
    await descriptionInput.fill(data.description);
    
    if (data.language) {
      const languageSelect = this.page.getByLabel('Language').nth(index);
      await languageSelect.click();
      await this.page.getByRole('option', { name: data.language }).click();
    }
    
    if (data.type) {
      const typeSelect = this.page.getByLabel('Description Type').nth(index);
      await typeSelect.click();
      await this.page.getByRole('option', { name: data.type }).click();
    }
  }

  /**
   * Add a new date row
   */
  async addDate() {
    await this.openAccordion(this.datesAccordion);
    const addButton = this.page.getByRole('button', { name: 'Add date' }).first();
    await addButton.click();
  }

  /**
   * Fill date details
   * @param index - 0-based index of the date
   * @param data - Date data to fill
   */
  async fillDate(index: number, data: {
    date?: string;
    dateFrom?: string;
    dateTo?: string;
    type?: string;
  }) {
    await this.openAccordion(this.datesAccordion);
    
    if (data.date) {
      const dateInput = this.page.getByLabel('Date').nth(index);
      await dateInput.fill(data.date);
    }
    
    if (data.dateFrom) {
      const dateFromInput = this.page.getByLabel('Date From').nth(index);
      await dateFromInput.fill(data.dateFrom);
    }
    
    if (data.dateTo) {
      const dateToInput = this.page.getByLabel('Date To').nth(index);
      await dateToInput.fill(data.dateTo);
    }
    
    if (data.type) {
      const typeSelect = this.page.getByLabel('Date Type').nth(index);
      await typeSelect.click();
      await this.page.getByRole('option', { name: data.type }).click();
    }
  }
}

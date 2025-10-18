import { type Locator, type Page } from '@playwright/test';

/**
 * Page Object Model for DataCite Metadata Form
 * Handles interactions with the curation/editor form including validation feedback
 */
export class DataCiteFormPage {
  readonly page: Page;
  
  // Accordion Sections
  readonly resourceInfoAccordion: Locator;
  readonly licensesAccordion: Locator;
  readonly authorsAccordion: Locator;
  readonly contributorsAccordion: Locator;
  readonly descriptionsAccordion: Locator;
  readonly controlledVocabulariesAccordion: Locator;
  readonly freeKeywordsAccordion: Locator;
  readonly mslLaboratoriesAccordion: Locator;
  readonly spatialTemporalCoverageAccordion: Locator;
  readonly datesAccordion: Locator;
  readonly relatedWorkAccordion: Locator;
  readonly fundingAccordion: Locator;
  
  // Resource Info Fields
  readonly doiInput: Locator;
  readonly yearInput: Locator;
  readonly resourceTypeSelect: Locator;
  readonly languageSelect: Locator;
  readonly versionInput: Locator;
  readonly mainTitleInput: Locator;
  
  // License Fields
  readonly primaryLicenseSelect: Locator;
  
  // Description Fields
  readonly abstractTextarea: Locator;
  readonly abstractCharacterCount: Locator;
  
  // Save Button
  readonly saveButton: Locator;
  readonly saveButtonTooltip: Locator;
  
  constructor(page: Page) {
    this.page = page;
    
    // Accordion triggers - locate by text content and button role
    this.resourceInfoAccordion = page.getByRole('button', { name: /Resource Information/i });
    this.licensesAccordion = page.getByRole('button', { name: /Licenses.*Rights/i });
    this.authorsAccordion = page.getByRole('button', { name: /Authors/i });
    this.contributorsAccordion = page.getByRole('button', { name: /Contributors/i });
    this.descriptionsAccordion = page.getByRole('button', { name: /Descriptions/i });
    this.controlledVocabulariesAccordion = page.getByRole('button', { name: /Controlled Vocabularies/i });
    this.freeKeywordsAccordion = page.getByRole('button', { name: /Free Keywords/i });
    this.mslLaboratoriesAccordion = page.getByRole('button', { name: /MSL Laboratories/i });
    this.spatialTemporalCoverageAccordion = page.getByRole('button', { name: /Spatial.*Temporal Coverage/i });
    this.datesAccordion = page.getByRole('button', { name: /Dates/i });
    this.relatedWorkAccordion = page.getByRole('button', { name: /Related Work/i });
    this.fundingAccordion = page.getByRole('button', { name: /Funding/i });
    
    // Resource Info Fields
    this.doiInput = page.locator('#doi');
    this.yearInput = page.locator('#year');
    this.resourceTypeSelect = page.getByTestId('resource-type-select');
    this.languageSelect = page.getByTestId('language-select');
    this.versionInput = page.locator('#version');
    // Main title uses dynamic ID, so use role-based query
    this.mainTitleInput = page.getByRole('textbox', { name: /^Title$/i });
    
    // License Fields
    this.primaryLicenseSelect = page.getByTestId('license-select-0');
    
    // Description Fields
    this.abstractTextarea = page.getByTestId('abstract-textarea');
    this.abstractCharacterCount = page.locator('.character-count').first();
    
    // Save Button
    this.saveButton = page.getByRole('button', { name: /Save to database/i });
    this.saveButtonTooltip = page.locator('[role="tooltip"]');
  }
  
  /**
   * Navigate to the editor page
   */
  async goto() {
    await this.page.goto('/editor');
  }
  
  /**
   * Wait for the form to be fully loaded
   */
  async waitForFormLoad() {
    await this.resourceInfoAccordion.waitFor({ state: 'visible', timeout: 10000 });
    await this.saveButton.waitFor({ state: 'visible' });
  }
  
  /**
   * Expand an accordion section by clicking its trigger
   */
  async expandAccordion(accordion: Locator) {
    const isExpanded = await accordion.getAttribute('aria-expanded');
    if (isExpanded !== 'true') {
      await accordion.click();
      // Wait for animation
      await this.page.waitForTimeout(300);
    }
  }
  
  /**
   * Collapse an accordion section
   */
  async collapseAccordion(accordion: Locator) {
    const isExpanded = await accordion.getAttribute('aria-expanded');
    if (isExpanded === 'true') {
      await accordion.click();
      await this.page.waitForTimeout(300);
    }
  }
  
  /**
   * Get the status badge for an accordion section
   * Returns the aria-label of the badge icon
   */
  async getAccordionStatusBadge(accordion: Locator): Promise<string | null> {
    const badge = accordion.locator('svg[aria-label]');
    if (await badge.count() === 0) {
      return null;
    }
    return await badge.getAttribute('aria-label');
  }
  
  /**
   * Get validation messages for a field (errors, warnings, success)
   */
  async getFieldValidationMessages(fieldLocator: Locator): Promise<string[]> {
    const fieldContainer = fieldLocator.locator('xpath=ancestor::div[contains(@class, "space-y")]').first();
    const messages = fieldContainer.locator('.validation-message');
    const count = await messages.count();
    
    const texts: string[] = [];
    for (let i = 0; i < count; i++) {
      const text = await messages.nth(i).textContent();
      if (text) {
        texts.push(text.trim());
      }
    }
    
    return texts;
  }
  
  /**
   * Check if a field has validation error styling
   */
  async hasValidationError(fieldLocator: Locator): Promise<boolean> {
    const classes = await fieldLocator.getAttribute('class') || '';
    return classes.includes('border-red') || classes.includes('ring-red');
  }
  
  /**
   * Check if a field has validation success styling
   */
  async hasValidationSuccess(fieldLocator: Locator): Promise<boolean> {
    const classes = await fieldLocator.getAttribute('class') || '';
    return classes.includes('border-green') || classes.includes('ring-green');
  }
  
  /**
   * Fill the main title field and trigger blur
   */
  async fillMainTitle(title: string) {
    await this.expandAccordion(this.resourceInfoAccordion);
    await this.mainTitleInput.fill(title);
    await this.mainTitleInput.blur();
    // Wait for debounced validation
    await this.page.waitForTimeout(400);
  }
  
  /**
   * Fill the year field and trigger blur
   */
  async fillYear(year: string) {
    await this.expandAccordion(this.resourceInfoAccordion);
    await this.yearInput.fill(year);
    await this.yearInput.blur();
    await this.page.waitForTimeout(400);
  }
  
  /**
   * Fill the abstract field and trigger blur
   */
  async fillAbstract(text: string) {
    await this.expandAccordion(this.descriptionsAccordion);
    await this.abstractTextarea.fill(text);
    await this.abstractTextarea.blur();
    await this.page.waitForTimeout(400);
  }
  
  /**
   * Get the character count displayed for abstract
   */
  async getAbstractCharacterCount(): Promise<string> {
    const text = await this.abstractCharacterCount.textContent();
    return text?.trim() || '';
  }
  
  /**
   * Check if Save button is disabled
   */
  async isSaveButtonDisabled(): Promise<boolean> {
    return await this.saveButton.isDisabled();
  }
  
  /**
   * Hover over Save button to show tooltip
   */
  async hoverSaveButton() {
    await this.saveButton.hover();
    await this.page.waitForTimeout(300);
  }
  
  /**
   * Get the text content of the Save button tooltip
   */
  async getSaveButtonTooltipText(): Promise<string> {
    const tooltip = this.page.locator('[role="tooltip"]');
    await tooltip.waitFor({ state: 'visible', timeout: 2000 });
    const text = await tooltip.textContent();
    return text?.trim() || '';
  }
  
  /**
   * Click the Save button
   */
  async clickSave() {
    await this.saveButton.click();
  }
  
  /**
   * Fill all required fields with valid data
   */
  async fillAllRequiredFields() {
    // Resource Info
    await this.expandAccordion(this.resourceInfoAccordion);
    await this.mainTitleInput.fill('Test Dataset for Validation E2E');
    await this.yearInput.fill('2024');
    await this.resourceTypeSelect.click();
    await this.page.getByRole('option', { name: /Dataset/i }).first().click();
    await this.languageSelect.click();
    await this.page.getByRole('option', { name: /English/i }).first().click();
    
    // License
    await this.expandAccordion(this.licensesAccordion);
    await this.primaryLicenseSelect.click();
    await this.page.getByRole('option', { name: /CC BY 4.0/i }).first().click();
    
    // Abstract (50+ characters required)
    await this.expandAccordion(this.descriptionsAccordion);
    await this.abstractTextarea.fill('This is a comprehensive test abstract that contains more than fifty characters to meet the minimum length requirement for validation.');
    
    // Wait for all validations to complete
    await this.page.waitForTimeout(500);
  }
  
  /**
   * Clear all form fields
   */
  async clearAllFields() {
    await this.expandAccordion(this.resourceInfoAccordion);
    await this.mainTitleInput.clear();
    await this.yearInput.clear();
    await this.versionInput.clear();
    
    await this.expandAccordion(this.descriptionsAccordion);
    await this.abstractTextarea.clear();
    
    await this.page.waitForTimeout(500);
  }
}

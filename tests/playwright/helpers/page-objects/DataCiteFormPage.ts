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
    
    // Accordion triggers - use data-slot to ensure we get accordion triggers only
    this.resourceInfoAccordion = page.locator('[data-slot="accordion-trigger"]', { hasText: /Resource Information/i });
    this.licensesAccordion = page.locator('[data-slot="accordion-trigger"]', { hasText: /Licenses.*Rights/i });
    this.authorsAccordion = page.locator('[data-slot="accordion-trigger"]', { hasText: /Authors/i });
    this.contributorsAccordion = page.locator('[data-slot="accordion-trigger"]', { hasText: /Contributors/i });
    this.descriptionsAccordion = page.locator('[data-slot="accordion-trigger"]', { hasText: /Descriptions/i });
    this.controlledVocabulariesAccordion = page.locator('[data-slot="accordion-trigger"]', { hasText: /Controlled Vocabularies/i });
    this.freeKeywordsAccordion = page.locator('[data-slot="accordion-trigger"]', { hasText: /Free Keywords/i });
    this.mslLaboratoriesAccordion = page.locator('[data-slot="accordion-trigger"]', { hasText: /MSL Laboratories/i });
    this.spatialTemporalCoverageAccordion = page.locator('[data-slot="accordion-trigger"]', { hasText: /Spatial.*Temporal Coverage/i });
    this.datesAccordion = page.locator('[data-slot="accordion-trigger"]', { hasText: /Dates/i });
    this.relatedWorkAccordion = page.locator('[data-slot="accordion-trigger"]', { hasText: /Related Work/i });
    this.fundingAccordion = page.locator('[data-slot="accordion-trigger"]', { hasText: /Funding/i });
    
    // Resource Info Fields
    this.doiInput = page.locator('#doi');
    this.yearInput = page.locator('#year');
    this.resourceTypeSelect = page.getByTestId('resource-type-select');
    this.languageSelect = page.getByTestId('language-select');
    this.versionInput = page.locator('#version');
    this.mainTitleInput = page.getByTestId('main-title-input');
    
    // License Fields
    this.primaryLicenseSelect = page.getByTestId('license-select-0');
    
    // Description Fields
    this.abstractTextarea = page.getByTestId('abstract-textarea');
    this.abstractCharacterCount = page.locator('#description-Abstract-count');
    
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
    // Get aria-describedby to find validation feedback element
    const describedBy = await fieldLocator.getAttribute('aria-describedby');
    if (!describedBy) return [];
    
    // Split by space and find all feedback IDs
    const ids = describedBy.split(/\s+/).filter(id => id.includes('feedback'));
    if (ids.length === 0) return [];
    
    const messages: string[] = [];
    for (const feedbackId of ids) {
      const feedback = this.page.locator(`#${feedbackId}`);
      
      try {
        // Wait for feedback element to be visible (with short timeout)
        await feedback.waitFor({ state: 'visible', timeout: 1000 });
        const text = await feedback.textContent();
        if (text) {
          messages.push(text.trim());
        }
      } catch {
        // Feedback element doesn't exist or isn't visible, skip it
        continue;
      }
    }
    
    return messages;
  }
  
  /**
   * Check if a field has validation error styling
   */
  async hasValidationError(fieldLocator: Locator): Promise<boolean> {
    const ariaInvalid = await fieldLocator.getAttribute('aria-invalid');
    return ariaInvalid === 'true';
  }
  
  /**
   * Check if a field has validation success styling
   */
  async hasValidationSuccess(fieldLocator: Locator): Promise<boolean> {
    const ariaInvalid = await fieldLocator.getAttribute('aria-invalid');
    // Field is NOT invalid (either false or null/undefined)
    return ariaInvalid !== 'true';
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
    // The button is wrapped in a tooltip trigger, hover over the trigger span
    const tooltipTrigger = this.page.locator('[data-slot="tooltip-trigger"]', { has: this.saveButton });
    await tooltipTrigger.hover({ force: true });
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
    
    // Resource Type Select
    await this.resourceTypeSelect.scrollIntoViewIfNeeded();
    await this.resourceTypeSelect.click();
    await this.page.getByRole('listbox').waitFor({ state: 'visible', timeout: 10000 });
    const datasetOption = this.page.getByRole('option', { name: /Dataset/i }).first();
    await datasetOption.waitFor({ state: 'visible', timeout: 10000 });
    await datasetOption.click();
    
    // Language Select
    await this.languageSelect.scrollIntoViewIfNeeded();
    await this.languageSelect.click();
    await this.page.getByRole('listbox').waitFor({ state: 'visible', timeout: 10000 });
    const englishOption = this.page.getByRole('option', { name: /English/i }).first();
    await englishOption.waitFor({ state: 'visible', timeout: 10000 });
    await englishOption.click();
    
    // License - needs extra robustness
    await this.expandAccordion(this.licensesAccordion);
    await this.page.waitForTimeout(500); // Wait for accordion animation
    await this.primaryLicenseSelect.scrollIntoViewIfNeeded();
    await this.primaryLicenseSelect.click();
    
    // Wait for the listbox to appear (the container for options)
    await this.page.getByRole('listbox').waitFor({ state: 'visible', timeout: 10000 });
    
    // Now wait for and click the specific option
    // Note: SPDX license name is the full name, not the identifier
    const ccByOption = this.page.getByRole('option', { name: /Creative Commons Attribution 4\.0/i }).first();
    await ccByOption.waitFor({ state: 'visible', timeout: 10000 });
    await ccByOption.click();
    
    // Abstract (50+ characters required)
    await this.expandAccordion(this.descriptionsAccordion);
    await this.abstractTextarea.fill('This is a comprehensive test abstract that contains more than fifty characters to meet the minimum length requirement for validation.');
    
    // Authors (at least one required with lastName)
    await this.expandAccordion(this.authorsAccordion);
    const addAuthorButton = this.page.getByRole('button', { name: /Add Author/i });
    if (await addAuthorButton.isVisible()) {
      await addAuthorButton.click();
      await this.page.waitForTimeout(300);
      
      const lastNameInput = this.page.locator('input[name*="lastName"]').first();
      await lastNameInput.fill('Testauthor');
      await lastNameInput.blur();
      await this.page.waitForTimeout(300);
    }
    
    // Created Date (required) - Note: First date entry already exists with type "Created"
    // We just need to fill in the date value
    await this.expandAccordion(this.datesAccordion);
    
    // The first date should already exist with type "Created"
    // Find the first date input (type="date") and fill it
    const firstDateInput = this.page.locator('input[type="date"]').first();
    if (await firstDateInput.isVisible()) {
      await firstDateInput.scrollIntoViewIfNeeded();
      await firstDateInput.fill('2024-01-01');
      await firstDateInput.blur();
      await this.page.waitForTimeout(500);
    }
    
    // Wait for all validations to complete and form state to update
    // The areRequiredFieldsFilled memo needs time to recompute
    await this.page.waitForTimeout(2000);
    
    // Wait for Save button to become enabled (with timeout)
    try {
      await this.saveButton.waitFor({ state: 'attached', timeout: 3000 });
      // Give React time to update the disabled state
      await this.page.waitForTimeout(500);
    } catch {
      // Button might already be attached, that's fine
    }
  }
  
  /**
   * Clear all form fields
   */
  async clearAllFields() {
    await this.expandAccordion(this.resourceInfoAccordion);
    await this.mainTitleInput.clear();
    await this.yearInput.clear();
    await this.versionInput.clear();
    
    // Clear select fields by selecting empty/first option
    // Note: This might not work for all select implementations
    // If selects don't have empty option, this will fail silently
    
    await this.expandAccordion(this.descriptionsAccordion);
    await this.abstractTextarea.clear();
    
    await this.page.waitForTimeout(500);
  }
}

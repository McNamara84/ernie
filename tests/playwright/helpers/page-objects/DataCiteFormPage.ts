import { expect, type Locator, type Page } from '@playwright/test';

/**
 * Helper function to locate an accordion trigger button by its text
 * Reduces duplication when accessing multiple accordion sections
 *
 * @param page - Playwright Page instance
 * @param textPattern - RegExp pattern to match accordion text
 * @returns Locator for the accordion trigger button
 */
function getAccordionTrigger(page: Page, textPattern: RegExp): Locator {
    return page.locator('[data-slot="accordion-trigger"]', { hasText: textPattern });
}

/**
 * Page Object Model for DataCite Metadata Form
 * Handles interactions with the curation/editor form including validation feedback
 */
export class DataCiteFormPage {
    readonly page: Page;

    // Form Sections
    readonly resourceInfoSection: Locator;
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
    readonly usedInstrumentsAccordion: Locator;
    readonly fundingAccordion: Locator;
    readonly collapseAllButtons: Locator;
    readonly expandAllButtons: Locator;

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

        // Resource Information is always visible; the remaining sections use accordion triggers.
        this.resourceInfoSection = page.getByTestId('resource-info-section');
        this.licensesAccordion = getAccordionTrigger(page, /Licenses.*Rights/i);
        this.authorsAccordion = getAccordionTrigger(page, /Authors/i);
        this.contributorsAccordion = getAccordionTrigger(page, /Contributors/i);
        this.descriptionsAccordion = getAccordionTrigger(page, /Descriptions/i);
        this.controlledVocabulariesAccordion = getAccordionTrigger(page, /Controlled Vocabularies/i);
        this.freeKeywordsAccordion = getAccordionTrigger(page, /Free Keywords/i);
        this.mslLaboratoriesAccordion = getAccordionTrigger(page, /Originating Multi-Scale Laboratories/i);
        this.spatialTemporalCoverageAccordion = getAccordionTrigger(page, /Spatial.*Temporal Coverage/i);
        this.datesAccordion = getAccordionTrigger(page, /Dates/i);
        this.relatedWorkAccordion = getAccordionTrigger(page, /Related Work/i);
        this.usedInstrumentsAccordion = getAccordionTrigger(page, /Used Instruments/i);
        this.fundingAccordion = getAccordionTrigger(page, /Funding/i);
        this.collapseAllButtons = page.getByRole('button', { name: /Collapse all field groups/i });
        this.expandAllButtons = page.getByRole('button', { name: /Expand all field groups/i });

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
        this.abstractCharacterCount = page.getByTestId('abstract-character-count');

        // Save Button - use data-testid for stability
        this.saveButton = page.getByTestId('save-resource-button');
        this.saveButtonTooltip = page.locator('[role="tooltip"]');
    }

    /**
     * Navigate to the editor page
     */
    async goto() {
        // Validation contexts restore a session whose worker already completed
        // real UI login and CSRF warmup. The editor's optional background refresh
        // is not a prerequisite for these form-validation scenarios.
        await this.page.goto('/editor');
    }

    /**
     * Wait for the form to be fully loaded
     */
    async waitForFormLoad() {
        await expect(this.resourceInfoSection).toBeVisible({ timeout: 10_000 });
        await expect(this.saveButton).toBeVisible();
    }

    /**
     * Expand an accordion section by clicking its trigger
     */
    async expandAccordion(accordion: Locator) {
        const isExpanded = await accordion.getAttribute('aria-expanded');
        if (isExpanded !== 'true') {
            await accordion.click();
        }
        await expect(accordion).toHaveAttribute('aria-expanded', 'true');
    }

    /**
     * Collapse an accordion section
     */
    async collapseAccordion(accordion: Locator) {
        const isExpanded = await accordion.getAttribute('aria-expanded');
        if (isExpanded === 'true') {
            await accordion.click();
        }
        await expect(accordion).toHaveAttribute('aria-expanded', 'false');
    }

    /**
     * Get the status badge for a static or accordion form section
     * Returns the aria-label of the badge icon
     */
    async getSectionStatusBadge(section: Locator): Promise<string | null> {
        const badge = section
            .locator('svg[aria-label="Section complete"], svg[aria-label="Section incomplete or has errors"], svg[aria-label="Optional section"]')
            .first();
        if ((await badge.count()) === 0) {
            return null;
        }
        return await badge.getAttribute('aria-label');
    }

    async expectSectionStatus(section: Locator, status: 'Section complete' | 'Section incomplete or has errors' | 'Optional section') {
        await expect.poll(() => this.getSectionStatusBadge(section)).toBe(status);
    }

    /**
     * Get validation messages for a field (errors, warnings, success)
     */
    async getFieldValidationMessages(fieldLocator: Locator): Promise<string[]> {
        // Get aria-describedby to find validation feedback element
        const describedBy = await fieldLocator.getAttribute('aria-describedby');
        if (!describedBy) return [];

        // Split by space and find all feedback IDs
        const ids = describedBy.split(/\s+/).filter((id) => id.includes('feedback'));
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
     * Assert that a field has validation error styling.
     * Waits up to `timeout` ms for `aria-invalid="true"` to appear,
     * which avoids flaky failures on slow CI runners.
     * Throws Playwright's full assertion error with attribute-diff output on failure.
     */
    async expectValidationError(fieldLocator: Locator, timeout = 5000): Promise<void> {
        await expect(fieldLocator).toHaveAttribute('aria-invalid', 'true', { timeout });
    }

    /**
     * Assert that a field has validation success styling.
     * Waits up to `timeout` ms for `aria-invalid` to NOT be "true".
     * Throws Playwright's full assertion error with attribute-diff output on failure.
     */
    async expectValidationSuccess(fieldLocator: Locator, timeout = 5000): Promise<void> {
        // Wait until the attribute is either absent, empty, or "false"
        await expect(fieldLocator).not.toHaveAttribute('aria-invalid', 'true', { timeout });
    }

    /**
     * Fill the main title field and trigger blur
     */
    async fillMainTitle(title: string) {
        await this.mainTitleInput.fill(title);
        await this.mainTitleInput.blur();
    }

    /**
     * Fill the year field and trigger blur
     */
    async fillYear(year: string) {
        await this.yearInput.fill(year);
        await this.yearInput.blur();
    }

    /**
     * Fill the abstract field and trigger blur
     */
    async fillAbstract(text: string) {
        await this.expandAccordion(this.descriptionsAccordion);
        await this.abstractTextarea.fill(text);
        await expect(this.abstractCharacterCount).toContainText(`${text.trim().length.toLocaleString('en-US')} characters`);
        await this.abstractTextarea.blur();
        await expect(this.abstractTextarea).toHaveValue(text);
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
        await expect(this.saveButtonTooltip).toBeVisible();
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
     * Click Save and wait for the global validation error alert to appear.
     * Returns the text content of the ValidationAlert.
     */
    async clickSaveAndWaitForValidationAlert(): Promise<string> {
        await this.saveButton.click();
        const alert = this.page.getByTestId('global-validation-alert');
        await alert.waitFor({ state: 'visible', timeout: 10000 });
        const text = await alert.textContent();
        return text?.trim() || '';
    }

    /**
     * Check whether the global ValidationAlert is visible
     */
    async isValidationAlertVisible(): Promise<boolean> {
        const alert = this.page.getByTestId('global-validation-alert');
        return await alert.isVisible();
    }

    /**
     * Get the text content of the global ValidationAlert
     */
    async getValidationAlertText(): Promise<string> {
        const alert = this.page.getByTestId('global-validation-alert');
        const text = await alert.textContent();
        return text?.trim() || '';
    }

    private async selectOption(trigger: Locator, option: Locator, method: 'keyboard' | 'pointer' = 'pointer') {
        await trigger.click();
        await expect(trigger).toHaveAttribute('aria-expanded', 'true');
        await expect(option).toBeVisible();
        if (method === 'keyboard') {
            // Radix Select options can receive focus; Enter also avoids pointer
            // races when Firefox repositions or scrolls the open list.
            await option.focus();
            await expect(option).toBeFocused();
            await option.press('Enter');
        } else {
            // Command search-list items keep focus on their search input.
            await option.click();
        }
        await expect(trigger).toHaveAttribute('aria-expanded', 'false');
        // Radix restores focus after its closing animation. Starting another
        // interaction earlier can close the next dropdown or interrupt input.
        await expect(trigger).toBeFocused();
    }

    async fillResourceInfo(title = 'Test Dataset for Validation E2E') {
        await this.fillMainTitle(title);
        await this.fillYear('2024');
        await this.selectOption(this.resourceTypeSelect, this.page.getByRole('option', { name: /Dataset/i }).first(), 'keyboard');
        await this.selectOption(this.languageSelect, this.page.getByRole('option', { name: /English/i }).first(), 'keyboard');
        await this.selectOption(
            this.page.getByTestId('datacenter-select'),
            this.page.getByRole('option').filter({ hasNotText: 'Clear selection' }).first(),
        );
        await this.expectSectionStatus(this.resourceInfoSection, 'Section complete');
    }

    /** Fill real UI fixtures without adding duplicate empty author/date rows. */
    async fillAllRequiredFields() {
        await this.fillResourceInfo();
        await this.expandAccordion(this.licensesAccordion);
        await this.selectOption(this.primaryLicenseSelect, this.page.getByRole('option', { name: /Creative Commons Attribution 4\.0/i }).first());
        await this.fillAbstract('This is a comprehensive test abstract for validating a complete metadata submission.');
        await this.expandAccordion(this.authorsAccordion);
        const lastName = this.page.locator('input[id$="-lastName"]').first();
        if ((await lastName.count()) === 0) {
            await this.page.getByRole('button', { name: /Add.*Author/i }).first().click();
        }
        await lastName.fill('Testauthor');
        await lastName.blur();

        for (const section of [this.licensesAccordion, this.descriptionsAccordion, this.authorsAccordion]) {
            await this.expectSectionStatus(section, 'Section complete');
        }
        await this.expectSectionStatus(this.datesAccordion, 'Optional section');
        await expect(this.saveButton).toBeEnabled();
    }

    /**
     * Clear all form fields
     */
    async clearAllFields() {
        await this.mainTitleInput.clear();
        await this.yearInput.clear();
        await this.versionInput.clear();

        // Clear select fields by selecting empty/first option
        // Note: This might not work for all select implementations
        // If selects don't have empty option, this will fail silently

        await this.expandAccordion(this.descriptionsAccordion);
        await this.abstractTextarea.clear();

        await expect(this.abstractTextarea).toHaveValue('');
    }
}

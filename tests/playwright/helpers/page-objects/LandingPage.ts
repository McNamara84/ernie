import { expect, type Locator, type Page } from '@playwright/test';

/**
 * Page Object Model for the public Landing Page
 *
 * Handles all interactions with the landing page components:
 * - Header section (title, DOI, citation)
 * - Abstract section
 * - Creators/Authors section
 * - Contributors section
 * - Related Works section
 * - Funding section
 * - GeoLocation/Map section
 * - Subjects/Keywords section
 * - License section
 */
export class LandingPage {
  readonly page: Page;

  // Header section
  readonly title: Locator;
  readonly doi: Locator;
  readonly citationButton: Locator;
  readonly citationModal: Locator;

  // Abstract section
  readonly abstractSection: Locator;
  readonly abstractText: Locator;

  // Creators section
  readonly creatorsSection: Locator;
  readonly creatorsList: Locator;

  // Contributors section
  readonly contributorsSection: Locator;
  readonly contributorsList: Locator;

  // Related Works section
  readonly relatedWorksSection: Locator;
  readonly relatedWorksList: Locator;

  // Funding section
  readonly fundingSection: Locator;
  readonly fundingList: Locator;

  // GeoLocation section
  readonly geoLocationSection: Locator;
  readonly mapContainer: Locator;

  // Subjects/Keywords section
  readonly subjectsSection: Locator;
  readonly keywordsList: Locator;

  // License section
  readonly licenseSection: Locator;

  // Files section
  readonly filesSection: Locator;
  readonly downloadButton: Locator;
  readonly contactFormButton: Locator;
  readonly noDownloadMessage: Locator;

  constructor(page: Page) {
    this.page = page;

    // Header elements
    this.title = page.locator('h1').first();
    this.doi = page.locator('[data-testid="doi-badge"], a[href*="doi.org"]').first();
    this.citationButton = page.getByRole('button', { name: /Cite|Citation/i });
    this.citationModal = page.locator('[role="dialog"]');

    // Abstract section
    this.abstractSection = page.locator('[data-testid="abstract-section"], section:has-text("Abstract")').first();
    this.abstractText = this.abstractSection.locator('p, [data-testid="abstract-text"]').first();

    // Creators section
    this.creatorsSection = page.locator('[data-testid="creators-section"], section:has-text("Authors")').first();
    this.creatorsList = this.creatorsSection.locator('ul, [data-testid="creators-list"]');

    // Contributors section
    this.contributorsSection = page.locator('[data-testid="contributors-section"], section:has-text("Contributors")').first();
    this.contributorsList = this.contributorsSection.locator('ul, [data-testid="contributors-list"]');

    // Related Works section
    this.relatedWorksSection = page.locator('[data-testid="related-works-section"], section:has-text("Related")').first();
    this.relatedWorksList = this.relatedWorksSection.locator('ul, [data-testid="related-works-list"]');

    // Funding section
    this.fundingSection = page.locator('[data-testid="funding-section"], section:has-text("Funding")').first();
    this.fundingList = this.fundingSection.locator('ul, [data-testid="funding-list"]');

    // GeoLocation section
    this.geoLocationSection = page.locator('[data-testid="geolocation-section"], section:has-text("Location")').first();
    this.mapContainer = page.locator('.leaflet-container, [data-testid="map-container"]').first();

    // Subjects/Keywords section
    this.subjectsSection = page.locator('[data-testid="subjects-section"], section:has-text("Keywords")').first();
    this.keywordsList = this.subjectsSection.locator('[data-testid="keywords-list"], .flex.flex-wrap');

    // License section - licenses are displayed within the Files section
    this.licenseSection = page.locator('[data-testid="license-section"]').first();

    // Files section (contains licenses) - look for heading "Files" and its parent container
    this.filesSection = page.locator('[data-testid="files-section"]').or(
      page.locator(':has(> h3:text("Files"))').first()
    );
    this.downloadButton = this.filesSection.locator('a:has-text("Download data and description")');
    // Contact form is now a button (opens modal), not a link
    this.contactFormButton = this.filesSection.locator('button:has-text("Request data via contact form")');
    this.noDownloadMessage = this.filesSection.locator('p:has-text("Download information not available")');
  }

  /**
   * Navigate to a landing page using DOI and slug (semantic URL)
   * Format: /{doiPrefix}/{slug}
   * Example: /10.5880/gfz.test.001/my-dataset-title
   */
  async gotoByDoiAndSlug(doiPrefix: string, slug: string) {
    await this.page.goto(`/${doiPrefix}/${slug}`);
  }

  /**
   * Navigate to a draft landing page (without DOI)
   * Format: /draft-{resourceId}/{slug}
   */
  async gotoDraft(resourceId: number, slug: string) {
    await this.page.goto(`/draft-${resourceId}/${slug}`);
  }

  /**
   * Navigate to a landing page by resource ID using the legacy URL.
   * This will follow the 301 redirect to the new semantic URL.
   * @deprecated Use gotoByDoiAndSlug() for new tests
   */
  async gotoByResourceId(resourceId: number) {
    await this.page.goto(`/datasets/${resourceId}`);
  }

  /**
   * Navigate to a landing page by its slug.
   * Uses the test helper API to look up the correct semantic URL.
   * This is the recommended method for tests using seeded data.
   *
   * @throws Error if landing page not found or test helper API unavailable
   * @requires APP_ENV=local or APP_ENV=testing for the test helper API to be available
   */
  async goto(slug: string) {
    // Use the test helper API to get the landing page URL by slug
    const response = await this.page.request.get(`/_test/landing-page-by-slug/${slug}`);

    if (!response.ok()) {
      const status = response.status();
      let hint: string;

      switch (status) {
        case 404:
          hint = 'Make sure test data is seeded (run: php artisan db:seed --class=PlaywrightTestSeeder)';
          break;
        case 403:
          hint = 'Access forbidden - check authentication or route middleware configuration';
          break;
        case 422:
          hint = 'Validation error - the slug format may be invalid';
          break;
        case 500:
        case 502:
        case 503:
          hint = 'Server error - check Laravel logs (storage/logs/laravel.log) for details';
          break;
        default:
          hint = `Unexpected HTTP ${status}. Check if APP_ENV is set to "local" or "testing" - the /_test/ routes are only available in dev/test environments. Also verify the Laravel server is running.`;
      }

      throw new Error(
        `Failed to load landing page with slug "${slug}" (HTTP ${status}). ${hint}`
      );
    }

    // Parse JSON response with error handling for malformed responses
    let data: { public_url: string };
    try {
      data = await response.json();
    } catch {
      const responseText = await response.text().catch(() => '<unable to read response body>');
      throw new Error(
        `Failed to parse JSON response from test helper API for slug "${slug}". ` +
        `The server returned a 200 OK but the response was not valid JSON. ` +
        `This may indicate a server-side error or misconfiguration. ` +
        `Response body (truncated): ${responseText.substring(0, 200)}`
      );
    }

    if (!data.public_url) {
      throw new Error(
        `Test helper API returned invalid data for slug "${slug}": missing public_url field. ` +
        `Response: ${JSON.stringify(data)}`
      );
    }

    // Validate that public_url is a valid relative URL path.
    // Expected formats: /10.5880/suffix/slug or /draft-123/slug
    // This prevents navigation errors from malformed API responses.
    if (!data.public_url.startsWith('/') || data.public_url.includes('://')) {
      throw new Error(
        `Test helper API returned invalid public_url for slug "${slug}": ` +
        `expected a relative path starting with '/', got: "${data.public_url}"`
      );
    }

    await this.page.goto(data.public_url);
  }

  /**
   * Verify the page has loaded successfully
   */
  async verifyPageLoaded() {
    await expect(this.title).toBeVisible({ timeout: 10000 });
  }

  /**
   * Verify the title matches expected text
   */
  async verifyTitle(expectedTitle: string) {
    await expect(this.title).toContainText(expectedTitle);
  }

  /**
   * Verify abstract section is visible and contains text
   */
  async verifyAbstractVisible(expectedText?: string) {
    await expect(this.abstractSection).toBeVisible();
    if (expectedText) {
      await expect(this.abstractText).toContainText(expectedText);
    }
  }

  /**
   * Verify abstract section is NOT visible (for control cases)
   */
  async verifyAbstractNotVisible() {
    await expect(this.abstractSection).not.toBeVisible();
  }

  /**
   * Verify creators section shows expected number of creators
   */
  async verifyCreatorsCount(expectedCount: number) {
    await expect(this.creatorsSection).toBeVisible();
    const creatorItems = this.creatorsSection.locator('li, [data-testid="creator-item"]');
    await expect(creatorItems).toHaveCount(expectedCount);
  }

  /**
   * Verify a specific creator is displayed
   */
  async verifyCreatorDisplayed(name: string) {
    await expect(this.creatorsSection).toBeVisible();
    await expect(this.creatorsSection).toContainText(name);
  }

  /**
   * Verify ORCID icons are displayed for creators
   */
  async verifyOrcidIconsDisplayed(expectedCount: number) {
    const orcidIcons = this.creatorsSection.locator('a[href*="orcid.org"], [data-testid="orcid-link"]');
    await expect(orcidIcons).toHaveCount(expectedCount);
  }

  /**
   * Verify contributors section shows expected number
   */
  async verifyContributorsCount(expectedCount: number) {
    await expect(this.contributorsSection).toBeVisible();
    const contributorItems = this.contributorsSection.locator('li, [data-testid="contributor-item"]');
    await expect(contributorItems).toHaveCount(expectedCount);
  }

  /**
   * Verify contributors section is NOT visible
   */
  async verifyContributorsNotVisible() {
    await expect(this.contributorsSection).not.toBeVisible();
  }

  /**
   * Verify related works section shows expected number
   */
  async verifyRelatedWorksCount(expectedCount: number) {
    await expect(this.relatedWorksSection).toBeVisible();
    const relatedItems = this.relatedWorksSection.locator('li, [data-testid="related-work-item"]');
    await expect(relatedItems).toHaveCount(expectedCount);
  }

  /**
   * Verify a related work DOI is displayed and clickable
   */
  async verifyRelatedWorkDoi(doi: string) {
    const doiLink = this.relatedWorksSection.locator(`a[href*="${doi}"]`);
    await expect(doiLink).toBeVisible();
  }

  /**
   * Verify related works section is NOT visible
   */
  async verifyRelatedWorksNotVisible() {
    await expect(this.relatedWorksSection).not.toBeVisible();
  }

  /**
   * Verify funding section shows expected number of funders
   */
  async verifyFundingCount(expectedCount: number) {
    await expect(this.fundingSection).toBeVisible();
    const fundingItems = this.fundingSection.locator('li, [data-testid="funding-item"]');
    await expect(fundingItems).toHaveCount(expectedCount);
  }

  /**
   * Verify funding section is NOT visible
   */
  async verifyFundingNotVisible() {
    await expect(this.fundingSection).not.toBeVisible();
  }

  /**
   * Verify geo-location section and map are visible
   */
  async verifyMapVisible() {
    await expect(this.geoLocationSection).toBeVisible();
    await expect(this.mapContainer).toBeVisible();
  }

  /**
   * Verify geo-location section is NOT visible (for resources without locations)
   */
  async verifyMapNotVisible() {
    await expect(this.geoLocationSection).not.toBeVisible();
  }

  /**
   * Verify keywords section shows expected number
   */
  async verifyKeywordsCount(expectedCount: number) {
    await expect(this.subjectsSection).toBeVisible();
    const keywordItems = this.subjectsSection.locator('[data-testid="keyword-badge"], .inline-flex, span');
    // Keywords might be rendered differently, so we check for at least the expected count
    const count = await keywordItems.count();
    expect(count).toBeGreaterThanOrEqual(expectedCount);
  }

  /**
   * Verify a specific keyword is displayed
   */
  async verifyKeywordDisplayed(keyword: string) {
    await expect(this.subjectsSection).toBeVisible();
    await expect(this.subjectsSection).toContainText(keyword);
  }

  /**
   * Verify keywords section is NOT visible
   */
  async verifyKeywordsNotVisible() {
    await expect(this.subjectsSection).not.toBeVisible();
  }

  /**
   * Verify license is visible within the files section
   */
  async verifyLicenseVisible(licenseName?: string) {
    // Check for "License" label in files section
    await expect(this.filesSection.locator('text=License')).toBeVisible();
    if (licenseName) {
      await expect(this.filesSection).toContainText(licenseName);
    }
  }

  /**
   * Verify no license is displayed in the files section
   */
  async verifyLicenseNotVisible() {
    await expect(this.filesSection.locator('text=License')).not.toBeVisible();
  }

  /**
   * Open citation modal
   */
  async openCitationModal() {
    await this.citationButton.click();
    await expect(this.citationModal).toBeVisible();
  }

  /**
   * Verify citation contains expected text
   */
  async verifyCitationContains(text: string) {
    await this.openCitationModal();
    await expect(this.citationModal).toContainText(text);
  }

  /**
   * Get the current page URL
   */
  async getCurrentUrl(): Promise<string> {
    return this.page.url();
  }

  // =========================================================================
  // Files Section Methods (Issue #373)
  // =========================================================================

  /**
   * Verify the files section is visible
   */
  async verifyFilesSectionVisible() {
    await expect(this.filesSection).toBeVisible();
  }

  /**
   * Verify download button is visible and has correct URL
   */
  async verifyDownloadButtonVisible(expectedUrl?: string) {
    await expect(this.downloadButton).toBeVisible();
    if (expectedUrl) {
      await expect(this.downloadButton).toHaveAttribute('href', expectedUrl);
    }
  }

  /**
   * Verify download button is NOT visible (for resources without FTP URL)
   */
  async verifyDownloadButtonNotVisible() {
    await expect(this.downloadButton).not.toBeVisible();
  }

  /**
   * Verify contact form button is visible
   */
  async verifyContactFormButtonVisible() {
    await expect(this.contactFormButton).toBeVisible();
  }

  /**
   * Verify contact form button is NOT visible
   */
  async verifyContactFormButtonNotVisible() {
    await expect(this.contactFormButton).not.toBeVisible();
  }

  /**
   * Verify fallback message is shown when no download options are available
   */
  async verifyNoDownloadMessageVisible() {
    await expect(this.noDownloadMessage).toBeVisible();
  }

  /**
   * Verify fallback message is NOT visible
   */
  async verifyNoDownloadMessageNotVisible() {
    await expect(this.noDownloadMessage).not.toBeVisible();
  }

  // =========================================================================
  // License Methods (Full License Names & CC Icons)
  // Note: Licenses are displayed within the Files section
  // =========================================================================

  /**
   * Verify a license link is displayed with full name.
   * The license name should be the complete SPDX name like
   * "Creative Commons Attribution 4.0 International"
   */
  async verifyLicenseFullName(fullName: string) {
    const licenseLink = this.filesSection.locator(`a:has-text("${fullName}")`);
    await expect(licenseLink).toBeVisible();
  }

  /**
   * Verify a license link has correct href
   */
  async verifyLicenseLinkHref(licenseName: string, expectedUrl: string) {
    const licenseLink = this.filesSection.locator(`a:has-text("${licenseName}")`);
    await expect(licenseLink).toHaveAttribute('href', expectedUrl);
  }

  /**
   * Verify Creative Commons icons are displayed for a CC license.
   * Checks for the CC logo SVG elements within the files section.
   */
  async verifyCreativeCommonsIconsVisible() {
    // CC icons are rendered as SVG elements with aria-label containing "Creative Commons"
    const ccIcons = this.filesSection.locator('[aria-label*="Creative Commons"]');
    await expect(ccIcons.first()).toBeVisible();
  }

  /**
   * Verify Creative Commons icons are NOT displayed (for non-CC licenses)
   */
  async verifyCreativeCommonsIconsNotVisible() {
    const ccIcons = this.filesSection.locator('[aria-label*="Creative Commons"]');
    await expect(ccIcons).toHaveCount(0);
  }

  /**
   * Verify license has SPDX identifier in tooltip
   */
  async verifyLicenseTooltip(licenseName: string, spdxId: string) {
    const licenseLink = this.filesSection.locator(`a:has-text("${licenseName}")`);
    await expect(licenseLink).toHaveAttribute('title', `SPDX: ${spdxId}`);
  }

  /**
   * Get the count of license badges displayed
   */
  async getLicenseCount(): Promise<number> {
    // License links have title attributes starting with 'SPDX:' and href to actual license URLs
    // (e.g., creativecommons.org, opensource.org), not spdx.org
    const licenseLinks = this.filesSection.locator('a[title^="SPDX:"]');
    return await licenseLinks.count();
  }
}

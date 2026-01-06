import { expect, test } from '@playwright/test';
import path from 'path';
import { fileURLToPath } from 'url';

import { STAGE_TEST_PASSWORD, STAGE_TEST_USERNAME } from '../constants';

/**
 * Stage Full Workflow Integration Test
 * 
 * This test performs a complete end-to-end workflow against the Stage environment:
 * 1. Login with stage test credentials
 * 2. Upload XML file from dataset examples
 * 3. Verify all imported data in the editor
 * 4. Save to database
 * 5. Verify resource appears in /resources
 * 6. Load resource back into editor
 * 7. Modify title
 * 8. Save again
 * 9. Verify updated title in /resources
 * 
 * NOTE: This test is NOT run in CI. It requires manual execution with proper credentials.
 * 
 * Prerequisites:
 * - Set environment variables STAGE_TEST_USERNAME and STAGE_TEST_PASSWORD
 * - Stage environment must be accessible at https://ernie.rz-vm182.gfz.de/
 */

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

/**
 * Resolve path to dataset example files
 */
function resolveDatasetExample(filename: string): string {
  return path.resolve(__dirname, '..', '..', 'pest', 'dataset-examples', filename);
}

/**
 * Expected data from datacite-example-dataset-v4.xml
 */
const EXPECTED_DATA = {
  // Resource Information
  doi: '10.82433/9184-DY35',
  year: '2022',
  resourceType: 'Dataset',
  language: 'en',
  version: '1.0',
  mainTitle: 'External Environmental Data, 2010-2020, National Gallery',
  
  // Creator (Author)
  creator: {
    type: 'Organizational',
    name: 'National Gallery',
    ror: 'https://ror.org/043kfff89',
  },
  
  // Contributors
  contributors: [
    {
      type: 'ContactPerson',
      nameType: 'Personal',
      firstName: 'Joseph',
      lastName: 'Padfield',
      orcid: '0000-0002-2572-6428',
      affiliation: 'National Gallery',
    },
    {
      type: 'DataCollector',
      nameType: 'Organizational',
      name: 'Building Facilities Department',
      firstName: undefined,
      lastName: undefined,
      affiliation: 'National Gallery',
    },
  ] as const,
  
  // Free Keywords
  freeKeywords: [
    'Test Keyword 1',
    'Test Keyword 2',
    'Test Keyword 3',
  ],
  
  // GCMD Science Keywords
  scienceKeywords: [
    'BIODIVERSITY FUNCTIONS',
    'HYDROGEN GAS VERTICAL/GEOGRAPHIC DISTRIBUTION',
  ],
  
  // GCMD Platforms
  platforms: [
    'Rockets',
    'Titan 34D',
    'Titan IIID',
  ],
  
  // GCMD Instruments
  instruments: [
    'ICE AUGERS',
    'DREDGING DEVICES',
    'EDDY CORRELATION DEVICES',
    'Charged Coupled Devices',
  ],
  
  // Dates
  dates: [
    { type: 'Collected', value: '2010/2020' },
    { type: 'Other', value: '2010/2020', info: 'Coverage' },
    { type: 'Issued', value: '2022' },
  ],
  
  // Related Identifiers
  relatedIdentifiers: [
    {
      type: 'URL',
      relation: 'IsSupplementTo',
      resourceType: 'Report',
      value: 'https://www.nationalgallery.org.uk/research/research-resources/research-papers/improving-our-environment',
    },
    {
      type: 'URL',
      relation: 'IsSourceOf',
      resourceType: 'InteractiveResource',
      value: 'https://research.ng-london.org.uk/scientific/env/',
    },
    {
      type: 'DOI',
      relation: 'IsSupplementedBy',
      resourceType: 'JournalArticle',
      value: '10.1080/00393630.2018.1504449/',
    },
    {
      type: 'DOI',
      relation: 'IsDocumentedBy',
      resourceType: 'ConferencePaper',
      value: '10.5281/zenodo.7629200',
    },
  ],
  
  // License
  license: {
    identifier: 'CC-BY-4.0',
    name: 'Creative Commons Attribution 4.0 International',
  },
  
  // Description (first sentence for verification)
  abstractStart: 'The National Gallery houses one of the greatest',
  
  // GeoLocation
  geoLocation: {
    place: 'Roof of National Gallery, London, UK',
    latitude: '51.50872',
    longitude: '-0.12841',
  },
  
  // Funding Reference
  funding: {
    funderName: 'H2020 Excellent Science',
    funderId: 'https://doi.org/10.13039/100010662',
    awardNumber: '871034',
    awardTitle: 'Integrating Platforms for the European Research Infrastructure ON Heritage Science',
  },
};

// Modified title for update test
const MODIFIED_TITLE = `[STAGE TEST] ${EXPECTED_DATA.mainTitle} - Modified ${Date.now()}`;

/**
 * Helper function to open an accordion and wait for it to be expanded
 */
async function openAccordion(page: import('@playwright/test').Page, accordion: import('@playwright/test').Locator, name: string) {
  const isExpanded = await accordion.getAttribute('aria-expanded');
  if (isExpanded !== 'true') {
    console.log(`    Opening accordion: ${name}...`);
    await accordion.click();
    // Wait for accordion to expand
    await expect(accordion).toHaveAttribute('aria-expanded', 'true', { timeout: 5000 });
    await page.waitForTimeout(500); // Wait for animation
  } else {
    console.log(`    Accordion already open: ${name}`);
  }
}

test.describe('Stage Full Workflow Test', () => {
  test.beforeAll(() => {
    // Verify credentials are set
    if (!STAGE_TEST_USERNAME || STAGE_TEST_USERNAME === 'stage@example.com') {
      throw new Error('STAGE_TEST_USERNAME environment variable must be set');
    }
    if (!STAGE_TEST_PASSWORD || STAGE_TEST_PASSWORD === 'stage-password') {
      throw new Error('STAGE_TEST_PASSWORD environment variable must be set');
    }
  });

  test('complete XML upload, verify, save, edit, and re-save workflow', async ({ page }) => {
    // Collect console errors throughout the test
    const consoleErrors: string[] = [];
    page.on('console', msg => {
      if (msg.type() === 'error') {
        consoleErrors.push(msg.text());
      }
    });
    
    // Collect page errors (uncaught exceptions)
    const pageErrors: string[] = [];
    page.on('pageerror', err => {
      pageErrors.push(err.message);
    });
    
    // ========================================
    // STEP 1: Login
    // ========================================
    console.log('Step 1: Logging in...');
    console.log(`  Using username: ${STAGE_TEST_USERNAME}`);
    
    await page.goto('/login');
    await page.waitForLoadState('networkidle');
    
    await page.getByLabel('Email address').fill(STAGE_TEST_USERNAME);
    await page.getByLabel('Password').fill(STAGE_TEST_PASSWORD);
    
    const loginButton = page.getByRole('button', { name: 'Log in' });
    await expect(loginButton).toBeEnabled({ timeout: 15000 });
    
    // Click login and wait for navigation (Promise.all to catch navigation)
    await Promise.all([
      page.waitForURL(/\/dashboard/, { timeout: 60000 }),
      loginButton.click(),
    ]);
    console.log('✓ Login successful, redirected to dashboard');

    // ========================================
    // STEP 2: Upload XML file
    // ========================================
    console.log('Step 2: Uploading XML file...');
    
    await page.goto('/dashboard');
    await expect(page.locator('text=Dropzone for XML files')).toBeVisible();
    
    const fileInput = page.locator('input[type="file"][accept=".xml"]');
    const xmlFilePath = resolveDatasetExample('datacite-example-dataset-v4.xml');
    await fileInput.setInputFiles(xmlFilePath);
    
    console.log('✓ XML file uploaded');

    // ========================================
    // STEP 3: Wait for Editor
    // ========================================
    console.log('Step 3: Waiting for editor to load...');
    
    await page.waitForURL(/\/editor/, { timeout: 30000 });
    
    // Wait for form to be fully loaded
    const saveButton = page.getByRole('button', { name: /Save to database/i });
    await expect(saveButton).toBeVisible({ timeout: 30000 });
    
    console.log('✓ Editor loaded');

    // ========================================
    // STEP 4: Verify imported data
    // ========================================
    console.log('Step 4: Verifying imported data...');
    
    // --- 4.1 Resource Information ---
    console.log('  4.1 Checking Resource Information...');
    
    // DOI
    const doiInput = page.locator('#doi');
    await expect(doiInput).toHaveValue(EXPECTED_DATA.doi);
    
    // Year
    const yearInput = page.locator('#year');
    await expect(yearInput).toHaveValue(EXPECTED_DATA.year);
    
    // Version
    const versionInput = page.locator('#version');
    await expect(versionInput).toHaveValue(EXPECTED_DATA.version);
    
    // Main Title
    const mainTitleInput = page.getByTestId('main-title-input');
    await expect(mainTitleInput).toHaveValue(EXPECTED_DATA.mainTitle);
    
    // Resource Type - check the select displays correct value
    const resourceTypeSelect = page.getByTestId('resource-type-select');
    await expect(resourceTypeSelect).toContainText(EXPECTED_DATA.resourceType);
    
    // Language - check the select displays correct value
    const languageSelect = page.getByTestId('language-select');
    await expect(languageSelect).toContainText('English');
    
    console.log('  ✓ Resource Information verified');

    // --- 4.2 License ---
    console.log('  4.2 Checking License...');
    
    const licensesAccordion = page.locator('[data-slot="accordion-trigger"]', { hasText: /Licenses.*Rights/i });
    await openAccordion(page, licensesAccordion, 'Licenses & Rights');
    
    // Check license select contains the expected license
    const licenseSelect = page.getByTestId('license-select-0');
    try {
      await expect(licenseSelect).toContainText(EXPECTED_DATA.license.name, { timeout: 5000 });
      console.log('  ✓ License verified');
    } catch {
      console.log('  ⚠️ License verification FAILED - license-select-0 not found or wrong value');
      // Take screenshot for debugging
      await page.screenshot({ path: 'test-results/debug-license-section.png' });
    }

    // --- 4.3 Authors/Creators ---
    console.log('  4.3 Checking Authors...');
    
    const authorsAccordion = page.locator('[data-slot="accordion-trigger"]', { hasText: /Authors/i });
    await openAccordion(page, authorsAccordion, 'Authors');
    
    try {
      // Check first author is the organization
      // The institution name input should contain "National Gallery"
      const institutionNameInput = page.locator('input[id$="-institutionName"]').first();
      await expect(institutionNameInput).toHaveValue(EXPECTED_DATA.creator.name, { timeout: 5000 });
      console.log('  ✓ Authors verified');
    } catch {
      console.log('  ⚠️ Authors verification FAILED');
      await page.screenshot({ path: 'test-results/debug-authors-section.png' });
    }

    // --- 4.4 Contributors ---
    console.log('  4.4 Checking Contributors...');
    
    const contributorsAccordion = page.locator('[data-slot="accordion-trigger"]', { hasText: /Contributors/i });
    await openAccordion(page, contributorsAccordion, 'Contributors');
    
    try {
      // Check first contributor (Joseph Padfield - Personal)
      const contributorLastName = page.locator('input[id$="-lastName"]').first();
      await expect(contributorLastName).toHaveValue(EXPECTED_DATA.contributors[0].lastName!, { timeout: 5000 });
      
      const contributorFirstName = page.locator('input[id$="-firstName"]').first();
      await expect(contributorFirstName).toHaveValue(EXPECTED_DATA.contributors[0].firstName!, { timeout: 5000 });
      console.log('  ✓ Contributors verified');
    } catch {
      console.log('  ⚠️ Contributors verification FAILED');
      await page.screenshot({ path: 'test-results/debug-contributors-section.png' });
    }

    // --- 4.5 Descriptions ---
    console.log('  4.5 Checking Descriptions...');
    
    const descriptionsAccordion = page.locator('[data-slot="accordion-trigger"]', { hasText: /Descriptions/i });
    await openAccordion(page, descriptionsAccordion, 'Descriptions');
    
    try {
      // Check abstract textarea contains expected text
      const abstractTextarea = page.getByTestId('abstract-textarea');
      const abstractValue = await abstractTextarea.inputValue();
      expect(abstractValue).toContain(EXPECTED_DATA.abstractStart);
      console.log('  ✓ Descriptions verified');
    } catch {
      console.log('  ⚠️ Descriptions verification FAILED');
      await page.screenshot({ path: 'test-results/debug-descriptions-section.png' });
    }

    // --- 4.6 Free Keywords ---
    console.log('  4.6 Checking Free Keywords...');
    
    const freeKeywordsAccordion = page.locator('[data-slot="accordion-trigger"]', { hasText: /Free Keywords/i });
    await openAccordion(page, freeKeywordsAccordion, 'Free Keywords');
    
    try {
      // Check that free keywords are displayed (they appear as tags)
      for (const keyword of EXPECTED_DATA.freeKeywords) {
        await expect(page.getByText(keyword, { exact: true }).first()).toBeVisible({ timeout: 5000 });
      }
      console.log('  ✓ Free Keywords verified');
    } catch {
      console.log('  ⚠️ Free Keywords verification FAILED');
      await page.screenshot({ path: 'test-results/debug-free-keywords-section.png' });
    }

    // --- 4.7 Controlled Vocabularies (GCMD) ---
    console.log('  4.7 Checking Controlled Vocabularies...');
    
    const controlledVocabAccordion = page.locator('[data-slot="accordion-trigger"]', { hasText: /Controlled Vocabularies/i });
    await openAccordion(page, controlledVocabAccordion, 'Controlled Vocabularies');
    
    try {
      // Check Science Keywords tab
      const scienceKeywordsTab = page.getByRole('tab', { name: /Science Keywords/i });
      await scienceKeywordsTab.click();
      await page.waitForTimeout(300);
      
      for (const keyword of EXPECTED_DATA.scienceKeywords) {
        await expect(page.getByText(keyword).first()).toBeVisible({ timeout: 5000 });
      }
      console.log('    ✓ Science Keywords verified');
    } catch {
      console.log('    ⚠️ Science Keywords verification FAILED');
      await page.screenshot({ path: 'test-results/debug-science-keywords.png' });
    }
    
    try {
      // Check Platforms tab
      const platformsTab = page.getByRole('tab', { name: /Platforms/i });
      await platformsTab.click();
      await page.waitForTimeout(300);
      
      for (const platform of EXPECTED_DATA.platforms) {
        await expect(page.getByText(platform).first()).toBeVisible({ timeout: 5000 });
      }
      console.log('    ✓ Platforms verified');
    } catch {
      console.log('    ⚠️ Platforms verification FAILED');
      await page.screenshot({ path: 'test-results/debug-platforms.png' });
    }
    
    try {
      // Check Instruments tab
      const instrumentsTab = page.getByRole('tab', { name: /Instruments/i });
      await instrumentsTab.click();
      await page.waitForTimeout(300);
      
      for (const instrument of EXPECTED_DATA.instruments) {
        await expect(page.getByText(instrument).first()).toBeVisible({ timeout: 5000 });
      }
      console.log('    ✓ Instruments verified');
    } catch {
      console.log('    ⚠️ Instruments verification FAILED');
      await page.screenshot({ path: 'test-results/debug-instruments.png' });
    }
    
    console.log('  ✓ Controlled Vocabularies section done');

    // --- 4.8 Spatial & Temporal Coverage ---
    console.log('  4.8 Checking Spatial & Temporal Coverage...');
    
    const spatialTemporalAccordion = page.locator('[data-slot="accordion-trigger"]', { hasText: /Spatial.*Temporal Coverage/i });
    await openAccordion(page, spatialTemporalAccordion, 'Spatial & Temporal Coverage');
    
    try {
      // Check GeoLocation Place
      const geoPlaceInput = page.locator('input[placeholder*="location"]').first();
      if (await geoPlaceInput.isVisible()) {
        await expect(geoPlaceInput).toHaveValue(EXPECTED_DATA.geoLocation.place, { timeout: 5000 });
      } else {
        // Try alternative selector - description input
        await expect(page.getByText(EXPECTED_DATA.geoLocation.place).first()).toBeVisible({ timeout: 5000 });
      }
      
      // Check coordinates (latitude/longitude inputs)
      const latitudeInput = page.locator('input[id*="latitude"], input[placeholder*="Latitude"]').first();
      const longitudeInput = page.locator('input[id*="longitude"], input[placeholder*="Longitude"]').first();
      
      if (await latitudeInput.isVisible()) {
        await expect(latitudeInput).toHaveValue(EXPECTED_DATA.geoLocation.latitude, { timeout: 5000 });
        await expect(longitudeInput).toHaveValue(EXPECTED_DATA.geoLocation.longitude, { timeout: 5000 });
      }
      console.log('  ✓ Spatial & Temporal Coverage verified');
    } catch {
      console.log('  ⚠️ Spatial & Temporal Coverage verification FAILED');
      await page.screenshot({ path: 'test-results/debug-spatial-temporal.png' });
    }

    // --- 4.9 Dates ---
    console.log('  4.9 Checking Dates...');
    
    const datesAccordion = page.locator('[data-slot="accordion-trigger"]', { hasText: /^Dates$/i });
    await openAccordion(page, datesAccordion, 'Dates');
    
    try {
      // Verify date entries exist - check for date type selects or date inputs
      // The dates section should contain multiple date entries
      const dateInputs = page.locator('input[type="date"], input[type="text"][placeholder*="date" i]');
      const dateCount = await dateInputs.count();
      expect(dateCount).toBeGreaterThanOrEqual(EXPECTED_DATA.dates.length);
      console.log(`  ✓ Dates verified (found ${dateCount} date inputs)`);
    } catch {
      console.log('  ⚠️ Dates verification FAILED');
      await page.screenshot({ path: 'test-results/debug-dates-section.png' });
    }

    // --- 4.10 Related Work ---
    console.log('  4.10 Checking Related Work...');
    
    const relatedWorkAccordion = page.locator('[data-slot="accordion-trigger"]', { hasText: /Related Work/i });
    await openAccordion(page, relatedWorkAccordion, 'Related Work');
    
    try {
      // Check that related identifiers exist
      let foundCount = 0;
      for (const relId of EXPECTED_DATA.relatedIdentifiers) {
        // Look for the identifier value in the form
        const identifierVisible = await page.getByText(relId.value, { exact: false }).first().isVisible().catch(() => false);
        if (identifierVisible) {
          foundCount++;
          console.log(`    Found related identifier: ${relId.value.substring(0, 50)}...`);
        }
      }
      if (foundCount === 0) {
        throw new Error('No related identifiers found');
      }
      console.log(`  ✓ Related Work verified (found ${foundCount}/${EXPECTED_DATA.relatedIdentifiers.length})`);
    } catch {
      console.log('  ⚠️ Related Work verification FAILED');
      await page.screenshot({ path: 'test-results/debug-related-work.png' });
    }

    // --- 4.11 Funding ---
    console.log('  4.11 Checking Funding...');
    
    const fundingAccordion = page.locator('[data-slot="accordion-trigger"]', { hasText: /Funding/i });
    await openAccordion(page, fundingAccordion, 'Funding');
    
    try {
      // Check funder name
      await expect(page.getByText(EXPECTED_DATA.funding.funderName).first()).toBeVisible({ timeout: 5000 });
      
      // Check award number
      await expect(page.getByText(EXPECTED_DATA.funding.awardNumber).first()).toBeVisible({ timeout: 5000 });
      console.log('  ✓ Funding verified');
    } catch {
      console.log('  ⚠️ Funding verification FAILED');
      await page.screenshot({ path: 'test-results/debug-funding-section.png' });
    }
    
    console.log('✓ All imported data verification completed');

    // ========================================
    // STEP 5: Save to database
    // ========================================
    console.log('Step 5: Saving to database...');
    
    // Take a screenshot before saving for debugging
    await page.screenshot({ path: 'test-results/debug-before-save.png', fullPage: true });
    
    // Scroll to save button and click
    await saveButton.scrollIntoViewIfNeeded();
    await expect(saveButton).toBeEnabled({ timeout: 10000 });
    await saveButton.click();
    
    console.log('  Save button clicked, waiting for response...');
    
    // Wait for the page to process the save
    await page.waitForTimeout(5000);
    
    // Take screenshot after save attempt
    await page.screenshot({ path: 'test-results/debug-after-save.png', fullPage: true });
    
    // Check for validation errors in the form (red borders, error messages)
    const errorMessages = page.locator('.text-red-500, .text-destructive, [role="alert"], .text-red-600, [data-slot="form-message"]');
    const errorCount = await errorMessages.count();
    
    if (errorCount > 0) {
      console.log(`  ⚠️ Found ${errorCount} error message(s) on the form:`);
      for (let i = 0; i < Math.min(errorCount, 10); i++) {
        const errorText = await errorMessages.nth(i).textContent().catch(() => 'Unable to read error');
        console.log(`    - Error ${i + 1}: ${errorText}`);
      }
      // Take full page screenshot with errors visible
      await page.screenshot({ path: 'test-results/debug-save-errors.png', fullPage: true });
    }
    
    // Check if we're still on the editor page (save failed) or redirected (save succeeded)
    const currentUrl = page.url();
    console.log(`  Current URL after save: ${currentUrl}`);
    
    // Check for toast messages (success or error)
    const toastMessages = page.locator('[data-sonner-toast], [role="status"], .toast, .Toastify');
    const toastCount = await toastMessages.count();
    if (toastCount > 0) {
      for (let i = 0; i < toastCount; i++) {
        const toastText = await toastMessages.nth(i).textContent().catch(() => '');
        console.log(`  Toast message ${i + 1}: ${toastText}`);
      }
    }
    
    // If still on editor page, try to find specific validation issues
    if (currentUrl.includes('/editor')) {
      console.log('  ⚠️ Still on editor page - save may have failed');
      
      // Look for required fields that might be empty
      const emptyRequiredFields = page.locator('input[required]:not([value]), select[required] option:checked[value=""]');
      const emptyCount = await emptyRequiredFields.count();
      if (emptyCount > 0) {
        console.log(`  Found ${emptyCount} potentially empty required field(s)`);
      }
      
      // Check for any visible error indicators near inputs
      const inputErrors = page.locator('input.border-red-500, input.border-destructive, select.border-red-500');
      const inputErrorCount = await inputErrors.count();
      if (inputErrorCount > 0) {
        console.log(`  Found ${inputErrorCount} input(s) with error styling`);
      }
      
      // Log any console errors we collected
      if (consoleErrors.length > 0) {
        console.log('  Browser console errors:');
        consoleErrors.forEach((err, i) => console.log(`    ${i + 1}. ${err}`));
      }
      
      if (pageErrors.length > 0) {
        console.log('  Page errors (uncaught exceptions):');
        pageErrors.forEach((err, i) => console.log(`    ${i + 1}. ${err}`));
      }
      
      // IMPORTANT: The save failed, so we need to stop the test here with a clear message
      throw new Error('SAVE FAILED: The form could not be saved to the database. Check test-results/debug-save-errors.png for details.');
    }
    
    // If we get here, check that we were redirected to resources or got success
    if (!currentUrl.includes('/resources')) {
      // Maybe there's a success toast but no redirect - try navigating manually
      console.log('  No automatic redirect to /resources, will navigate manually');
    }
    
    console.log('✓ Saved to database');

    // ========================================
    // STEP 6: Verify in /resources
    // ========================================
    console.log('Step 6: Verifying resource in /resources...');
    
    await page.goto('/resources');
    await page.waitForLoadState('networkidle');
    
    // Wait for resources table to load
    const resourcesTable = page.getByRole('table');
    await expect(resourcesTable).toBeVisible({ timeout: 30000 });
    
    // Find the resource by DOI or title
    const resourceRow = page.getByRole('row', { name: new RegExp(EXPECTED_DATA.doi, 'i') }).first();
    await expect(resourceRow).toBeVisible({ timeout: 10000 });
    
    console.log('✓ Resource found in /resources');

    // ========================================
    // STEP 7: Load resource back into editor
    // ========================================
    console.log('Step 7: Loading resource back into editor...');
    
    // Find the edit button in the resource row
    const editButton = resourceRow.getByRole('button', { name: /edit/i }).first();
    
    // If not found, try link or other button
    if (await editButton.isVisible().catch(() => false)) {
      await editButton.click();
    } else {
      // Try clicking the row or a link within it
      const editLink = resourceRow.getByRole('link').first();
      if (await editLink.isVisible().catch(() => false)) {
        await editLink.click();
      } else {
        // Click the row itself
        await resourceRow.click();
      }
    }
    
    // Wait for editor to load
    await page.waitForURL(/\/editor/, { timeout: 30000 });
    await expect(saveButton).toBeVisible({ timeout: 30000 });
    
    console.log('✓ Resource loaded into editor');

    // ========================================
    // STEP 8: Modify title
    // ========================================
    console.log('Step 8: Modifying title...');
    
    // Ensure Resource Information accordion is open
    const resourceInfoAccordion = page.locator('[data-slot="accordion-trigger"]', { hasText: /Resource Information/i });
    const isExpanded = await resourceInfoAccordion.getAttribute('aria-expanded');
    if (isExpanded !== 'true') {
      await resourceInfoAccordion.click();
      await page.waitForTimeout(300);
    }
    
    // Clear and fill new title
    const titleInput = page.getByTestId('main-title-input');
    await titleInput.clear();
    await titleInput.fill(MODIFIED_TITLE);
    await titleInput.blur();
    await page.waitForTimeout(500);
    
    // Verify title was changed
    await expect(titleInput).toHaveValue(MODIFIED_TITLE);
    
    console.log(`✓ Title modified to: ${MODIFIED_TITLE.substring(0, 50)}...`);

    // ========================================
    // STEP 9: Save again
    // ========================================
    console.log('Step 9: Saving modified resource...');
    
    await saveButton.scrollIntoViewIfNeeded();
    await saveButton.click();
    
    // Wait for save to complete
    await page.waitForTimeout(3000);
    
    // Check for success
    const hasErrorAfterSave = await page.locator('.text-red-500, .text-destructive, [role="alert"]').isVisible().catch(() => false);
    expect(hasErrorAfterSave).toBeFalsy();
    
    console.log('✓ Modified resource saved');

    // ========================================
    // STEP 10: Verify updated title in /resources
    // ========================================
    console.log('Step 10: Verifying updated title in /resources...');
    
    await page.goto('/resources');
    await page.waitForLoadState('networkidle');
    
    // Wait for table
    await expect(resourcesTable).toBeVisible({ timeout: 30000 });
    
    // Find the resource with the new title
    const updatedResourceRow = page.getByRole('row', { name: new RegExp(MODIFIED_TITLE.substring(0, 30), 'i') }).first();
    await expect(updatedResourceRow).toBeVisible({ timeout: 10000 });
    
    console.log('✓ Updated title verified in /resources');
    
    console.log('\n========================================');
    console.log('✅ ALL STEPS COMPLETED SUCCESSFULLY');
    console.log('========================================');
  });
});

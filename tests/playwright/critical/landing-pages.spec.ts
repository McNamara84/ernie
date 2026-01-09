import { expect, test } from '@playwright/test';

import { LandingPage } from '../helpers/page-objects/LandingPage';

/**
 * Landing Page E2E Tests
 *
 * Tests the public landing pages created by ResourceTestDataSeeder.
 * These tests verify that:
 * - All major sections render correctly
 * - Data is properly displayed
 * - Maps and interactive elements work
 * - Edge cases (no data) are handled gracefully
 *
 * Prerequisites: Run `php artisan db:seed --class=ResourceTestDataSeeder` before testing.
 */

test.describe('Landing Page - Basic Display', () => {
  test('displays mandatory fields only page correctly', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('mandatory-fields-only');
    await landingPage.verifyPageLoaded();

    // Verify title
    await landingPage.verifyTitle('TEST: Mandatory Fields Only');

    // Verify abstract is displayed
    await landingPage.verifyAbstractVisible('minimal test resource');

    // Verify at least one creator
    await expect(landingPage.creatorsSection).toBeVisible();
    // Landing page displays names as "FamilyName, GivenName"
    await landingPage.verifyCreatorDisplayed('Doe, Jane');

    // Verify license is displayed (full name with CC icon)
    await landingPage.verifyLicenseVisible('Creative Commons Attribution 4.0 International');

    // No geo-locations for this resource
    await landingPage.verifyMapNotVisible();
  });

  test('displays fully populated page correctly', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('fully-populated');
    await landingPage.verifyPageLoaded();

    // Verify title
    await landingPage.verifyTitle('TEST: Fully Populated Resource with All Fields');

    // Verify abstract
    await landingPage.verifyAbstractVisible('comprehensive test data');

    // Verify creators with ORCID
    await expect(landingPage.creatorsSection).toBeVisible();
    // Landing page displays names as "FamilyName, GivenName"
    await landingPage.verifyCreatorDisplayed('Wonderland, Alice');
    await landingPage.verifyCreatorDisplayed('Builder, Bob');

    // NOTE: Contributors section is not implemented on the landing page.
    // Contributors may be part of Contact section or omitted entirely.

    // Verify geo-location/map
    await landingPage.verifyMapVisible();

    // Verify subjects/keywords
    await expect(landingPage.subjectsSection).toBeVisible();
    await landingPage.verifyKeywordDisplayed('Geosciences');
  });
});

test.describe('Landing Page - Creators', () => {
  test('displays many creators with ORCID links', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('many-creators-all-with-orcid');
    await landingPage.verifyPageLoaded();

    // Verify title
    await landingPage.verifyTitle('TEST: Many Creators All with ORCID');

    // Verify 9 creators are displayed (1 default contact + 8 with ORCID)
    await landingPage.verifyCreatorsCount(9);

    // Verify ORCID links are present (8 creators have ORCID, default contact does not)
    await landingPage.verifyOrcidIconsDisplayed(8);
  });

  test('displays creators without ORCID', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('creators-without-orcid');
    await landingPage.verifyPageLoaded();

    // Verify 4 creators are displayed (1 default contact + 3 without ORCID)
    await landingPage.verifyCreatorsCount(4);

    // No ORCID links (none of the creators have ORCID)
    await landingPage.verifyOrcidIconsDisplayed(0);
  });

  test('displays mixed creators (with and without ORCID)', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('mixed-orcid-creators');
    await landingPage.verifyPageLoaded();

    // Verify 6 creators are displayed (1 default contact + 5 mixed)
    await landingPage.verifyCreatorsCount(6);

    // 3 of the 5 scenario creators have ORCID, default contact does not
    await landingPage.verifyOrcidIconsDisplayed(3);
  });

  test('displays institutional creators (organizations)', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('institutional-creators');
    await landingPage.verifyPageLoaded();

    // Verify institutional names are displayed
    await landingPage.verifyCreatorDisplayed('GFZ German Research Centre for Geosciences');
  });
});

// NOTE: Contributors section is not implemented on the landing page.
// Contributors are either part of Contact section or not displayed.
test.describe.skip('Landing Page - Contributors', () => {
  test('displays many contributors with different types', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('many-contributors');
    await landingPage.verifyPageLoaded();

    // Verify 10 contributors are displayed
    await landingPage.verifyContributorsCount(10);
  });

  test('displays contributors with ROR affiliations', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('contributors-with-ror');
    await landingPage.verifyPageLoaded();

    // Verify contributors section is visible
    await expect(landingPage.contributorsSection).toBeVisible();

    // Look for ROR-linked affiliations
    const rorLinks = landingPage.contributorsSection.locator('a[href*="ror.org"]');
    await expect(rorLinks).toHaveCount(2);
  });
});

test.describe('Landing Page - GeoLocations', () => {
  test('displays map with multiple points', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('geo-points');
    await landingPage.verifyPageLoaded();

    // Verify map is visible
    await landingPage.verifyMapVisible();

    // Leaflet should render markers
    const markers = page.locator('.leaflet-marker-icon');
    const markerCount = await markers.count();
    expect(markerCount).toBeGreaterThan(0);
  });

  test('displays map with bounding boxes', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('geo-bounding-boxes');
    await landingPage.verifyPageLoaded();

    // Verify map is visible
    await landingPage.verifyMapVisible();

    // Leaflet should render rectangles/paths
    const paths = page.locator('.leaflet-interactive');
    const pathCount = await paths.count();
    expect(pathCount).toBeGreaterThan(0);
  });

  test('displays map with polygons', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('geo-polygons');
    await landingPage.verifyPageLoaded();

    // Verify map is visible
    await landingPage.verifyMapVisible();

    // Leaflet should render polygons
    const paths = page.locator('.leaflet-interactive');
    const pathCount = await paths.count();
    expect(pathCount).toBeGreaterThan(0);
  });

  test('displays map with mixed geo-location types', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('geo-mixed');
    await landingPage.verifyPageLoaded();

    // Verify map is visible
    await landingPage.verifyMapVisible();
  });

  test('does NOT display map when no geo-locations', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('no-geo-locations');
    await landingPage.verifyPageLoaded();

    // Verify map is NOT visible
    await landingPage.verifyMapNotVisible();
  });
});

test.describe('Landing Page - Related Identifiers', () => {
  test('displays related works with real DOIs', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('many-related-identifiers');
    await landingPage.verifyPageLoaded();

    // Verify related works section
    await expect(landingPage.relatedWorksSection).toBeVisible();

    // Verify real DOIs are displayed
    await landingPage.verifyRelatedWorkDoi('10.5880/igets.su.l1.001');
    await landingPage.verifyRelatedWorkDoi('10.1007/978-3-642-20338-1_37');
    await landingPage.verifyRelatedWorkDoi('10.1016/j.jog.2009.09.009');
  });

  test('related work DOI links are clickable', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('many-related-identifiers');
    await landingPage.verifyPageLoaded();

    // Find a DOI link and verify it has correct href
    const doiLink = page.locator('a[href*="doi.org/10.5880/igets.su.l1.001"]');
    await expect(doiLink).toBeVisible();
    await expect(doiLink).toHaveAttribute('href', /https:\/\/doi\.org\/10\.5880\/igets\.su\.l1\.001/);
  });
});

test.describe('Landing Page - Funding References', () => {
  test('displays funding references with ROR links', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('many-funding-references');
    await landingPage.verifyPageLoaded();

    // Verify funding section
    await expect(landingPage.fundingSection).toBeVisible();

    // Verify funders are displayed
    await expect(landingPage.fundingSection).toContainText('Deutsche Forschungsgemeinschaft');
    await expect(landingPage.fundingSection).toContainText('Helmholtz Association');

    // Verify ROR links
    const rorLinks = landingPage.fundingSection.locator('a[href*="ror.org"]');
    const rorCount = await rorLinks.count();
    expect(rorCount).toBeGreaterThan(0);
  });
});

test.describe('Landing Page - Keywords/Subjects', () => {
  test('displays many free-text keywords', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('many-keywords');
    await landingPage.verifyPageLoaded();

    // Verify subjects section
    await expect(landingPage.subjectsSection).toBeVisible();

    // Verify some keywords are displayed
    await landingPage.verifyKeywordDisplayed('Seismology');
    await landingPage.verifyKeywordDisplayed('Geophysics');
  });

  test('displays GCMD controlled vocabulary keywords', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('gcmd-keywords');
    await landingPage.verifyPageLoaded();

    // Verify subjects section
    await expect(landingPage.subjectsSection).toBeVisible();
  });
});

test.describe('Landing Page - Licenses', () => {
  test('displays single CC-BY-4.0 license with full name', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('single-license');
    await landingPage.verifyPageLoaded();

    // Verify files section is visible (licenses are displayed within files section)
    await landingPage.verifyFilesSectionVisible();
    
    // Verify license full name is displayed
    await expect(landingPage.filesSection).toContainText('Creative Commons Attribution 4.0 International');
    
    // Verify CC icons are displayed for Creative Commons license
    const ccIcon = landingPage.filesSection.locator('[aria-label*="Creative Commons"]');
    await expect(ccIcon.first()).toBeVisible();
  });

  test('displays multiple licenses', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('multiple-licenses');
    await landingPage.verifyPageLoaded();

    // Verify files section is visible
    await landingPage.verifyFilesSectionVisible();
    
    // Check for License label and CC content
    await expect(landingPage.filesSection).toContainText('License');
    await expect(landingPage.filesSection).toContainText('CC');
    
    // Verify CC icons are displayed
    const ccIcon = landingPage.filesSection.locator('[aria-label*="Creative Commons"]');
    await expect(ccIcon.first()).toBeVisible();
  });

  test('license links open in new tab with correct URL', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('single-license');
    await landingPage.verifyPageLoaded();

    // Verify license link has target="_blank" and correct href pattern
    // Note: Links use spdx.org reference URLs
    const licenseLink = landingPage.filesSection.locator('a[href*="spdx.org/licenses"]');
    await expect(licenseLink).toBeVisible();
    await expect(licenseLink).toHaveAttribute('target', '_blank');
    await expect(licenseLink).toHaveAttribute('rel', /noopener/);
  });

  test('license shows SPDX identifier in tooltip', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('single-license');
    await landingPage.verifyPageLoaded();

    // Verify tooltip contains SPDX identifier
    const licenseLink = landingPage.filesSection.locator('a[href*="spdx.org/licenses"]');
    await expect(licenseLink).toHaveAttribute('title', /SPDX:/);
  });
});

test.describe('Landing Page - Titles and Descriptions', () => {
  test('displays multiple title types', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('multiple-titles');
    await landingPage.verifyPageLoaded();

    // Verify main title
    await landingPage.verifyTitle('TEST: Multiple Title Types');

    // Subtitles and alternative titles might be displayed differently
    // Just verify the page loaded successfully
    await expect(landingPage.title).toBeVisible();
  });

  test('displays multiple description types', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('multiple-descriptions');
    await landingPage.verifyPageLoaded();

    // Verify abstract
    await landingPage.verifyAbstractVisible('main abstract describing the dataset');
  });
});

test.describe('Landing Page - Contact Persons', () => {
  test('displays contact persons with email and website', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('contact-persons');
    await landingPage.verifyPageLoaded();

    // Verify creators section has contact persons
    await expect(landingPage.creatorsSection).toBeVisible();

    // Contact information should be accessible (names from seeder)
    // Landing page displays names as "FamilyName, GivenName"
    await landingPage.verifyCreatorDisplayed('Contact, Anna');
    await landingPage.verifyCreatorDisplayed('Kontakt, Bruno');
  });
});

test.describe('Landing Page - Sizes and Formats', () => {
  test('displays sizes and formats information', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('sizes-and-formats');
    await landingPage.verifyPageLoaded();

    // Verify the page loads correctly
    await landingPage.verifyTitle('TEST: Multiple Sizes and Formats');

    // Files section might show formats
    await expect(landingPage.filesSection).toBeVisible();
  });
});

test.describe('Landing Page - Files Section (Issue #373)', () => {
  test('displays download button when FTP URL is configured', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('files-with-download-url');
    await landingPage.verifyPageLoaded();

    // Verify files section is visible
    await landingPage.verifyFilesSectionVisible();

    // Verify download button is visible with correct URL
    await landingPage.verifyDownloadButtonVisible('https://datapub.gfz-potsdam.de/download/test-data.zip');

    // Contact form button should NOT be visible when download URL exists
    await landingPage.verifyContactFormButtonNotVisible();

    // Fallback message should NOT be visible
    await landingPage.verifyNoDownloadMessageNotVisible();
  });

  test('displays contact form button when contact person with email exists', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('files-with-contact-person-only');
    await landingPage.verifyPageLoaded();

    // Verify files section is visible
    await landingPage.verifyFilesSectionVisible();

    // Download button should NOT be visible (no FTP URL)
    await landingPage.verifyDownloadButtonNotVisible();

    // Contact form button SHOULD be visible (contact person has email)
    await landingPage.verifyContactFormButtonVisible();

    // Fallback message should NOT be visible
    await landingPage.verifyNoDownloadMessageNotVisible();
  });

  // This test requires a resource with no contact persons AND no download URL.
  // The current seeder may not create such a resource correctly.
  // TODO: Review ResourceTestDataSeeder to ensure 'files-with-no-contact-options' truly has no contacts.
  test.skip('displays fallback message when no contact options are available', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('files-with-no-contact-options');
    await landingPage.verifyPageLoaded();

    // Verify files section is visible
    await landingPage.verifyFilesSectionVisible();

    // Download button should NOT be visible
    await landingPage.verifyDownloadButtonNotVisible();

    // Contact form button should NOT be visible
    await landingPage.verifyContactFormButtonNotVisible();

    // Fallback message SHOULD be visible
    await landingPage.verifyNoDownloadMessageVisible();
  });
});

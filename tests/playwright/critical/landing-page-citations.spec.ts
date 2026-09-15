import { expect, test } from '@playwright/test';

import { LandingPage } from '../helpers/page-objects/LandingPage';

const normalizeVisibleText = (value: string): string => value.replace(/\s+/g, ' ').trim();

test.describe('Landing Page - Citation Standards', () => {
  test('renders the resource citation after License & Rights, switches styles and copies selected plaintext', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.installCitationClipboardStub();
    await landingPage.goto('playwright-published');
    await landingPage.verifyPageLoaded();

    await expect(landingPage.heroCitation).toContainText('Playwright: Published Resource. V. 1.0. GFZ Data Services.');
    await expect(landingPage.citationSection).toBeVisible();
    await expect(landingPage.citationStyleSelect).toHaveAttribute('data-citation-style', 'apa-7');
    await expect(landingPage.citationStyleSelect).toHaveText('APA 7');

    await landingPage.citationStyleSelect.click();
    const options = page.getByRole('option');
    await expect(options).toHaveCount(6);
    await expect(options).toHaveText([
      'APA 7',
      'Harvard (Cite Them Right)',
      'Copernicus / EGU',
      'AGU',
      'GSA',
      'GFZ Data Services (legacy)',
    ]);

    expect(await options.evaluateAll((items) => items.map((item) => item.getAttribute('data-citation-style')))).toEqual([
      'apa-7',
      'harvard',
      'copernicus',
      'agu',
      'gsa',
      'gfz',
    ]);
    await page.keyboard.press('Escape');

    const leftColumn = landingPage.citationSection.locator('xpath=..');
    const sectionIds = await leftColumn.locator(':scope > section').evaluateAll((sections) =>
      sections.map((section) => section.getAttribute('data-testid') ?? section.getAttribute('aria-labelledby')),
    );
    const filesSlotIndex = sectionIds.findIndex((sectionId) => sectionId === 'files-section' || sectionId === 'data-request-section');
    const licensesIndex = sectionIds.indexOf('license-and-rights-section');
    const citationIndex = sectionIds.indexOf('citation-section');
    expect(filesSlotIndex).toBeGreaterThanOrEqual(0);
    expect(licensesIndex).toBe(filesSlotIndex + 1);
    expect(citationIndex).toBe(licensesIndex + 1);

    const apaText = normalizeVisibleText(await landingPage.citationContent.innerText());
    expect(apaText).toContain('Version 1.0');
    expect(apaText).not.toContain('V. 1.0.');
    await landingPage.selectCitationStyle('harvard');
    await expect(landingPage.citationContent).toHaveAttribute('data-citation-style', 'harvard');
    const harvardText = normalizeVisibleText(await landingPage.citationContent.innerText());
    expect(harvardText).not.toBe(apaText);
    expect(harvardText).not.toContain('1.0');

    await landingPage.copyCitation();
    await expect
      .poll(async () => normalizeVisibleText((await landingPage.copiedCitationText()) ?? ''))
      .toBe(harvardText);

    await landingPage.selectCitationStyle('gfz');
    const gfzText = normalizeVisibleText(await landingPage.citationContent.innerText());
    expect(gfzText).toContain('Playwright: Published Resource. V. 1.0. GFZ Data Services.');

    await landingPage.copyCitation();
    await expect
      .poll(async () => normalizeVisibleText((await landingPage.copiedCitationText()) ?? ''))
      .toBe(gfzText);
  });

  test('uses the new IGSN card order and complete DOI in visible and copied citations', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.installCitationClipboardStub();
    await landingPage.gotoPreview('playwright-igsn-preview');
    await landingPage.verifyPageLoaded();

    await expect(page.getByText('Preview Mode')).toBeVisible();
    await expect(landingPage.heroCitation).toContainText('Playwright: IGSN Citation Preview. V. 1.0. GFZ Data Services.');
    await expect(landingPage.heroCitation).toContainText('https://doi.org/10.1234/playwright-igsn-preview');
    await expect(landingPage.citationSection).toBeVisible();
    await expect(landingPage.citationStyleSelect).toHaveAttribute('data-citation-style', 'apa-7');
    await expect(landingPage.citationStyleSelect).toHaveText('APA 7');

    const leftHeadings = await landingPage.citationSection
      .locator('xpath=..')
      .locator(':scope > section > h2')
      .allTextContents();

    expect(leftHeadings[0]).toBe('General');
    expect(leftHeadings).toContain('Cite This Resource');
    expect(leftHeadings).not.toContain('License & Rights');

    const acquisition = page.locator('section[aria-labelledby="heading-acquisition"]');
    await expect(acquisition).toBeVisible();
    const valueOffsets = await acquisition
      .locator('dd')
      .evaluateAll((values) => values.map((value) => Math.round(value.getBoundingClientRect().left)));
    expect(new Set(valueOffsets).size).toBe(1);

    await page.setViewportSize({ width: 390, height: 720 });
    await expect(acquisition).toBeVisible();
    expect(await acquisition.evaluate((section) => section.scrollWidth <= section.clientWidth)).toBe(true);

    await landingPage.selectCitationStyle('gfz');
    const gfzText = normalizeVisibleText(await landingPage.citationContent.innerText());
    expect(gfzText).toContain('Playwright: IGSN Citation Preview. V. 1.0. GFZ Data Services.');
    expect(gfzText).toContain('https://doi.org/10.1234/playwright-igsn-preview');

    await landingPage.copyCitation();
    await expect
      .poll(async () => normalizeVisibleText((await landingPage.copiedCitationText()) ?? ''))
      .toBe(gfzText);
  });

  test('keeps the DOI-less note outside the copied GFZ citation', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.installCitationClipboardStub();
    await landingPage.goto('playwright-curation');
    await landingPage.verifyPageLoaded();

    await expect(landingPage.citationDoiNote).toHaveText('DOI not yet available.');
    await landingPage.selectCitationStyle('gfz');

    const visibleCitation = await landingPage.citationContent.innerText();
    expect(visibleCitation).toContain('Playwright: Curation Resource (no DOI). V. 1.0. GFZ Data Services.');
    expect(visibleCitation).not.toContain('doi.org');
    expect(visibleCitation).not.toContain('DOI not available');

    await landingPage.copyCitation();
    await expect.poll(async () => landingPage.copiedCitationText()).not.toBeNull();
    const copied = (await landingPage.copiedCitationText()) ?? '';

    expect(copied).toBe(visibleCitation);
    expect(copied).not.toContain('doi.org');
    expect(copied).not.toContain('DOI not available');
    expect(copied).not.toContain('DOI not yet available.');
  });
});

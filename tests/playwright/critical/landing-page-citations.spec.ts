import { expect, test } from '@playwright/test';

import { LandingPage } from '../helpers/page-objects/LandingPage';

const normalizeVisibleText = (value: string): string => value.replace(/\s+/g, ' ').trim();

test.describe('Landing Page - Citation Standards', () => {
  test('renders the resource module after files, switches styles and copies selected plaintext', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.installCitationClipboardStub();
    await landingPage.goto('playwright-published');
    await landingPage.verifyPageLoaded();

    await expect(landingPage.citationSection).toBeVisible();
    await expect(landingPage.citationStyleSelect).toHaveValue('apa-7');

    const options = landingPage.citationStyleSelect.locator('option');
    await expect(options).toHaveCount(6);
    await expect(options).toHaveText([
      'APA 7',
      'Harvard (Cite Them Right)',
      'Copernicus / EGU',
      'AGU',
      'GSA',
      'GFZ Data Services (legacy)',
    ]);

    const leftColumn = landingPage.citationSection.locator('xpath=..');
    const sectionIds = await leftColumn.locator(':scope > section').evaluateAll((sections) =>
      sections.map((section) => section.getAttribute('data-testid') ?? section.getAttribute('aria-labelledby')),
    );
    expect(sectionIds.indexOf('files-section')).toBeGreaterThanOrEqual(0);
    expect(sectionIds.indexOf('citation-section')).toBeGreaterThan(sectionIds.indexOf('files-section'));

    const apaText = normalizeVisibleText(await landingPage.citationContent.innerText());
    await landingPage.selectCitationStyle('harvard');
    await expect(landingPage.citationContent).toHaveAttribute('data-citation-style', 'harvard');
    const harvardText = normalizeVisibleText(await landingPage.citationContent.innerText());
    expect(harvardText).not.toBe(apaText);

    await landingPage.copyCitation();
    await expect
      .poll(async () => normalizeVisibleText((await landingPage.copiedCitationText()) ?? ''))
      .toBe(harvardText);
  });

  test('renders the citation module after Acquisition in an IGSN preview', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.gotoPreview('playwright-igsn-preview');
    await landingPage.verifyPageLoaded();

    await expect(page.getByText('Preview Mode')).toBeVisible();
    await expect(landingPage.citationSection).toBeVisible();
    await expect(landingPage.citationStyleSelect).toHaveValue('apa-7');

    const leftHeadings = await landingPage.citationSection
      .locator('xpath=..')
      .locator(':scope > section > h2')
      .allTextContents();

    expect(leftHeadings.slice(0, 3)).toEqual(['General', 'Acquisition', 'Cite this Resource']);
  });

  test('keeps the DOI-less note outside the copied GFZ citation', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.installCitationClipboardStub();
    await landingPage.goto('playwright-curation');
    await landingPage.verifyPageLoaded();

    await expect(landingPage.citationDoiNote).toHaveText('DOI not yet available.');
    await landingPage.selectCitationStyle('gfz');

    const visibleCitation = await landingPage.citationContent.innerText();
    expect(visibleCitation).not.toContain('doi.org');
    expect(visibleCitation).not.toContain('DOI not available');

    await landingPage.copyCitation();
    const copied = await expect
      .poll(async () => landingPage.copiedCitationText())
      .not.toBeNull();

    expect(copied).not.toContain('doi.org');
    expect(copied).not.toContain('DOI not available');
    expect(copied).not.toContain('DOI not yet available.');
  });
});

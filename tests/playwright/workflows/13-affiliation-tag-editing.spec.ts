import { expect, type Locator, type Page, test } from '@playwright/test';

import { loginAsTestUser } from '../helpers/test-helpers';

const ORIGINAL_AFFILIATION = 'GFZ Helmholtz Centre for Geosciences';
const EDITED_AFFILIATION = 'GFZ Helmholtz Centre for Geosciences, Potsdam, Germany';
const GFZ_ROR_ID = 'https://ror.org/04z8jg394';

interface AffiliationSection {
  addButtonName: RegExp;
  fieldTestId: string;
  inputTestId: string;
  label: string;
  rorBadgeTestId: string;
  triggerName: RegExp;
}

const sections: AffiliationSection[] = [
  {
    addButtonName: /Add First Author/i,
    fieldTestId: 'author-0-affiliations-field',
    inputTestId: 'author-0-affiliations-input',
    label: 'author',
    rorBadgeTestId: 'author-0-affiliations-ror-ids',
    triggerName: /Authors/i,
  },
  {
    addButtonName: /Add First Contributor/i,
    fieldTestId: 'contributor-0-affiliations-field',
    inputTestId: 'contributor-0-affiliations-input',
    label: 'contributor',
    rorBadgeTestId: 'contributor-0-affiliations-ror-ids',
    triggerName: /Contributors/i,
  },
];

async function mockRorAffiliations(page: Page) {
  await page.route('**/api/v1/ror-affiliations', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      status: 200,
      body: JSON.stringify([
        {
          prefLabel: ORIGINAL_AFFILIATION,
          rorId: GFZ_ROR_ID,
          otherLabel: ['GFZ', 'GeoForschungsZentrum Potsdam'],
        },
      ]),
    });
  });
}

async function expandAccordion(page: Page, triggerName: RegExp) {
  const trigger = page.locator('[data-slot="accordion-trigger"]', { hasText: triggerName }).first();
  await trigger.waitFor({ state: 'visible', timeout: 10000 });

  if ((await trigger.getAttribute('aria-expanded')) !== 'true') {
    await trigger.scrollIntoViewIfNeeded();
    await trigger.click();
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
  }
}

async function addFirstEntry(page: Page, section: AffiliationSection): Promise<Locator> {
  await expandAccordion(page, section.triggerName);

  const addButton = page.getByRole('button', { name: section.addButtonName }).first();
  await addButton.scrollIntoViewIfNeeded();
  await addButton.click();

  const field = page.getByTestId(section.fieldTestId);
  await field.waitFor({ state: 'visible', timeout: 10000 });
  return field;
}

async function selectGfzAffiliation(page: Page, field: Locator) {
  const tagInput = field.locator('.tagify__input').first();
  await tagInput.scrollIntoViewIfNeeded();
  await tagInput.click();
  await tagInput.fill('GFZ');

  const suggestion = page.locator('.tagify__dropdown__item', { hasText: ORIGINAL_AFFILIATION }).first();
  await suggestion.waitFor({ state: 'visible', timeout: 10000 });
  await suggestion.click();

  await expect(field.locator('.tagify__tag-text').first()).toHaveText(ORIGINAL_AFFILIATION);
}

async function startInlineTagEdit(page: Page, section: AffiliationSection, field: Locator): Promise<Locator> {
  const tagText = field.locator('.tagify__tag-text').first();
  await tagText.scrollIntoViewIfNeeded();
  await tagText.dblclick();

  const editableTagText = field.locator('.tagify__tag [contenteditable], .tagify__tag[contenteditable]').first();
  try {
    await editableTagText.waitFor({ state: 'visible', timeout: 1500 });
  } catch {
    await page.getByTestId(section.inputTestId).evaluate((input) => {
      const tagify = (input as HTMLInputElement & {
        tagify?: {
          editTag?: (tagElement: HTMLElement) => void;
          getTagElms?: () => HTMLElement[];
        };
      }).tagify;
      const tagElement = tagify?.getTagElms?.()[0] ?? input.parentElement?.querySelector<HTMLElement>('.tagify__tag');

      if (!tagify?.editTag || !tagElement) {
        throw new Error('Unable to start Tagify inline editing for the affiliation tag.');
      }

      tagify.editTag(tagElement);
    });
    await editableTagText.waitFor({ state: 'visible', timeout: 5000 });
  }

  return editableTagText;
}

async function editGfzAffiliation(page: Page, section: AffiliationSection, field: Locator) {
  const editableTagText = await startInlineTagEdit(page, section, field);
  await editableTagText.fill(EDITED_AFFILIATION);
  await editableTagText.press('Enter');
}

async function expectEditedAffiliation(page: Page, section: AffiliationSection, field: Locator) {
  const tagText = field.locator('.tagify__tag-text').first();
  await expect(tagText).toHaveText(EDITED_AFFILIATION);
  await expect(page.getByTestId(section.inputTestId)).toHaveValue(EDITED_AFFILIATION);

  const visibleLabel = (await tagText.textContent())?.trim() ?? '';
  expect(visibleLabel).toBe(EDITED_AFFILIATION);
  expect(visibleLabel.startsWith('(')).toBe(false);

  const rorBadge = page.getByTestId(section.rorBadgeTestId);
  await expect(rorBadge).toContainText(EDITED_AFFILIATION);
  await expect(rorBadge).toContainText(GFZ_ROR_ID);
}

test.describe('Affiliation tag editing', () => {
  test.beforeEach(async ({ page }) => {
    await mockRorAffiliations(page);
    await loginAsTestUser(page);
    await page.goto('/editor');
  });

  for (const section of sections) {
    test(`keeps the edited GFZ ROR affiliation exact for ${section.label}s`, async ({ page }) => {
      const field = await addFirstEntry(page, section);

      await selectGfzAffiliation(page, field);
      await editGfzAffiliation(page, section, field);
      await expectEditedAffiliation(page, section, field);
    });
  }
});

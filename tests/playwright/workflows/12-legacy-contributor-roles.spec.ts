import { expect, type Page, test } from '@playwright/test';

import { loginAsTestUser } from '../helpers/test-helpers';

const contributors = [
  { institutionName: 'Geological Survey of Estonia', role: 'Data Collector' },
  { institutionName: 'GFZ Helmholtz-Zentrum für Geoforschung', role: 'Hosting Institution' },
  { institutionName: 'GEOFON Data Centre', role: 'Data Manager' },
] as const;

const buildEditorUrl = () => {
  const query = new URLSearchParams({
    'titles[0][title]': 'Legacy contributor roles',
    'titles[0][titleType]': 'main-title',
  });

  contributors.forEach((contributor, index) => {
    query.set(`contributors[${index}][type]`, 'institution');
    query.set(`contributors[${index}][position]`, String(index));
    query.set(`contributors[${index}][institutionName]`, contributor.institutionName);
    query.set(`contributors[${index}][roles][0]`, contributor.role);
  });

  return `/editor?${query.toString()}`;
};

const expandContributors = async (page: Page) => {
  const trigger = page.locator('[data-slot="accordion-trigger"]', { hasText: /Contributors/i }).first();
  await trigger.waitFor({ state: 'visible' });

  if ((await trigger.getAttribute('aria-expanded')) !== 'true') {
    await trigger.click();
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
  }
};

const getRoleWhitelist = (page: Page, index: number) =>
  page.getByTestId(`contributor-${index}-roles-input`).evaluate((input) => {
    const tagify = (input as HTMLInputElement & {
      tagify?: { whitelist?: Array<string | { value?: string }> };
    }).tagify;

    return (tagify?.whitelist ?? [])
      .map((role) => (typeof role === 'string' ? role : role.value))
      .filter((role): role is string => Boolean(role));
  });

test.describe('Legacy contributor roles (Issue #1090)', () => {
  test('keeps imported institution roles without offering them to a new institution', async ({ page }) => {
    await loginAsTestUser(page);
    await page.goto(buildEditorUrl());
    await expandContributors(page);

    for (const [index, contributor] of contributors.entries()) {
      await expect(page.getByTestId(`contributor-${index}-roles-input`)).toHaveValue(contributor.role);
      await expect(page.getByTestId(`contributor-${index}-roles-field`).locator('.tagify__tag-text')).toHaveText(contributor.role);
    }

    await page.getByRole('button', { name: 'Add another contributor' }).click();
    const typeField = page.getByTestId('contributor-3-type-field');
    await typeField.getByRole('combobox').click();
    await page.getByRole('option', { name: 'Institution', exact: true }).click();

    await expect.poll(() => getRoleWhitelist(page, 3)).not.toContain('Data Collector');
    await expect.poll(() => getRoleWhitelist(page, 3)).not.toContain('Data Manager');

    const newInstitutionRoleField = page.getByTestId('contributor-3-roles-field');
    const tagInput = newInstitutionRoleField.locator('.tagify__input');
    await tagInput.click();
    await tagInput.fill('Data Collector');
    await tagInput.press('Enter');
    await expect(newInstitutionRoleField.locator('.tagify__tag')).toHaveCount(0);

    const draftRequestPromise = page.waitForRequest((request) => request.url().includes('/editor/resources/draft') && request.method() === 'POST');
    await page.route('**/editor/resources/draft', async (route) => {
      await route.fulfill({
        contentType: 'application/json',
        status: 200,
        body: JSON.stringify({ message: 'Draft saved.', resource: { id: 1090 } }),
      });
    });

    await page.getByTestId('save-draft-button').click();
    const draftRequest = await draftRequestPromise;
    const payload = draftRequest.postDataJSON() as {
      contributors: Array<{ institutionName: string; roles: string[] }>;
    };

    expect(payload.contributors).toEqual(
      contributors.map((contributor, position) =>
        expect.objectContaining({
          type: 'institution',
          institutionName: contributor.institutionName,
          roles: [contributor.role],
          position,
        }),
      ),
    );
  });
});

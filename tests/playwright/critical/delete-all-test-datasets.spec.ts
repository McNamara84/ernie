import { expect, test } from '@playwright/test';

import { loginAsTestUser } from '../helpers/test-helpers';

test.describe('Delete all test datasets', () => {
  test.skip(
    process.env.RUN_DELETE_ALL_RESOURCES_E2E !== '1',
    'Destructive local regression: set RUN_DELETE_ALL_RESOURCES_E2E=1 to delete all resources through /logs.',
  );

  test('admin can delete all resources from the logs page without a gateway timeout', async ({ page }) => {
    await loginAsTestUser(page);
    await page.goto('/logs');

    const confirmationDialog = page.getByRole('dialog', { name: 'Delete All Test Datasets' });

    await page.getByRole('button', { name: 'Delete all Test Datasets' }).click();
    await expect(confirmationDialog).toBeVisible();
    await page.locator('#delete-confirmation').fill('delete');

    const [deleteResponse] = await Promise.all([
      page.waitForResponse(
        (response) => response.url().includes('/resources/all') && response.request().method() === 'DELETE',
        { timeout: 60_000 },
      ),
      page.getByRole('button', { name: 'Delete All Resources' }).click(),
    ]);

    expect([302, 303]).toContain(deleteResponse.status());

    const redirectLocation = deleteResponse.headers().location;
    expect(redirectLocation).toBeTruthy();
    expect(new URL(redirectLocation!, page.url()).pathname).toBe('/logs');

    await expect(confirmationDialog).toBeHidden();
    await expect(page.getByRole('button', { name: 'Delete all Test Datasets' })).toBeVisible();
  });
});

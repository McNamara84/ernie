import { type BrowserContext, expect, type Page, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';
import { ResourcesPage } from '../helpers/page-objects/ResourcesPage';
import { loginAsTestUser } from '../helpers/test-helpers';

const SEEDED_RESOURCE_DOI = '10.1234/playwright-published';
const BLOCKED_FEEDBACK_MARKER = '__playwrightBlockedEditorFeedbackSeen';

async function monitorBlockedEditorFeedback(page: Page) {
  await page.evaluate((marker) => {
    const monitoredWindow = window as typeof window & Record<string, boolean>;
    const feedbackSelector = '[data-sonner-toast], [data-testid=blocked-editor-tabs-dialog]';
    const blockedFeedbackIsPresent = () =>
      Array.from(document.querySelectorAll(feedbackSelector)).some((element) => /browser blocked/i.test(element.textContent ?? ''));

    monitoredWindow[marker] = blockedFeedbackIsPresent();

    const observer = new MutationObserver(() => {
      if (blockedFeedbackIsPresent()) {
        monitoredWindow[marker] = true;
      }
    });

    observer.observe(document.body, { childList: true, subtree: true, characterData: true });
  }, BLOCKED_FEEDBACK_MARKER);
}

async function expectNoBlockedEditorFeedback(page: Page) {
  const blockedFeedbackWasSeen = await page.evaluate(
    (marker) => Boolean((window as typeof window & Record<string, boolean>)[marker]),
    BLOCKED_FEEDBACK_MARKER,
  );

  expect(blockedFeedbackWasSeen).toBe(false);
  await expect(page.locator('[data-sonner-toast]').filter({ hasText: /browser blocked/i })).toHaveCount(0);
  await expect(page.getByTestId('blocked-editor-tabs-dialog')).toHaveCount(0);
}

async function expectEditorPopup(context: BrowserContext, resourcesPage: Page, openEditor: () => Promise<void>) {
  const popupPromise = context.waitForEvent('page');
  await openEditor();
  const editorPage = await popupPromise;

  try {
    await editorPage.waitForURL((url) => url.pathname === '/editor' && /^\d+$/.test(url.searchParams.get('resourceId') ?? ''), {
      timeout: 30_000,
    });
    await expect(resourcesPage).toHaveURL((url) => url.pathname === '/resources');
    await expectNoBlockedEditorFeedback(resourcesPage);
    expect(await editorPage.evaluate(() => window.opener)).toBeNull();
  } finally {
    await editorPage.close();
  }
}

// Resources Management Basic Tests
// Verifies resources page is accessible.

test.describe('Resources Management', () => {
  test('resources page requires authentication', async ({ page }) => {
    // Try to access resources without login
    await page.goto('/resources');
    
    // Should redirect to login
    await expect(page).toHaveURL(/\/login/);
  });

  test('resources page is accessible after login', async ({ page }) => {
    // Login first
    await page.goto('/login');
    await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
    await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL(/\/dashboard/, { timeout: 15000 });
    
    // Navigate to resources
    await page.goto('/resources');
    
    // Should be accessible
    await expect(page).toHaveURL(/\/resources/);
  });

  test('successful single-resource editor openings show no blocked-tab feedback', async ({ page, context }) => {
    await loginAsTestUser(page);

    const resourcesPage = new ResourcesPage(page);
    await resourcesPage.goto();
    await resourcesPage.verifyOnResourcesPage();
    await resourcesPage.search(SEEDED_RESOURCE_DOI);

    const resourceCheckbox = page.getByRole('checkbox', { name: `Select resource ${SEEDED_RESOURCE_DOI}` });
    const resourceTitle = page.getByText('Playwright: Published Resource', { exact: true });

    await expect(resourceCheckbox).toBeVisible();
    await expect(resourceTitle).toBeVisible();
    await monitorBlockedEditorFeedback(page);

    await expectEditorPopup(context, page, () => resourceTitle.click());

    await resourceCheckbox.click();
    await expect(page.getByText('1 resource selected', { exact: true })).toBeVisible();

    await expectEditorPopup(context, page, () => page.getByTestId('resources-action-edit').click());
  });
});

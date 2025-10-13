import fs from 'node:fs';
import path from 'node:path';

import { type Locator, type Page } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';
import { LoginPage } from './page-objects/LoginPage';

/**
 * Perform login as the default test user and wait for dashboard
 * 
 * @param page - Playwright page object
 * @param email - User email (defaults to TEST_USER_EMAIL)
 * @param password - User password (defaults to TEST_USER_PASSWORD)
 */
export async function loginAsTestUser(
  page: Page,
  email: string = TEST_USER_EMAIL,
  password: string = TEST_USER_PASSWORD,
) {
  const loginPage = new LoginPage(page);
  await loginPage.goto();
  await loginPage.loginAndWaitForDashboard(email, password);
}

/**
 * Perform logout
 * 
 * @param page - Playwright page object
 */
export async function logout(page: Page) {
  // Click user menu
  const userMenu = page.getByRole('button', { name: /User menu|Profile/i });
  await userMenu.click();
  
  // Click logout
  const logoutButton = page.getByRole('menuitem', { name: /Logout|Sign out/i });
  await logoutButton.click();
  
  // Wait for redirect to login page
  await page.waitForURL('/login', { timeout: 10000 });
}

/**
 * Wait for an accordion to reach the specified expanded state
 * 
 * @param accordionButton - The accordion trigger button
 * @param expanded - Whether accordion should be expanded (true) or collapsed (false)
 */
export async function waitForAccordionState(
  accordionButton: Locator,
  expanded: boolean,
) {
  const expectedState = String(expanded);
  await accordionButton.waitFor({ state: 'visible' });
  
  // Wait for aria-expanded attribute to match expected state
  await accordionButton.evaluate((el: HTMLElement, state: string) => {
    return new Promise<void>((resolve) => {
      const checkState = () => {
        if (el.getAttribute('aria-expanded') === state) {
          resolve();
        } else {
          setTimeout(checkState, 100);
        }
      };
      checkState();
    });
  }, expectedState);
}

/**
 * Resolve path to a dataset example file
 * 
 * @param fileName - Name of the dataset example file
 * @returns Absolute path to the file
 */
export function resolveDatasetExample(fileName: string): string {
  // Try to determine the correct path
  const possiblePaths = [
    path.resolve(__dirname, '..', '..', 'pest', 'dataset-examples', fileName),
    path.resolve(process.cwd(), 'tests', 'pest', 'dataset-examples', fileName),
  ];
  
  for (const candidatePath of possiblePaths) {
    if (fs.existsSync(candidatePath)) {
      return candidatePath;
    }
  }
  
  throw new Error(
    `Unable to locate dataset example "${fileName}" in any of the expected locations: ${possiblePaths.join(', ')}`,
  );
}

/**
 * Wait for navigation to complete
 * 
 * @param page - Playwright page object
 * @param urlPattern - URL pattern to wait for (regex or string)
 * @param timeout - Timeout in milliseconds (default: 10000)
 */
export async function waitForNavigation(
  page: Page,
  urlPattern: RegExp | string,
  timeout: number = 10000,
) {
  await page.waitForURL(urlPattern, { timeout });
  
  // Also wait for network idle to ensure page is fully loaded
  await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => {
    // Ignore timeout - networkidle is optional
  });
}

/**
 * Wait for a debounced action to complete
 * Useful after typing in search fields, filters, etc.
 * 
 * @param page - Playwright page object
 * @param ms - Milliseconds to wait (default: 500)
 */
export async function waitForDebounce(page: Page, ms: number = 500) {
  await page.waitForTimeout(ms);
}

/**
 * Clear local storage
 * 
 * @param page - Playwright page object
 */
export async function clearLocalStorage(page: Page) {
  await page.evaluate(() => {
    localStorage.clear();
  });
}

/**
 * Clear session storage
 * 
 * @param page - Playwright page object
 */
export async function clearSessionStorage(page: Page) {
  await page.evaluate(() => {
    sessionStorage.clear();
  });
}

/**
 * Take a screenshot with a descriptive name
 * 
 * @param page - Playwright page object
 * @param name - Screenshot name
 */
export async function takeScreenshot(page: Page, name: string) {
  await page.screenshot({
    path: `test-results/${name}-${Date.now()}.png`,
    fullPage: true,
  });
}

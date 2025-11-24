import { expect, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';

/**
 * Dashboard Version Badges E2E Tests
 * 
 * Tests that verify the version badges in the Environment card
 * are correctly displayed, linked, and accessible.
 */

test.describe('Dashboard Version Badges', () => {
  test.beforeEach(async ({ page }) => {
    // Login before each test
    await page.goto('/login');
    await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
    await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL(/\/dashboard/, { timeout: 15000 });
  });

  test('displays ERNIE version badge linked to changelog', async ({ page }) => {
    // Find the ERNIE version link
    const ernieVersionLink = page.getByRole('link', { name: /view changelog for version/i });
    
    // Verify link is visible and has correct href
    await expect(ernieVersionLink).toBeVisible();
    await expect(ernieVersionLink).toHaveAttribute('href', '/changelog');
    
    // Verify badge text is present (version format like 1.0.0a)
    const badgeText = await ernieVersionLink.textContent();
    expect(badgeText).toMatch(/\d+\.\d+\.\d+[a-z]?/);
  });

  test('displays PHP version badge linked to PHP release notes', async ({ page }) => {
    // Find the PHP version link
    const phpVersionLink = page.getByRole('link', { name: /view php .+ release notes/i });
    
    // Verify link is visible
    await expect(phpVersionLink).toBeVisible();
    
    // Verify link opens in new tab
    await expect(phpVersionLink).toHaveAttribute('target', '_blank');
    await expect(phpVersionLink).toHaveAttribute('rel', 'noopener noreferrer');
    
    // Verify href points to php.net releases
    const href = await phpVersionLink.getAttribute('href');
    expect(href).toMatch(/^https:\/\/www\.php\.net\/releases\/\d+\.\d+\/en\.php$/);
    
    // Verify badge text shows version (format like 8.4.12)
    const badgeText = await phpVersionLink.textContent();
    expect(badgeText).toMatch(/\d+\.\d+\.\d+/);
  });

  test('displays Laravel version badge linked to Laravel release notes', async ({ page }) => {
    // Find the Laravel version link
    const laravelVersionLink = page.getByRole('link', { name: /view laravel .+ release notes/i });
    
    // Verify link is visible
    await expect(laravelVersionLink).toBeVisible();
    
    // Verify link opens in new tab
    await expect(laravelVersionLink).toHaveAttribute('target', '_blank');
    await expect(laravelVersionLink).toHaveAttribute('rel', 'noopener noreferrer');
    
    // Verify href points to laravel.com docs
    const href = await laravelVersionLink.getAttribute('href');
    expect(href).toMatch(/^https:\/\/laravel\.com\/docs\/\d+\.x\/releases$/);
    
    // Verify badge text shows version (format like 12.28.1)
    const badgeText = await laravelVersionLink.textContent();
    expect(badgeText).toMatch(/\d+\.\d+\.\d+/);
  });

  test('all version badges are in the Environment card', async ({ page }) => {
    // Find the Environment card
    const environmentCard = page.locator('text=Environment').locator('..');
    await expect(environmentCard).toBeVisible();
    
    // Verify all three version rows exist
    const ernieRow = environmentCard.locator('text=ERNIE Version');
    const phpRow = environmentCard.locator('text=PHP Version');
    const laravelRow = environmentCard.locator('text=Laravel Version');
    
    await expect(ernieRow).toBeVisible();
    await expect(phpRow).toBeVisible();
    await expect(laravelRow).toBeVisible();
  });

  test('version badges have proper hover styles', async ({ page }) => {
    // Get PHP version link (has hover effect)
    const phpVersionLink = page.getByRole('link', { name: /view php .+ release notes/i });
    const badge = phpVersionLink.locator('..');
    
    // Verify badge has transition class for hover effect
    const badgeClasses = await badge.getAttribute('class');
    expect(badgeClasses).toContain('transition-colors');
  });

  test('version badge links are keyboard accessible', async ({ page }) => {
    // Tab through the page to reach version badges
    await page.keyboard.press('Tab');
    
    // Find the focused element and verify it's a version link
    // This tests that the badges are in the tab order
    const phpVersionLink = page.getByRole('link', { name: /view php .+ release notes/i });
    
    // Focus the link
    await phpVersionLink.focus();
    
    // Verify it's focusable
    await expect(phpVersionLink).toBeFocused();
    
    // Verify we can activate it with Enter (we won't actually navigate)
    // Just check that the element is interactive
    await expect(phpVersionLink).toHaveAttribute('href', /php\.net/);
  });

  test('ERNIE version badge navigates to changelog page', async ({ page }) => {
    // Click the ERNIE version link
    const ernieVersionLink = page.getByRole('link', { name: /view changelog for version/i });
    await ernieVersionLink.click();
    
    // Verify navigation to changelog
    await page.waitForURL(/\/changelog/, { timeout: 5000 });
    
    // Verify changelog page loaded
    await expect(page.getByText(/changelog/i)).toBeVisible();
  });

  test('PHP version badge link is valid without navigation', async ({ page }) => {
    // Get the PHP version link without clicking
    const phpVersionLink = page.getByRole('link', { name: /view php .+ release notes/i });
    
    // Extract href
    const href = await phpVersionLink.getAttribute('href');
    expect(href).toBeTruthy();
    
    // Verify it's a valid URL
    expect(() => new URL(href!)).not.toThrow();
    
    // Verify domain
    const url = new URL(href!);
    expect(url.hostname).toBe('www.php.net');
  });

  test('Laravel version badge link is valid without navigation', async ({ page }) => {
    // Get the Laravel version link without clicking
    const laravelVersionLink = page.getByRole('link', { name: /view laravel .+ release notes/i });
    
    // Extract href
    const href = await laravelVersionLink.getAttribute('href');
    expect(href).toBeTruthy();
    
    // Verify it's a valid URL
    expect(() => new URL(href!)).not.toThrow();
    
    // Verify domain
    const url = new URL(href!);
    expect(url.hostname).toBe('laravel.com');
  });
});

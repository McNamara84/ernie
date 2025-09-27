import { test, expect } from '@playwright/test';

test('basic server health check', async ({ page }) => {
  console.log('Starting basic health check...');
  
  // Go to the root page
  await page.goto('/');
  console.log('Navigated to root page');
  
  // Just check that the page loads and has some content
  await expect(page.locator('body')).toBeVisible();
  console.log('Body is visible');
  
  // Check that we get some response (even if it's a redirect)
  const title = await page.title();
  console.log('Page title:', title);
  
  // This should pass if the server is responding at all
  expect(title).toBeTruthy();
  console.log('Health check completed successfully');
});
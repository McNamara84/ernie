import { test, expect } from '@playwright/test';

test('debug login page content', async ({ page }) => {
  console.log('=== DEBUGGING LOGIN PAGE ===');
  
  await page.goto('/login', { waitUntil: 'networkidle' });
  
  const title = await page.title();
  console.log('Page title:', title);
  
  const url = page.url();
  console.log('Current URL:', url);
  
  const htmlContent = await page.content();
  console.log('Page content length:', htmlContent.length);
  console.log('First 1000 chars:', htmlContent.substring(0, 1000));
  
  // Check if there are any JavaScript errors
  const errors: string[] = [];
  page.on('console', msg => {
    if (msg.type() === 'error') {
      errors.push(msg.text());
    }
  });
  
  // Wait a bit to see if any JS loads
  await page.waitForTimeout(3000);
  
  if (errors.length > 0) {
    console.log('JavaScript errors found:');
    errors.forEach(error => console.log('- ', error));
  }
  
  // Try to find any input elements
  const inputs = await page.locator('input').count();
  console.log('Number of input elements:', inputs);
  
  // Try to find any labels
  const labels = await page.locator('label').count();
  console.log('Number of label elements:', labels);
  
  // Check for specific elements
  const emailLabel = page.getByLabel('Email address');
  const isEmailVisible = await emailLabel.isVisible().catch(() => false);
  console.log('Email address label visible:', isEmailVisible);
  
  console.log('=== END DEBUG ===');
  
  // This test doesn't need to pass, it's just for debugging
  expect(true).toBe(true);
});
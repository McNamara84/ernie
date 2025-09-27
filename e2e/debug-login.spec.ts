import { test, expect } from '@playwright/test';

test('debug login page content', async ({ page }) => {
  console.log('=== DEBUGGING LOGIN PAGE ===');
  
  // Capture JavaScript errors
  const jsErrors: string[] = [];
  const networkErrors: string[] = [];
  
  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      jsErrors.push(`Console Error: ${msg.text()}`);
    }
    console.log(`Console ${msg.type()}: ${msg.text()}`);
  });
  
  page.on('pageerror', (error) => {
    jsErrors.push(`Page Error: ${error.message}`);
    console.log(`Page Error: ${error.message}`);
  });
  
  page.on('response', (response) => {
    if (!response.ok()) {
      networkErrors.push(`Network Error: ${response.status()} ${response.url()}`);
      console.log(`Network Error: ${response.status()} ${response.url()}`);
    }
  });

  await page.goto('/login', { waitUntil: 'networkidle' });
  
  // Additional wait for any dynamic content
  await page.waitForTimeout(3000);
  
  const title = await page.title();
  console.log('Page title:', title);
  
  const url = page.url();
  console.log('Current URL:', url);
  
  const htmlContent = await page.content();
  console.log('Page content length:', htmlContent.length);
  console.log('First 1000 chars:', htmlContent.substring(0, 1000));
  
  // Check for JavaScript errors
  console.log('JavaScript errors found:', jsErrors.length);
  jsErrors.forEach((error, index) => {
    console.log(`JS Error ${index + 1}: ${error}`);
  });
  
  // Check for network errors  
  console.log('Network errors found:', networkErrors.length);
  networkErrors.forEach((error, index) => {
    console.log(`Network Error ${index + 1}: ${error}`);
  });

  // Count DOM elements
  const inputs = await page.locator('input').count();
  console.log('Number of input elements:', inputs);

  const labels = await page.locator('label').count();
  console.log('Number of label elements:', labels);
  
  const forms = await page.locator('form').count();
  console.log('Number of form elements:', forms);
  
  const appContainer = await page.locator('#app').count();
  console.log(`App container (#app) found: ${appContainer > 0}`);
  
  const bodyContent = await page.locator('body').innerHTML();
  console.log(`Body innerHTML length: ${bodyContent.length}`);
  
  // Check for Inertia page data
  const inertiaPageData = await page.locator('#app').getAttribute('data-page');
  console.log(`Inertia page data present: ${inertiaPageData ? 'Yes' : 'No'}`);
  if (inertiaPageData) {
    console.log('Inertia page data:', inertiaPageData.substring(0, 200) + '...');
  }
  
  // Check if React/Inertia has loaded
  const reactLoaded = await page.evaluate(() => {
    return typeof window.React !== 'undefined' || 
           (window as unknown as { InertiaApp?: unknown }).InertiaApp !== undefined ||
           document.querySelector('[data-reactroot]') !== null;
  });
  console.log(`React/Inertia loaded: ${reactLoaded}`);
  
  // Check for asset loading
  const scriptTags = await page.locator('script[src]').count();
  const linkTags = await page.locator('link[href*=".css"]').count();
  console.log(`Script tags: ${scriptTags}`);
  console.log(`CSS link tags: ${linkTags}`);
  
  // Check for specific elements
  const emailLabel = page.getByLabel('Email address');
  const isEmailVisible = await emailLabel.isVisible().catch(() => false);
  console.log('Email address label visible:', isEmailVisible);
  
  // Alternative ways to find email input
  const emailInputByType = await page.locator('input[type="email"]').count();
  const emailInputByName = await page.locator('input[name*="email"], input[id*="email"]').count();
  console.log(`Email inputs by type: ${emailInputByType}`);
  console.log(`Email inputs by name/id: ${emailInputByName}`);
  
  console.log('=== END DEBUG ===');
  
  // This test doesn't need to pass, it's just for debugging
  expect(true).toBe(true);
});
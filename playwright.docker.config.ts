import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for Docker development environment.
 * 
 * Usage: npx playwright test --config=playwright.docker.config.ts
 * 
 * This configuration:
 * - Uses https://localhost:3333/ernie/ as base URL
 * - Ignores self-signed certificate errors
 * - No webServer (Docker containers are already running)
 */
export default defineConfig({
  testDir: './tests/playwright',
  /* Run tests in files in parallel */
  fullyParallel: true,
  /* Fail the build on CI if you accidentally left test.only in the source code. */
  forbidOnly: !!process.env.CI,
  /* Retry on CI only */
  retries: process.env.CI ? 2 : 0,
  /* Workers - use fewer workers since Docker may have limited resources */
  workers: 2,
  /* Reporter to use */
  reporter: 'html',
  /* Global timeout for each test */
  timeout: 90 * 1000,
  /* Global timeout for expect() */
  expect: {
    timeout: 15 * 1000,
  },
  
  /* Test match patterns - same as main config */
  testMatch: [
    'tests/playwright/critical/**/*.spec.ts',
    'tests/playwright/workflows/!(user-management).spec.ts',
    'tests/playwright/accessibility/**/*.spec.ts',
  ],
  
  /* Ignore helper files and documentation */
  testIgnore: [
    '**/helpers/**',
    '**/page-objects/**',
    '**/*.md',
    '**/constants.ts',
  ],
  
  /* Shared settings for all the projects below */
  use: {
    /* Docker development URL with /ernie/ prefix */
    baseURL: 'https://localhost:3333/ernie',

    /* Accept self-signed certificates */
    ignoreHTTPSErrors: true,

    /* Collect trace when retrying the failed test */
    trace: 'on-first-retry',
    
    /* Take screenshot on failure */
    screenshot: 'only-on-failure',
    
    /* Record video on failure */
    video: 'retain-on-failure',
    
    /* Timeout for each action */
    actionTimeout: 15 * 1000,
    
    /* Timeout for page navigation */
    navigationTimeout: 30 * 1000,
  },

  /* Configure projects for major browsers */
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },

    {
      name: 'firefox',
      use: { ...devices['Desktop Firefox'] },
    },

    {
      name: 'webkit',
      use: { ...devices['Desktop Safari'] },
    },
  ],

  /* No webServer - Docker containers handle the server */
});

import { defineConfig, devices } from '@playwright/test';

import { testIgnorePatterns, testMatchPatterns, timeoutSettings } from './tests/playwright/playwright.shared';

/**
 * Read environment variables from file.
 * https://github.com/motdotla/dotenv
 */
// import dotenv from 'dotenv';
// import path from 'path';
// dotenv.config({ path: path.resolve(__dirname, '.env') });

/**
 * See https://playwright.dev/docs/test-configuration.
 */
export default defineConfig({
  testDir: './tests/playwright',
  /* Run tests in files in parallel */
  fullyParallel: true,
  /* Fail the build on CI if you accidentally left test.only in the source code. */
  forbidOnly: !!process.env.CI,
  /* Retry on CI only */
  retries: process.env.CI ? 2 : 0,
  /* Workers per shard - can be parallel now since sharding isolates tests */
  workers: process.env.CI ? 2 : undefined,
  /* Reporter to use. See https://playwright.dev/docs/test-reporters */
  reporter: process.env.CI ? 'github' : 'html',
  /* Global timeout for each test */
  timeout: timeoutSettings.testTimeout,
  /* Global timeout for expect() */
  expect: {
    timeout: timeoutSettings.expectTimeout,
  },
  
  /* Test match patterns - imported from shared config */
  testMatch: testMatchPatterns,
  
  /* Ignore helper files and documentation */
  testIgnore: testIgnorePatterns,
  /* Shared settings for all the projects below. See https://playwright.dev/docs/api/class-testoptions. */
  use: {
    /* Base URL to use in actions like `await page.goto('/')`. */
    baseURL: 'http://127.0.0.1:8000',

    /* Collect trace when retrying the failed test. See https://playwright.dev/docs/trace-viewer */
    trace: 'on-first-retry',
    
    /* Take screenshot on failure */
    screenshot: 'only-on-failure',
    
    /* Record video on failure */
    video: 'retain-on-failure',
    
    /* Timeout for each action (click, fill, etc.) */
    actionTimeout: 15 * 1000, // 15s for actions in CI
    
    /* Timeout for page navigation */
    navigationTimeout: 30 * 1000, // 30s for page loads in CI
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

    /* Test against mobile viewports. */
    // {
    //   name: 'Mobile Chrome',
    //   use: { ...devices['Pixel 5'] },
    // },
    // {
    //   name: 'Mobile Safari',
    //   use: { ...devices['iPhone 12'] },
    // },

    /* Test against branded browsers. */
    // {
    //   name: 'Microsoft Edge',
    //   use: { ...devices['Desktop Edge'], channel: 'msedge' },
    // },
    // {
    //   name: 'Google Chrome',
    //   use: { ...devices['Desktop Chrome'], channel: 'chrome' },
    // },
  ],

  /* Only use webServer for local development */
  ...(process.env.CI ? {} : {
    webServer: {
      command: 'php artisan serve --host=127.0.0.1 --port=8000 --env=testing',
      url: 'http://127.0.0.1:8000',
      reuseExistingServer: true,
      timeout: 120 * 1000,
    },
  }),
});

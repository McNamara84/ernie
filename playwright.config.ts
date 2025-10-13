import { defineConfig, devices } from '@playwright/test';

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
  /* Opt out of parallel tests on CI. */
  workers: process.env.CI ? 1 : undefined,
  /* Reporter to use. See https://playwright.dev/docs/test-reporters */
  reporter: process.env.CI ? 'github' : 'html',
  /* Global timeout for each test */
  timeout: 60 * 1000, // Increased to 60s for workflow tests
  /* Global timeout for expect() */
  expect: {
    timeout: 10 * 1000, // Increased to 10s for more complex assertions
  },
  
  /* Test match patterns - organized by priority */
  testMatch: [
    // Critical smoke tests run first
    'tests/playwright/critical/**/*.spec.ts',
    // Then workflow tests
    'tests/playwright/workflows/**/*.spec.ts',
  ],
  
  /* Ignore helper files and documentation */
  testIgnore: [
    '**/helpers/**',
    '**/page-objects/**',
    '**/*.md',
    '**/constants.ts',
  ],
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
      command: 'php artisan serve --host=127.0.0.1 --port=8000',
      url: 'http://127.0.0.1:8000',
      reuseExistingServer: true,
      timeout: 120 * 1000,
      env: {
        ...process.env,
        APP_ENV: 'testing',
        DB_CONNECTION: 'sqlite',
        SESSION_DRIVER: 'file',
        SESSION_PATH: '/',
        CACHE_DRIVER: 'file',
        QUEUE_CONNECTION: 'sync',
        BROADCAST_DRIVER: 'log',
      },
    },
  }),
});

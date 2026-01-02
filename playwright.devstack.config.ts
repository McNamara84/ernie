import { defineConfig, devices } from '@playwright/test';

import { testIgnorePatterns, testMatchPatterns, timeoutSettings } from './tests/playwright/playwright.shared';

/**
 * Playwright configuration for local development against the running Docker dev stack.
 *
 * Important:
 * - This is intended for manual/local runs.
 * - CI should keep using its existing config(s).
 *
 * Usage:
 * - npm run test:e2e:devstack
 * - PLAYWRIGHT_BASE_URL=https://ernie.localhost:3333 npx playwright test --config=playwright.devstack.config.ts
 */
export default defineConfig({
  testDir: './tests/playwright',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: 0,
  workers: 2,
  reporter: 'html',
  timeout: timeoutSettings.testTimeout,
  expect: {
    timeout: timeoutSettings.expectTimeout,
  },

  testMatch: testMatchPatterns,
  testIgnore: testIgnorePatterns,

  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'https://ernie.localhost:3333',
    ignoreHTTPSErrors: true,

    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',

    actionTimeout: 15 * 1000,
    navigationTimeout: 30 * 1000,
  },

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

  // No webServer: docker-compose.dev.yml provides the app+vite+traefik.
});

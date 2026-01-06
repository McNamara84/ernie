import fs from 'fs';
import path from 'path';

import { defineConfig, devices } from '@playwright/test';

// Load environment variables from .env file manually
const envPath = path.resolve(process.cwd(), '.env');
if (fs.existsSync(envPath)) {
  const envContent = fs.readFileSync(envPath, 'utf-8');
  for (const line of envContent.split('\n')) {
    const trimmed = line.trim();
    if (trimmed && !trimmed.startsWith('#')) {
      const [key, ...valueParts] = trimmed.split('=');
      const value = valueParts.join('=').replace(/^["']|["']$/g, '');
      if (key && !process.env[key]) {
        process.env[key] = value;
      }
    }
  }
}

/**
 * Playwright configuration for Stage environment testing.
 * 
 * This configuration is specifically designed for manual integration testing
 * against the production-like stage environment at https://ernie.rz-vm182.gfz.de/
 * 
 * IMPORTANT: This configuration is NOT included in CI/CD pipelines.
 * It requires manual credentials via environment variables:
 * - STAGE_TEST_USERNAME: Stage test account email
 * - STAGE_TEST_PASSWORD: Stage test account password
 * 
 * Usage: 
 *   npm run test:e2e:stage
 *   npm run test:e2e:stage:headed
 *   npm run test:e2e:stage:ui
 */
export default defineConfig({
  testDir: './tests/playwright/stage',
  
  /* Run tests sequentially - stage tests should not run in parallel */
  fullyParallel: false,
  
  /* Fail the build on CI if you accidentally left test.only in the source code. */
  forbidOnly: !!process.env.CI,
  
  /* No retries for stage tests - we want to see failures immediately */
  retries: 0,
  
  /* Single worker for stage tests */
  workers: 1,
  
  /* Reporter to use */
  reporter: [
    ['html', { outputFolder: 'playwright-report-stage' }],
    ['list'],
  ],
  
  /* Global timeout for each test - longer for stage (network latency) */
  timeout: 120 * 1000,
  
  /* Global timeout for expect() */
  expect: {
    timeout: 20 * 1000,
  },
  
  /* Match all spec files in the stage directory */
  testMatch: '**/*.spec.ts',
  
  /* Shared settings for all the projects below */
  use: {
    /* Stage environment URL */
    baseURL: 'https://ernie.rz-vm182.gfz.de',

    /* Accept self-signed certificates (if any) */
    ignoreHTTPSErrors: true,

    /* Collect trace on failure */
    trace: 'retain-on-failure',
    
    /* Take screenshot on failure */
    screenshot: 'only-on-failure',
    
    /* Record video on failure */
    video: 'retain-on-failure',
    
    /* Timeout for each action - longer for stage */
    actionTimeout: 20 * 1000,
    
    /* Timeout for page navigation */
    navigationTimeout: 30 * 1000,
  },

  /* Configure projects for major browsers */
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
    // Uncomment to test in other browsers
    // {
    //   name: 'firefox',
    //   use: { ...devices['Desktop Firefox'] },
    // },
    // {
    //   name: 'webkit',
    //   use: { ...devices['Desktop Safari'] },
    // },
  ],
});

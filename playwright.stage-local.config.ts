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
 * TEMPORARY: Stage test config pointing to LOCAL Docker environment.
 * Use this to test the full workflow locally before deploying to Stage.
 */
export default defineConfig({
  testDir: './tests/playwright/stage',
  
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: 0,
  workers: 1,
  
  reporter: [
    ['html', { outputFolder: 'playwright-report-stage-local' }],
    ['list'],
  ],
  
  timeout: 120 * 1000,
  
  expect: {
    timeout: 20 * 1000,
  },
  
  testMatch: '**/*.spec.ts',
  
  use: {
    /* LOCAL Docker environment instead of Stage */
    baseURL: 'https://ernie.localhost:3333',

    ignoreHTTPSErrors: true,
    trace: 'retain-on-failure',
    video: 'retain-on-failure',
    screenshot: 'only-on-failure',
    
    actionTimeout: 30 * 1000,
    navigationTimeout: 60 * 1000,
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});

// Override stage credentials for local testing
process.env.STAGE_TEST_USERNAME = 'test@example.com';
process.env.STAGE_TEST_PASSWORD = 'password';

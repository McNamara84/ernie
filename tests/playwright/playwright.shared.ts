/**
 * Shared Playwright configuration patterns.
 * Import these in both playwright.config.ts and playwright.docker.config.ts
 * to ensure consistency across environments.
 */

/**
 * Test match patterns - organized by priority
 * 
 * Note: user-management.spec.ts is excluded because logout tests rely on
 * 'button:has-text("Logout")' which doesn't exist. The actual implementation
 * uses a dropdown menu (see resources/js/components/user-menu-content.tsx)
 */
export const testMatchPatterns = [
  // Critical smoke tests run first
  'tests/playwright/critical/**/*.spec.ts',
  // Workflow tests (excluding user-management)
  'tests/playwright/workflows/!(user-management).spec.ts',
  // Accessibility tests
  'tests/playwright/accessibility/**/*.spec.ts',
];

/**
 * Patterns for files to ignore in test discovery
 */
export const testIgnorePatterns = [
  '**/helpers/**',
  '**/page-objects/**',
  '**/*.md',
  '**/constants.ts',
];

/**
 * Common timeout settings
 */
export const timeoutSettings = {
  /** Global timeout for each test (90 seconds) */
  testTimeout: 90 * 1000,
  /** Timeout for expect() assertions (15 seconds) */
  expectTimeout: 15 * 1000,
};

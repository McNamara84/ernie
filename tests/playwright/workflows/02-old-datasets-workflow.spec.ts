import { expect, test } from '@playwright/test';

// Old Datasets Basic Tests
// Verifies old datasets page is accessible.
// Note: Tests requiring the legacy VPN database (db_old) have been removed.
// Only the authentication check remains, which works without the legacy database.

test.describe('Old Datasets', () => {
  test('old datasets page requires authentication', async ({ page }) => {
    // Try to access without login
    await page.goto('/old-datasets');
    
    // Should redirect to login
    await expect(page).toHaveURL(/\/login/);
  });
});

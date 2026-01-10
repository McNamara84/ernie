import { expect, test } from '@playwright/test';

const TEST_USER_EMAIL = 'test@example.com';
const TEST_USER_PASSWORD = 'password';

// Run tests serially to avoid parallel password changes
test.describe.configure({ mode: 'serial' });

test.describe('Password Change Bug Reproduction (Issue #317)', () => {
  // Reset test user password before tests
  test.beforeAll(async () => {
    // Reset the test user's password via artisan command
    const { exec } = await import('child_process');
    await new Promise<void>((resolve) => {
      exec(
        'docker exec ernie-app-dev php artisan tinker --execute="\\App\\Models\\User::where(\'email\', \'test@example.com\')->update([\'password\' => bcrypt(\'password\')])"',
        (error, _stdout, stderr) => {
          if (error) {
            console.log('Warning: Could not reset user password:', stderr);
          }
          resolve();
        }
      );
    });
  });

  test('should change password without 419 error - basic scenario', async ({ page }) => {
    // Enable request/response logging for password endpoints
    page.on('response', response => {
      if (response.url().includes('password') || response.status() === 419) {
        console.log(`Response: ${response.status()} ${response.url()}`);
      }
    });

    // Login
    await page.goto('/login');
    await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
    await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL(/\/dashboard/, { timeout: 15000 });

    // Go to password settings
    await page.goto('/settings/password');
    await expect(page.getByRole('heading', { name: 'Update password' })).toBeVisible();

    // Fill in password change form - use the SAME password to keep things simple
    await page.getByLabel('Current password').fill(TEST_USER_PASSWORD);
    await page.getByLabel('New password').fill(TEST_USER_PASSWORD);
    await page.getByLabel('Confirm password').fill(TEST_USER_PASSWORD);

    // Intercept the request to see what happens
    const [response] = await Promise.all([
      page.waitForResponse(resp => resp.url().includes('password') && resp.request().method() !== 'GET'),
      page.getByRole('button', { name: 'Save password' }).click()
    ]);

    // Check response status
    console.log(`Password change response status: ${response.status()}`);
    
    // We expect success (200/302/303) not 419
    expect(response.status()).not.toBe(419);
    
    // If successful, we should see a success message
    await expect(page.getByText('Saved')).toBeVisible({ timeout: 5000 });
  });

  test('should change password after page refresh - session persistence test', async ({ page }) => {
    // This scenario tests if CSRF token is preserved after page refresh
    page.on('response', response => {
      if (response.url().includes('password') || response.status() === 419) {
        console.log(`Response: ${response.status()} ${response.url()}`);
      }
    });

    // Login
    await page.goto('/login');
    await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
    await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL(/\/dashboard/, { timeout: 15000 });

    // Go to password settings
    await page.goto('/settings/password');
    await expect(page.getByRole('heading', { name: 'Update password' })).toBeVisible();

    // Refresh the page
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Update password' })).toBeVisible();

    // Fill in password change form after refresh - use same password
    await page.getByLabel('Current password').fill(TEST_USER_PASSWORD);
    await page.getByLabel('New password').fill(TEST_USER_PASSWORD);
    await page.getByLabel('Confirm password').fill(TEST_USER_PASSWORD);

    // Intercept the request
    const [response] = await Promise.all([
      page.waitForResponse(resp => resp.url().includes('password') && resp.request().method() !== 'GET'),
      page.getByRole('button', { name: 'Save password' }).click()
    ]);

    console.log(`Password change after refresh response status: ${response.status()}`);
    expect(response.status()).not.toBe(419);
    await expect(page.getByText('Saved')).toBeVisible({ timeout: 5000 });
  });

  test('should change password after idle time - token expiration test', async ({ page }) => {
    // This scenario tests if CSRF token is still valid after some idle time
    page.on('response', response => {
      if (response.url().includes('password') || response.status() === 419) {
        console.log(`Response: ${response.status()} ${response.url()}`);
      }
    });

    // Login
    await page.goto('/login');
    await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
    await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL(/\/dashboard/, { timeout: 15000 });

    // Go to password settings
    await page.goto('/settings/password');
    await expect(page.getByRole('heading', { name: 'Update password' })).toBeVisible();

    // Wait for a while to simulate idle time
    await page.waitForTimeout(3000);

    // Fill in password change form - use same password
    await page.getByLabel('Current password').fill(TEST_USER_PASSWORD);
    await page.getByLabel('New password').fill(TEST_USER_PASSWORD);
    await page.getByLabel('Confirm password').fill(TEST_USER_PASSWORD);

    // Intercept the request
    const [response] = await Promise.all([
      page.waitForResponse(resp => resp.url().includes('password') && resp.request().method() !== 'GET'),
      page.getByRole('button', { name: 'Save password' }).click()
    ]);

    console.log(`Password change after idle response status: ${response.status()}`);
    expect(response.status()).not.toBe(419);
    await expect(page.getByText('Saved')).toBeVisible({ timeout: 5000 });
  });
});

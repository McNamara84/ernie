import { expect, test } from '@playwright/test';

import { STAGE_TEST_PASSWORD, STAGE_TEST_USERNAME } from '../constants';

/**
 * Stage IGSN Page Bug Investigation
 * 
 * This test investigates the 500 Internal Server Error when accessing /igsns
 * on the stage environment.
 * 
 * Bug Report:
 * - Error: "All Inertia requests must receive a valid Inertia response, however a plain JSON response was received."
 * - Console: GET https://ernie.rz-vm182.gfz.de/igsns 500 (Internal Server Error)
 * 
 * Usage:
 *   npx playwright test --config=playwright.stage.config.ts tests/playwright/stage/igsn-page-bug.spec.ts
 */

test.describe('IGSN Page Bug Investigation', () => {
    test.beforeEach(async ({ page }) => {
        // Skip if no stage credentials provided
        test.skip(!STAGE_TEST_USERNAME || !STAGE_TEST_PASSWORD, 
            'Stage credentials not provided - set STAGE_TEST_USERNAME and STAGE_TEST_PASSWORD');
        
        // Navigate to login page
        await page.goto('/login');
        
        // Fill in credentials
        await page.getByLabel('Email').fill(STAGE_TEST_USERNAME!);
        await page.getByLabel('Password').fill(STAGE_TEST_PASSWORD!);
        
        // Click login button
        await page.getByRole('button', { name: 'Log in' }).click();
        
        // Wait for redirect to dashboard
        await page.waitForURL('**/dashboard', { timeout: 30000 });
        
        // Verify logged in - look for greeting or user button in sidebar
        await expect(page.getByRole('heading', { name: /Hello/i })).toBeVisible({ timeout: 10000 });
    });

    test('can access /igsns page directly via URL', async ({ page }) => {
        // Try to navigate directly to /igsns
        const response = await page.goto('/igsns', { waitUntil: 'networkidle' });
        
        // Log the response status
        console.log('Direct navigation response status:', response?.status());
        
        // Check if we got a 500 error
        if (response?.status() === 500) {
            // Capture the error response body for investigation
            const body = await response.text();
            console.log('Error response body (first 2000 chars):', body.substring(0, 2000));
            
            // Take a screenshot of the error state
            await page.screenshot({ path: 'playwright-report-stage/igsn-500-error.png', fullPage: true });
            
            // Fail the test with details
            expect(response.status()).not.toBe(500);
        }
        
        // If we get here, the page loaded - verify content
        await expect(page.getByText('Physical Samples (IGSNs)')).toBeVisible({ timeout: 15000 });
    });

    test('can access /igsns page via sidebar navigation', async ({ page }) => {
        // Look for IGSNs link in sidebar
        const igsnLink = page.getByRole('link', { name: /IGSNs/i });
        
        // Check if the link exists
        const linkExists = await igsnLink.isVisible().catch(() => false);
        
        if (!linkExists) {
            console.log('IGSNs link not found in sidebar - may not be visible for this user role');
            test.skip();
            return;
        }
        
        // Set up response listener before clicking
        const responsePromise = page.waitForResponse(
            resp => resp.url().includes('/igsns') && resp.request().method() === 'GET',
            { timeout: 30000 }
        );
        
        // Click the IGSNs link
        await igsnLink.click();
        
        // Wait for the response
        const response = await responsePromise;
        
        // Log details
        console.log('Navigation response status:', response.status());
        console.log('Navigation response headers:', JSON.stringify(response.headers(), null, 2));
        
        if (response.status() === 500) {
            // Capture error details
            const body = await response.text();
            console.log('Error response body (first 2000 chars):', body.substring(0, 2000));
            
            // Check if it's a JSON response (which would cause the Inertia error)
            const contentType = response.headers()['content-type'];
            console.log('Content-Type:', contentType);
            
            // Take screenshot
            await page.screenshot({ path: 'playwright-report-stage/igsn-nav-500-error.png', fullPage: true });
            
            // Fail with details
            expect(response.status()).not.toBe(500);
        }
        
        // Verify page content if successful
        await expect(page.getByText('Physical Samples (IGSNs)')).toBeVisible({ timeout: 15000 });
    });

    test('check server logs via direct API call', async ({ request, page }) => {
        // Skip if no credentials
        test.skip(!STAGE_TEST_USERNAME || !STAGE_TEST_PASSWORD, 
            'Stage credentials not provided');
        
        // First login via the page to get session
        await page.goto('/login');
        await page.getByLabel('Email').fill(STAGE_TEST_USERNAME!);
        await page.getByLabel('Password').fill(STAGE_TEST_PASSWORD!);
        await page.getByRole('button', { name: 'Log in' }).click();
        await page.waitForURL('**/dashboard', { timeout: 30000 });
        
        // Get cookies from the page
        const cookies = await page.context().cookies();
        const cookieHeader = cookies.map(c => `${c.name}=${c.value}`).join('; ');
        
        // Make a direct API call to /igsns without Inertia headers
        // This should return the raw error response
        const response = await request.get('/igsns', {
            headers: {
                'Cookie': cookieHeader,
                'Accept': 'application/json',
            },
        });
        
        console.log('Direct API call status:', response.status());
        
        if (response.status() !== 200) {
            const body = await response.text();
            console.log('Error response:', body);
            
            // Try to parse as JSON for better formatting
            try {
                const json = JSON.parse(body);
                console.log('Parsed error JSON:', JSON.stringify(json, null, 2));
            } catch {
                console.log('Response is not JSON');
            }
        }
        
        // We expect this to fail with 500 - that's what we're investigating
        // Log the status for the report
        console.log(`Test completed - API returned status ${response.status()}`);
    });

    test('verify IGSN controller and route are properly configured', async ({ page }) => {
        // This test checks if the basic route exists by examining the response
        
        // Try accessing without login first (should redirect to login)
        const unauthResponse = await page.goto('/igsns');
        
        // Should redirect to login, not return 500
        const isRedirectToLogin = page.url().includes('/login');
        const isServerError = unauthResponse?.status() === 500;
        
        console.log('Unauthenticated request:');
        console.log('  - Redirected to login:', isRedirectToLogin);
        console.log('  - Server error (500):', isServerError);
        console.log('  - Final URL:', page.url());
        console.log('  - Response status:', unauthResponse?.status());
        
        if (isServerError) {
            const body = await unauthResponse?.text();
            console.log('  - Error body:', body?.substring(0, 1000));
            
            // Take screenshot
            await page.screenshot({ path: 'playwright-report-stage/igsn-unauth-500.png', fullPage: true });
        }
        
        // For an unauthenticated request, we expect either:
        // - Redirect to /login (normal behavior)
        // - 500 error (the bug we're investigating)
        expect(isRedirectToLogin || !isServerError).toBeTruthy();
    });
});

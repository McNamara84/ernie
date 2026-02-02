import { expect, test } from '@playwright/test';

import { STAGE_TEST_PASSWORD, STAGE_TEST_USERNAME } from '../constants';

/**
 * Thesauri Settings Bug Reproduction Test
 * 
 * This test reproduces a bug where the three thesauri (Science Keywords, Platforms, Instruments)
 * are not displayed in the Settings page after an update was performed.
 * 
 * The bug: After updating thesauri settings, the thesauri are no longer visible on /settings,
 * so users cannot check for updates anymore. However, the thesauri are still available
 * in the Data Editor under /editor.
 * 
 * Usage:
 *   npx playwright test --config=playwright.stage.config.ts tests/playwright/stage/thesauri-settings-bug.spec.ts
 */

test.describe('Thesauri Settings Bug', () => {
    test.beforeAll(() => {
        if (!STAGE_TEST_USERNAME || !STAGE_TEST_PASSWORD) {
            throw new Error(
                'STAGE_TEST_USERNAME and STAGE_TEST_PASSWORD environment variables must be set. ' +
                'Check your .env file for these credentials.'
            );
        }
    });

    test('should display all three thesauri in the settings page', async ({ page }) => {
        // Step 1: Login to the stage environment
        console.log('Step 1: Navigating to login page...');
        await page.goto('/login');
        await expect(page).toHaveURL(/\/login/);

        console.log('Step 2: Logging in with test credentials...');
        await page.fill('input[name="email"]', STAGE_TEST_USERNAME!);
        await page.fill('input[name="password"]', STAGE_TEST_PASSWORD!);
        await page.click('button[type="submit"]');

        // Wait for successful login (redirect to dashboard)
        await expect(page).toHaveURL(/\/dashboard/, { timeout: 30000 });
        console.log('Successfully logged in!');

        // Step 3: Navigate to Settings page
        console.log('Step 3: Navigating to Settings page...');
        await page.goto('/settings');
        await expect(page).toHaveURL(/\/settings/);

        // Step 4: Look for the Thesauri card
        console.log('Step 4: Looking for Thesauri card...');
        const thesauriCardTitle = page.locator('h3, [class*="CardTitle"]').filter({ hasText: 'Thesauri' });
        await expect(thesauriCardTitle).toBeVisible({ timeout: 10000 });
        console.log('Thesauri card title found!');

        // Step 5: Check for the three thesaurus rows
        console.log('Step 5: Checking for thesaurus rows...');

        // Look for Science Keywords
        const scienceKeywordsRow = page.locator('[data-testid="thesaurus-row-science_keywords"]');
        const scienceKeywordsText = page.locator('text=GCMD Science Keywords');
        
        // Look for Platforms
        const platformsRow = page.locator('[data-testid="thesaurus-row-platforms"]');
        const platformsText = page.locator('text=GCMD Platforms');
        
        // Look for Instruments
        const instrumentsRow = page.locator('[data-testid="thesaurus-row-instruments"]');
        const instrumentsText = page.locator('text=GCMD Instruments');

        // Verify all three thesauri are visible
        console.log('Checking GCMD Science Keywords...');
        const scienceKeywordsVisible = await scienceKeywordsRow.or(scienceKeywordsText).isVisible().catch(() => false);
        console.log(`  GCMD Science Keywords visible: ${scienceKeywordsVisible}`);

        console.log('Checking GCMD Platforms...');
        const platformsVisible = await platformsRow.or(platformsText).isVisible().catch(() => false);
        console.log(`  GCMD Platforms visible: ${platformsVisible}`);

        console.log('Checking GCMD Instruments...');
        const instrumentsVisible = await instrumentsRow.or(instrumentsText).isVisible().catch(() => false);
        console.log(`  GCMD Instruments visible: ${instrumentsVisible}`);

        // Take a screenshot for debugging
        await page.screenshot({ path: 'test-results/thesauri-settings-bug.png', fullPage: true });

        // Step 6: Also look for any "Check for Updates" buttons to confirm thesauri functionality
        console.log('Step 6: Looking for Check for Updates buttons...');
        const checkForUpdatesButtons = page.locator('button').filter({ hasText: 'Check for Updates' });
        const buttonCount = await checkForUpdatesButtons.count();
        console.log(`Found ${buttonCount} "Check for Updates" buttons`);

        // Assertions - The bug is reproduced if any of these fail
        expect(scienceKeywordsVisible, 'GCMD Science Keywords should be visible').toBe(true);
        expect(platformsVisible, 'GCMD Platforms should be visible').toBe(true);
        expect(instrumentsVisible, 'GCMD Instruments should be visible').toBe(true);
        expect(buttonCount, 'Should have 3 Check for Updates buttons (one per thesaurus)').toBe(3);

        console.log('All thesauri are correctly displayed!');
    });

    test('thesauri should be available in the editor', async ({ page }) => {
        // This test verifies that thesauri still work in the editor (as reported)
        console.log('Step 1: Logging in...');
        await page.goto('/login');
        await page.fill('input[name="email"]', STAGE_TEST_USERNAME!);
        await page.fill('input[name="password"]', STAGE_TEST_PASSWORD!);
        await page.click('button[type="submit"]');
        await expect(page).toHaveURL(/\/dashboard/, { timeout: 30000 });

        console.log('Step 2: Navigating to Editor page...');
        await page.goto('/editor');
        await expect(page).toHaveURL(/\/editor/);

        // Step 3: Look for Science Keywords accordion
        console.log('Step 3: Looking for GCMD Science Keywords accordion...');
        const scienceKeywordsAccordion = page.locator('[data-state]').filter({ hasText: 'GCMD Science Keywords' });
        await expect(scienceKeywordsAccordion.first()).toBeVisible({ timeout: 10000 });
        console.log('GCMD Science Keywords found in editor!');

        // Look for Platforms accordion
        console.log('Step 4: Looking for GCMD Platforms accordion...');
        const platformsAccordion = page.locator('[data-state]').filter({ hasText: 'GCMD Platforms' });
        await expect(platformsAccordion.first()).toBeVisible({ timeout: 10000 });
        console.log('GCMD Platforms found in editor!');

        // Look for Instruments accordion
        console.log('Step 5: Looking for GCMD Instruments accordion...');
        const instrumentsAccordion = page.locator('[data-state]').filter({ hasText: 'GCMD Instruments' });
        await expect(instrumentsAccordion.first()).toBeVisible({ timeout: 10000 });
        console.log('GCMD Instruments found in editor!');

        console.log('All thesauri are available in the editor (as expected)!');
    });
});

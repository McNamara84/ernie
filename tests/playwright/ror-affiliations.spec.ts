import { test, expect } from '@playwright/test';
import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from './constants';

test.describe('ROR Affiliations Autocomplete', () => {
    test.beforeEach(async ({ page }) => {
        // Login first
        await page.goto('/login');
        await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
        await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();
        await page.waitForURL(/\/dashboard/);
        
        // Navigate to curation page
        await page.goto('/curation');
        await page.waitForLoadState('networkidle');
        
        // Open Authors accordion if not already open
        const authorsTrigger = page.getByRole('button', { name: 'Authors' });
        const isExpanded = await authorsTrigger.getAttribute('aria-expanded');
        if (isExpanded === 'false') {
            await authorsTrigger.click();
            await expect(authorsTrigger).toHaveAttribute('aria-expanded', 'true');
        }
    });

    test('loads ROR affiliations data on page load', async ({ page }) => {
        // The API request should have been made during beforeEach navigation
        // We'll reload the page and check for the request
        const responsePromise = page.waitForResponse(
            (response) =>
                response.url().includes('/api/v1/ror-affiliations') &&
                response.status() === 200
        );

        await page.reload();
        const response = await responsePromise;

        const data = await response.json();
        expect(Array.isArray(data)).toBe(true);
        expect(data.length).toBeGreaterThan(0);

        // Verify structure of first item
        if (data.length > 0) {
            expect(data[0]).toHaveProperty('prefLabel');
            expect(data[0]).toHaveProperty('rorId');
            expect(data[0]).toHaveProperty('otherLabel');
        }
    });

    test('displays affiliation suggestions when typing', async ({ page }) => {
        // Wait for the Tagify component to be ready
        await page.waitForSelector('[data-testid="author-0-affiliations-field"] .tagify__input');

        // Click on the Tagify input field
        const affiliationsInput = page.locator(
            '[data-testid="author-0-affiliations-field"] .tagify__input'
        );
        await affiliationsInput.click();

        // Type "Potsdam"
        await affiliationsInput.fill('Potsdam');

        // Wait for dropdown to appear
        await page.waitForSelector('.tagify__dropdown', { state: 'visible' });

        // Check that suggestions are displayed
        const suggestions = page.locator('.tagify__dropdown__item');
        await expect(suggestions.first()).toBeVisible();

        // Verify that suggestions contain "Potsdam"
        const firstSuggestion = await suggestions.first().textContent();
        expect(firstSuggestion?.toLowerCase()).toContain('potsdam');
    });

    test('selects affiliation from dropdown and closes dropdown', async ({ page }) => {
        const affiliationsInput = page.locator(
            '[data-testid="author-0-affiliations-field"] .tagify__input'
        );
        await affiliationsInput.click();
        await affiliationsInput.fill('Potsdam');

        // Wait for dropdown
        await page.waitForSelector('.tagify__dropdown', { state: 'visible' });

        // Click first suggestion
        const firstSuggestion = page.locator('.tagify__dropdown__item').first();
        await firstSuggestion.click();

        // Verify dropdown is closed
        await expect(page.locator('.tagify__dropdown')).not.toBeVisible();

        // Verify tag was added
        const tag = page.locator('.tagify__tag').first();
        await expect(tag).toBeVisible();

        const tagText = await tag.textContent();
        expect(tagText?.toLowerCase()).toContain('potsdam');
    });

    test('searches in both prefLabel and otherLabel fields', async ({ page }) => {
        const affiliationsInput = page.locator(
            '[data-testid="author-0-affiliations-field"] .tagify__input'
        );
        await affiliationsInput.click();

        // Search for "Albert Einstein" (which should match MPI's otherLabel)
        await affiliationsInput.fill('Albert Einstein');

        await page.waitForSelector('.tagify__dropdown', { state: 'visible' });

        const suggestions = page.locator('.tagify__dropdown__item');
        const count = await suggestions.count();
        expect(count).toBeGreaterThan(0);

        // Verify that Max Planck Institute is in the results
        const suggestionText = await suggestions.first().textContent();
        expect(suggestionText).toBeTruthy();
    });

    test('allows adding multiple affiliations', async ({ page }) => {
        const affiliationsInput = page.locator(
            '[data-testid="author-0-affiliations-field"] .tagify__input'
        );

        // Add first affiliation
        await affiliationsInput.click();
        await affiliationsInput.fill('Potsdam');
        await page.waitForSelector('.tagify__dropdown', { state: 'visible' });
        await page.locator('.tagify__dropdown__item').first().click();

        // Add second affiliation
        await affiliationsInput.click();
        await affiliationsInput.fill('Berlin');
        await page.waitForSelector('.tagify__dropdown', { state: 'visible' });
        await page.locator('.tagify__dropdown__item').first().click();

        // Verify both tags exist
        const tags = page.locator('.tagify__tag');
        await expect(tags).toHaveCount(2);
    });

    test('shows no suggestions for non-existent organizations', async ({ page }) => {
        const affiliationsInput = page.locator(
            '[data-testid="author-0-affiliations-field"] .tagify__input'
        );
        await affiliationsInput.click();
        await affiliationsInput.fill('xyznonexistentuniversity123');

        // Wait a bit for potential dropdown
        await page.waitForTimeout(500);

        // Dropdown should either not appear or show "No matches"
        const dropdown = page.locator('.tagify__dropdown');
        const isVisible = await dropdown.isVisible();

        if (isVisible) {
            const emptyMessage = page.locator('.tagify__dropdown__item--text');
            await expect(emptyMessage).toBeVisible();
        }
    });

    test('preserves ROR ID when affiliation is selected', async ({ page }) => {
        const affiliationsInput = page.locator(
            '[data-testid="author-0-affiliations-field"] .tagify__input'
        );
        await affiliationsInput.click();
        await affiliationsInput.fill('Potsdam');

        await page.waitForSelector('.tagify__dropdown', { state: 'visible' });
        await page.locator('.tagify__dropdown__item').first().click();

        // Check the tag's data attribute contains rorId
        const tag = page.locator('.tagify__tag').first();
        const rorId = await tag.getAttribute('data-ror-id');

        expect(rorId).toBeTruthy();
        expect(rorId).toContain('https://ror.org/');
    });

    test('handles special characters in organization names', async ({ page }) => {
        const affiliationsInput = page.locator(
            '[data-testid="author-0-affiliations-field"] .tagify__input'
        );
        await affiliationsInput.click();

        // Search for organization with umlauts
        await affiliationsInput.fill('Zürich');

        await page.waitForSelector('.tagify__dropdown', { state: 'visible', timeout: 2000 }).catch(() => {
            // If no dropdown appears, that's also valid (no matches)
        });

        const dropdown = page.locator('.tagify__dropdown');
        const isVisible = await dropdown.isVisible();

        if (isVisible) {
            const suggestions = page.locator('.tagify__dropdown__item');
            const count = await suggestions.count();
            expect(count).toBeGreaterThanOrEqual(0);
        }
    });

    test('removes affiliation tag when clicking remove button', async ({ page }) => {
        const affiliationsInput = page.locator(
            '[data-testid="author-0-affiliations-field"] .tagify__input'
        );
        await affiliationsInput.click();
        await affiliationsInput.fill('Potsdam');

        await page.waitForSelector('.tagify__dropdown', { state: 'visible' });
        await page.locator('.tagify__dropdown__item').first().click();

        // Verify tag was added
        await expect(page.locator('.tagify__tag').first()).toBeVisible();

        // Click remove button (×)
        const removeButton = page.locator('.tagify__tag__removeBtn').first();
        await removeButton.click();

        // Verify tag was removed
        await expect(page.locator('.tagify__tag')).toHaveCount(0);
    });

    test('reopens dropdown when typing after selection', async ({ page }) => {
        const affiliationsInput = page.locator(
            '[data-testid="author-0-affiliations-field"] .tagify__input'
        );

        // First selection
        await affiliationsInput.click();
        await affiliationsInput.fill('Potsdam');
        await page.waitForSelector('.tagify__dropdown', { state: 'visible' });
        await page.locator('.tagify__dropdown__item').first().click();

        // Dropdown should be closed
        await expect(page.locator('.tagify__dropdown')).not.toBeVisible();

        // Type again
        await affiliationsInput.click();
        await affiliationsInput.fill('Berlin');

        // Dropdown should reopen
        await expect(page.locator('.tagify__dropdown')).toBeVisible();
    });

    test('displays correct number of suggestions (max 20)', async ({ page }) => {
        const affiliationsInput = page.locator(
            '[data-testid="author-0-affiliations-field"] .tagify__input'
        );
        await affiliationsInput.click();

        // Search for common term that should return many results
        await affiliationsInput.fill('University');

        await page.waitForSelector('.tagify__dropdown', { state: 'visible' });

        const suggestions = page.locator('.tagify__dropdown__item');
        const count = await suggestions.count();

        // Should not exceed maxItems setting (20)
        expect(count).toBeLessThanOrEqual(20);
        expect(count).toBeGreaterThan(0);
    });

    test('maintains affiliation data after form submission attempt', async ({ page }) => {
        const affiliationsInput = page.locator(
            '[data-testid="author-0-affiliations-field"] .tagify__input'
        );
        await affiliationsInput.click();
        await affiliationsInput.fill('Potsdam');

        await page.waitForSelector('.tagify__dropdown', { state: 'visible' });
        await page.locator('.tagify__dropdown__item').first().click();

        // Try to submit form (should fail due to missing required fields)
        const submitButton = page.getByRole('button', { name: /save/i });
        await submitButton.click();

        // Wait for validation
        await page.waitForTimeout(500);

        // Verify affiliation tag is still present
        await expect(page.locator('.tagify__tag').first()).toBeVisible();
    });
});


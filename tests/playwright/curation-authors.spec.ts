import { expect, test } from '@playwright/test';
import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from './constants';

test.beforeEach(async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
    await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL(/\/dashboard/);
    await page.goto('/curation');
    
    // Open Authors accordion if not already open
    const authorsTrigger = page.getByRole('button', { name: 'Authors' });
    const isExpanded = await authorsTrigger.getAttribute('aria-expanded');
    if (isExpanded === 'false') {
        await authorsTrigger.click();
        await page.waitForTimeout(300); // Wait for accordion animation
    }
});

test('user can add and remove author rows', async ({ page }) => {
    // Initially one author should be present
    await expect(page.getByText('Author 1')).toBeVisible();
    
    // Fill first author
    await page.getByLabel('Last name').fill('Doe');
    
    // Add second author
    const addButton = page.getByRole('button', { name: 'Add author' }).first();
    await addButton.click();
    await expect(page.getByText('Author 2')).toBeVisible();
    
    // Fill second author
    await page.getByLabel('Last name').nth(1).fill('Smith');
    
    // Remove second author
    const deleteButtons = page.getByRole('button', { name: /Remove author/ });
    await expect(deleteButtons).toHaveCount(2);
    await deleteButtons.nth(1).click();
    
    // Should be back to one author
    await expect(page.getByText('Author 2')).not.toBeVisible();
    await expect(page.getByText('Author 1')).toBeVisible();
    await expect(page.getByLabel('Last name')).toHaveValue('Doe');
});

test('user can switch between person and institution author types', async ({ page }) => {
    // Initially person type should be selected
    const authorType = page.getByRole('combobox', { name: 'Author type' });
    await expect(authorType).toHaveText('Person');
    
    // Person fields should be visible
    await expect(page.getByLabel('ORCID')).toBeVisible();
    await expect(page.getByLabel('First name')).toBeVisible();
    await expect(page.getByLabel('Last name')).toBeVisible();
    await expect(page.getByLabel('Contact person')).toBeVisible();
    
    // Switch to Institution
    await authorType.click();
    await page.getByRole('option', { name: 'Institution' }).click();
    
    // Institution fields should be visible, person fields hidden
    await expect(page.getByLabel('Institution name')).toBeVisible();
    await expect(page.getByLabel('ORCID')).not.toBeVisible();
    await expect(page.getByLabel('First name')).not.toBeVisible();
    await expect(page.getByLabel('Last name')).not.toBeVisible();
    await expect(page.getByLabel('Contact person')).not.toBeVisible();
    
    // Affiliations should be visible for both types
    await expect(page.getByText('Affiliations')).toBeVisible();
    
    // Switch back to Person
    await authorType.click();
    await page.getByRole('option', { name: 'Person' }).click();
    
    // Person fields should be visible again
    await expect(page.getByLabel('ORCID')).toBeVisible();
    await expect(page.getByLabel('First name')).toBeVisible();
    await expect(page.getByLabel('Last name')).toBeVisible();
});

test('contact person checkbox shows email and website fields', async ({ page }) => {
    // Initially contact fields should not be visible
    await expect(page.getByLabel('Email')).not.toBeVisible();
    await expect(page.getByLabel('Website')).not.toBeVisible();
    
    // Click contact person checkbox
    const contactCheckbox = page.getByRole('checkbox', { name: 'Contact person' });
    await contactCheckbox.click();
    
    // Email and website fields should now be visible
    await expect(page.getByLabel('Email')).toBeVisible();
    await expect(page.getByLabel('Website')).toBeVisible();
    
    // Fill email
    await page.getByLabel('Email').fill('author@example.com');
    await page.getByLabel('Website').fill('https://example.com');
    
    // Uncheck contact person
    await contactCheckbox.click();
    
    // Fields should be hidden again
    await expect(page.getByLabel('Email')).not.toBeVisible();
    await expect(page.getByLabel('Website')).not.toBeVisible();
});

test('user can add multiple authors and manage them independently', async ({ page }) => {
    // Add three authors
    const addButton = page.getByRole('button', { name: 'Add author' }).first();
    
    await page.getByLabel('Last name').fill('Author One');
    await addButton.click();
    await expect(page.getByText('Author 2')).toBeVisible();
    
    await page.getByLabel('Last name').nth(1).fill('Author Two');
    await addButton.click();
    await expect(page.getByText('Author 3')).toBeVisible();
    
    await page.getByLabel('Last name').nth(2).fill('Author Three');
    
    // Switch second author to institution
    const authorType2 = page.getByRole('combobox', { name: 'Author type' }).nth(1);
    await authorType2.click();
    await page.getByRole('option', { name: 'Institution' }).click();
    await page.getByLabel('Institution name').fill('Test University');
    
    // Verify all authors are present
    await expect(page.getByText('Author 1')).toBeVisible();
    await expect(page.getByText('Author 2')).toBeVisible();
    await expect(page.getByText('Author 3')).toBeVisible();
    
    // Verify first author is still person
    await expect(page.getByLabel('Last name').first()).toHaveValue('Author One');
    
    // Verify third author is still person
    await expect(page.getByLabel('Last name').nth(1)).toHaveValue('Author Three');
    
    // Remove middle (institution) author
    const deleteButtons = page.getByRole('button', { name: /Remove author/ });
    await deleteButtons.nth(1).click();
    
    // Should now have 2 authors
    await expect(page.getByText('Author 1')).toBeVisible();
    await expect(page.getByText('Author 2')).toBeVisible();
    await expect(page.getByText('Author 3')).not.toBeVisible();
    
    // Second author should now be the former third author
    await expect(page.getByLabel('Last name').nth(1)).toHaveValue('Author Three');
});

test('contact person tooltip provides guidance', async ({ page }) => {
    // Hover over CP label to show tooltip
    const cpLabel = page.locator('label', { hasText: /^CP$/ }).first();
    await cpLabel.hover();
    
    // Wait for tooltip to appear
    await page.waitForTimeout(500);
    
    // Tooltip should contain guidance text
    await expect(page.getByText(/Contact Person.*primary contact/i)).toBeVisible();
});

test('preserves author data when navigating accordion sections', async ({ page }) => {
    // Fill author data
    await page.getByLabel('ORCID').fill('0000-0001-2345-6789');
    await page.getByLabel('First name').fill('Jane');
    await page.getByLabel('Last name').fill('Researcher');
    
    // Close Authors accordion
    await page.getByRole('button', { name: 'Authors' }).click();
    await page.waitForTimeout(300);
    
    // Open another accordion section (e.g., Resource Information)
    const resourceInfo = page.getByRole('button', { name: 'Resource Information' });
    const isExpanded = await resourceInfo.getAttribute('aria-expanded');
    if (isExpanded === 'false') {
        await resourceInfo.click();
        await page.waitForTimeout(300);
    }
    
    // Re-open Authors accordion
    await page.getByRole('button', { name: 'Authors' }).click();
    await page.waitForTimeout(300);
    
    // Data should be preserved
    await expect(page.getByLabel('ORCID')).toHaveValue('0000-0001-2345-6789');
    await expect(page.getByLabel('First name')).toHaveValue('Jane');
    await expect(page.getByLabel('Last name')).toHaveValue('Researcher');
});

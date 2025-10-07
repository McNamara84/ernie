import { expect, test, Locator, Page } from '@playwright/test';
import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from './constants';

/**
 * Wait for an accordion button to reach the specified expanded state.
 */
async function waitForAccordionState(accordionButton: Locator, expanded: boolean) {
    await expect(accordionButton).toHaveAttribute('aria-expanded', String(expanded));
}

function getAuthorRegion(page: Page, index: number) {
    return page.getByRole('region', { name: `Author ${index + 1}` });
}

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
        await waitForAccordionState(authorsTrigger, true);
    }
});

test('user can add and remove author rows', async ({ page }) => {
    // Initially one author should be present
    await expect(page.getByText('Author 1')).toBeVisible();
    
    // Fill first author
    await getAuthorRegion(page, 0).getByLabel('Last name').fill('Doe');
    
    // Add second author
    const addButton = page.getByRole('button', { name: 'Add author' }).first();
    await addButton.click();
    await expect(page.getByText('Author 2')).toBeVisible();
    
    // Fill second author
    await getAuthorRegion(page, 1).getByLabel('Last name').fill('Smith');
    
    // Remove second author
    const deleteButtons = page.getByRole('button', { name: /Remove author/ });
    await expect(deleteButtons).toHaveCount(2);
    await deleteButtons.nth(1).click();
    
    // Should be back to one author
    await expect(page.getByText('Author 2')).not.toBeVisible();
    await expect(page.getByText('Author 1')).toBeVisible();
    await expect(getAuthorRegion(page, 0).getByLabel('Last name')).toHaveValue('Doe');
});

test('user can switch between person and institution author types', async ({ page }) => {
    // Initially person type should be selected
    const authorType = getAuthorRegion(page, 0).getByRole('combobox', { name: 'Author type' });
    await expect(authorType).toHaveText('Person');
    
    // Person fields should be visible
    const author1 = getAuthorRegion(page, 0);
    await expect(author1.getByLabel('ORCID')).toBeVisible();
    await expect(author1.getByLabel('First name')).toBeVisible();
    await expect(author1.getByLabel('Last name')).toBeVisible();
    await expect(author1.getByLabel('Contact person')).toBeVisible();
    
    // Switch to Institution
    await authorType.click();
    await page.getByRole('option', { name: 'Institution' }).click();
    
    // Institution fields should be visible, person fields hidden
    await expect(author1.getByLabel('Institution name')).toBeVisible();
    await expect(author1.getByLabel('ORCID')).not.toBeVisible();
    await expect(author1.getByLabel('First name')).not.toBeVisible();
    await expect(author1.getByLabel('Last name')).not.toBeVisible();
    await expect(author1.getByLabel('Contact person')).not.toBeVisible();
    
    // Affiliations should be visible for both types
    await expect(author1.getByText('Affiliations')).toBeVisible();
    
    // Switch back to Person
    await authorType.click();
    await page.getByRole('option', { name: 'Person' }).click();
    
    // Person fields should be visible again
    await expect(author1.getByLabel('ORCID')).toBeVisible();
    await expect(author1.getByLabel('First name')).toBeVisible();
    await expect(author1.getByLabel('Last name')).toBeVisible();
});

test('contact person checkbox shows email and website fields', async ({ page }) => {
    // Initially contact fields should not be visible
    const author = getAuthorRegion(page, 0);
    await expect(author.getByLabel('Email address')).not.toBeVisible();
    await expect(author.getByLabel('Website')).not.toBeVisible();
    
    // Click contact person checkbox
    const contactCheckbox = author.getByRole('checkbox', { name: 'Contact person' });
    await contactCheckbox.click();
    
    // Email and website fields should now be visible
    await expect(author.getByLabel('Email address')).toBeVisible();
    await expect(author.getByLabel('Website')).toBeVisible();
    
    // Fill email
    await author.getByLabel('Email address').fill('author@example.com');
    await author.getByLabel('Website').fill('https://example.com');
    
    // Uncheck contact person
    await contactCheckbox.click();
    
    // Fields should be hidden again
    await expect(author.getByLabel('Email address')).not.toBeVisible();
    await expect(author.getByLabel('Website')).not.toBeVisible();
});

test('user can add multiple authors and manage them independently', async ({ page }) => {
    // Add three authors
    const addButton = page.getByRole('button', { name: 'Add author' }).first();
    
    await getAuthorRegion(page, 0).getByLabel('Last name').fill('Author One');
    await addButton.click();
    await expect(page.getByText('Author 2')).toBeVisible();
    
    await getAuthorRegion(page, 1).getByLabel('Last name').fill('Author Two');
    await addButton.click();
    await expect(page.getByText('Author 3')).toBeVisible();
    
    await getAuthorRegion(page, 2).getByLabel('Last name').fill('Author Three');
    
    // Switch second author to institution
    const authorType2 = getAuthorRegion(page, 1).getByRole('combobox', { name: 'Author type' });
    await authorType2.click();
    await page.getByRole('option', { name: 'Institution' }).click();
    await getAuthorRegion(page, 1).getByLabel('Institution name').fill('Test University');
    
    // Verify all authors are present
    await expect(page.getByText('Author 1')).toBeVisible();
    await expect(page.getByText('Author 2')).toBeVisible();
    await expect(page.getByText('Author 3')).toBeVisible();
    
    // Verify first author is still person
    await expect(getAuthorRegion(page, 0).getByLabel('Last name')).toHaveValue('Author One');
    
    // Verify third author is still person
    await expect(getAuthorRegion(page, 2).getByLabel('Last name')).toHaveValue('Author Three');
    
    // Remove middle (institution) author
    const deleteButtons = page.getByRole('button', { name: /Remove author/ });
    await deleteButtons.nth(1).click();
    
    // Should now have 2 authors
    await expect(page.getByText('Author 1')).toBeVisible();
    await expect(page.getByText('Author 2')).toBeVisible();
    await expect(page.getByText('Author 3')).not.toBeVisible();
    
    // Second author should now be the former third author
    await expect(getAuthorRegion(page, 1).getByLabel('Last name')).toHaveValue('Author Three');
});

test('contact person tooltip provides guidance', async ({ page }) => {
    // The CP label has a tooltip - we can verify it's there by checking the checkbox's accessible name
    const contactCheckbox = getAuthorRegion(page, 0).getByRole('checkbox', { name: 'Contact person' });
    await expect(contactCheckbox).toBeVisible();
    
    // The checkbox should have the correct accessible name from the sr-only text
    // No need to wait for tooltip animation - the accessible name is always present
    await expect(contactCheckbox).toHaveAccessibleName('Contact person');
});

test('preserves author data when navigating accordion sections', async ({ page }) => {
    // Fill author data
    const authorSection = getAuthorRegion(page, 0);
    await authorSection.getByLabel('ORCID').fill('0000-0001-2345-6789');
    await authorSection.getByLabel('First name').fill('Jane');
    await authorSection.getByLabel('Last name').fill('Researcher');
    
    // Close Authors accordion
    const authorsTrigger = page.getByRole('button', { name: 'Authors' });
    await authorsTrigger.click();
    await waitForAccordionState(authorsTrigger, false);
    
    // Open another accordion section (e.g., Resource Information)
    const resourceInfo = page.getByRole('button', { name: 'Resource Information' });
    const isExpanded = await resourceInfo.getAttribute('aria-expanded');
    if (isExpanded === 'false') {
        await resourceInfo.click();
        await waitForAccordionState(resourceInfo, true);
    }
    
    // Re-open Authors accordion
    await authorsTrigger.click();
    await waitForAccordionState(authorsTrigger, true);
    
    // Data should be preserved
    await expect(authorSection.getByLabel('ORCID')).toHaveValue('0000-0001-2345-6789');
    await expect(authorSection.getByLabel('First name')).toHaveValue('Jane');
    await expect(authorSection.getByLabel('Last name')).toHaveValue('Researcher');
});

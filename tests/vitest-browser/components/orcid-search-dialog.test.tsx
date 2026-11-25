import { OrcidSearchDialog } from '@/components/curation/fields/orcid-search-dialog';

import { commands, expect, page, render, test, vi } from '../helpers/test';

const result = { orcid: '0000-0002-1825-0097', firstName: 'Ada', lastName: 'Lovelace', creditName: null, institutions: ['GFZ'] };

test('searches the intercepted ORCID API, selects a record and restores trigger focus', async () => {
    await commands.mockApi([{ path: '/api/v1/orcid/search', body: { success: true, data: { results: [result], total: 1 } } }]);
    const onSelect = vi.fn();
    await render(<OrcidSearchDialog onSelect={onSelect} />);
    const trigger = page.getByRole('button', { name: 'Search for ORCID' });
    await trigger.click();
    await page.getByRole('searchbox', { name: 'Person name' }).fill('Ada');
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await expect.element(page.getByRole('status')).toHaveTextContent('1 result found');
    await page.getByRole('button', { name: /Lovelace, Ada/ }).click();
    expect(onSelect).toHaveBeenCalledExactlyOnceWith(result);
    await expect.element(page.getByRole('dialog')).not.toBeInTheDocument();
    await expect.element(trigger).toHaveFocus();
});

test('shows an empty search and resets it when the dialog is reopened', async ({ user }) => {
    await commands.mockApi([{ path: '/api/v1/orcid/search', body: { success: true, data: { results: [], total: 0 } } }]);
    await render(<OrcidSearchDialog onSelect={vi.fn()} />);
    await page.getByRole('button', { name: 'Search for ORCID' }).click();
    await page.getByRole('searchbox').fill('Nobody');
    await user.keyboard('{Enter}');
    await expect.element(page.getByText(/No ORCID records found/)).toBeVisible();
    await user.keyboard('{Escape}');
    await page.getByRole('button', { name: 'Search for ORCID' }).click();
    await expect.element(page.getByRole('searchbox')).toHaveValue('');
    await expect.element(page.getByRole('status')).toHaveTextContent('Enter at least two characters to search.');
});

import { OrcidSearchDialog } from '@/components/curation/fields/orcid-search-dialog';

import { commands, expect, page, render, test, vi } from '../helpers/test';

const result = { orcid: '0000-0002-1825-0097', firstName: 'Ada', lastName: 'Lovelace', creditName: null, institutions: ['GFZ'] };

test('searches the intercepted ORCID API, selects a record and restores trigger focus', async () => {
    await commands.mockApi([{ path: '/api/v1/orcid/search', body: { success: true, data: { results: [result], total: 1 } } }]);
    const onSelect = vi.fn();
    await render(<OrcidSearchDialog onSelect={onSelect} />);
    const trigger = page.getByRole('button', { name: 'Search for ORCID' });
    await trigger.click();
    await page.getByPlaceholder('Search by name, institution, keywords...').fill('Ada');
    await page.getByRole('option').click();
    expect(onSelect).toHaveBeenCalledExactlyOnceWith(result);
    await expect.element(page.getByRole('dialog')).not.toBeInTheDocument();
    await expect.element(trigger).toHaveFocus();
});

test('shows an empty search and resets it when the dialog is reopened', async ({ user }) => {
    await commands.mockApi([{ path: '/api/v1/orcid/search', body: { success: true, data: { results: [], total: 0 } } }]);
    await render(<OrcidSearchDialog onSelect={vi.fn()} />);
    await page.getByRole('button', { name: 'Search for ORCID' }).click();
    await page.getByPlaceholder('Search by name, institution, keywords...').fill('Nobody');
    await expect.element(page.getByText(/No ORCID records found/)).toBeVisible();
    await user.keyboard('{Escape}');
    await page.getByRole('button', { name: 'Search for ORCID' }).click();
    await expect.element(page.getByPlaceholder('Search by name, institution, keywords...')).toHaveValue('');
    await expect.element(page.getByText('Start typing to search ORCID records', { exact: true })).toBeVisible();
});

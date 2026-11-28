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

test('wraps long results on a narrow screen and keeps the profile link separately reachable', async ({ user }) => {
    const { innerWidth, innerHeight } = window;
    const institution = 'Helmholtz Centre for Geosciences, Department of Geodesy and Earth Observation';
    const person = { ...result, institutions: [institution] };
    await commands.mockApi([{ path: '/api/v1/orcid/search', body: { success: true, data: { results: [person], total: 1 } } }]);
    const onSelect = vi.fn();
    try {
        await page.viewport(360, 740);
        await render(<OrcidSearchDialog onSelect={onSelect} />);
        await page.getByRole('button', { name: 'Search for ORCID' }).click();
        await page.getByRole('searchbox').fill('Lovelace, Ada');
        await user.keyboard('{Enter}');
        const choice = page.getByRole('button', { name: /Lovelace, Ada/ });
        await expect.element(choice).toBeVisible();

        const affiliation = page.getByText(institution, { exact: true }).element();
        const textRange = document.createRange();
        textRange.selectNodeContents(affiliation);
        expect(textRange.getClientRects().length).toBeGreaterThan(1);
        expect(textRange.getBoundingClientRect().bottom).toBeLessThanOrEqual(choice.element().getBoundingClientRect().bottom);
        const row = page.getByRole('listitem').element();
        expect(row.scrollWidth).toBeLessThanOrEqual(row.clientWidth);

        await user.keyboard('{ArrowDown}');
        await expect.element(choice).toHaveFocus();
        await user.keyboard('{Tab}');
        await expect.element(page.getByRole('link', { name: `View ${person.orcid} on ORCID.org` })).toHaveFocus();
        expect(onSelect).not.toHaveBeenCalled();
        await user.keyboard('{Shift>}{Tab}{/Shift}{Enter}');
        expect(onSelect).toHaveBeenCalledExactlyOnceWith(person);
    } finally {
        await page.viewport(innerWidth, innerHeight);
    }
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

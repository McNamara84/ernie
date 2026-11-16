import { useState } from 'react';

import { AsyncCombobox, type AsyncComboboxOption } from '@/components/ui/async-combobox';

import { commands, expect, page, render, test, vi } from '../helpers/test';

const options = [
    { value: 'gfz', label: 'GFZ Potsdam' },
    { value: 'mit', label: 'MIT' },
];

test('advances fake timers while waiting for a debounced option, then clears the selection', async () => {
    const onSearch = vi.fn().mockResolvedValue(options);
    const onChange = vi.fn();
    function Search() {
        const [selected, setSelected] = useState<AsyncComboboxOption>();
        return (
            <AsyncCombobox
                onSearch={onSearch}
                value={selected?.value}
                selectedOption={selected}
                onChange={(value, option) => {
                    onChange(value, option);
                    setSelected(option);
                }}
            />
        );
    }
    await render(<Search />);
    await page.getByRole('combobox').click();
    vi.useFakeTimers();
    await page.getByPlaceholder('Type to search...').fill('GFZ');
    expect(onSearch).not.toHaveBeenCalled();
    await page.getByRole('option', { name: 'GFZ Potsdam' }).click();
    expect(onSearch).toHaveBeenCalledExactlyOnceWith('GFZ');
    expect(onChange).toHaveBeenCalledExactlyOnceWith('gfz', options[0]);
    await expect.element(page.getByRole('combobox')).toHaveTextContent('GFZ Potsdam');
    await page.getByLabelText('Clear selection').click();
    expect(onChange).toHaveBeenLastCalledWith(undefined, undefined);
});

test('uses the intercepted HTTP response without replacing the search UI', async ({ user }) => {
    await commands.mockApi([{ path: '/api/browser-test/search', body: options }]);
    const onSearch = vi.fn(async (query: string) => {
        const response = await fetch(`/api/browser-test/search?q=${encodeURIComponent(query)}`);
        return response.json() as Promise<AsyncComboboxOption[]>;
    });
    const onChange = vi.fn();
    await render(<AsyncCombobox onSearch={onSearch} onChange={onChange} debounceMs={20} />);
    await page.getByRole('combobox').click();
    await page.getByPlaceholder('Type to search...').fill('GFZ');
    await expect.element(page.getByRole('option', { name: 'GFZ Potsdam' })).toBeVisible();
    await user.keyboard('{Home}');
    await expect.element(page.getByRole('option', { name: 'GFZ Potsdam' })).toHaveAttribute('aria-selected', 'true');
    await user.keyboard('{Enter}');
    expect(onChange).toHaveBeenCalledWith('gfz', options[0]);
});

test('shows an empty response and prevents searching below the minimum length', async () => {
    const onSearch = vi.fn().mockResolvedValue([]);
    await render(<AsyncCombobox onSearch={onSearch} minSearchLength={3} debounceMs={20} emptyMessage="No organizations found" />);
    await page.getByRole('combobox').click();
    await page.getByPlaceholder('Type to search...').fill('GF');
    await expect.element(page.getByText(/Type at least 3 characters/)).toBeVisible();
    expect(onSearch).not.toHaveBeenCalled();
    await page.getByPlaceholder('Type to search...').fill('GFZ');
    await expect.element(page.getByText('No organizations found', { exact: true })).toBeVisible();
    expect(onSearch).toHaveBeenCalledExactlyOnceWith('GFZ');
});

test('handles a search failure and verifies its expected diagnostic', async () => {
    using error = vi.spyOn(console, 'error').mockImplementation(() => {});
    const failure = new Error('Search offline');
    await render(<AsyncCombobox onSearch={vi.fn().mockRejectedValue(failure)} debounceMs={20} />);
    await page.getByRole('combobox').click();
    await page.getByPlaceholder('Type to search...').fill('GFZ');
    await expect.element(page.getByText('No results found.', { exact: true })).toBeVisible();
    expect(error).toHaveBeenCalledExactlyOnceWith('AsyncCombobox search error:', failure);
});

test('cancels a pending debounce when unmounted', async () => {
    const onSearch = vi.fn().mockResolvedValue(options);
    const view = await render(<AsyncCombobox onSearch={onSearch} />);
    await page.getByRole('combobox').click();
    vi.useFakeTimers();
    await page.getByPlaceholder('Type to search...').fill('GFZ');
    await view.unmount();
    await vi.advanceTimersByTimeAsync(1000);
    expect(onSearch).not.toHaveBeenCalled();
});

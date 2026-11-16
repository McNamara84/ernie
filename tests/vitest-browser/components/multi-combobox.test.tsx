import { AsyncCombobox } from '@/components/ui/async-combobox';
import { Combobox } from '@/components/ui/combobox';

import { expect, page, render, test, vi } from '../helpers/test';

const options = [{ value: 'gfz', label: 'GFZ Potsdam' }];

test.for([false, true])('removes a badge and opens a valid multi-select trigger with the keyboard (async: %s)', async (asynchronous, { user }) => {
    const onValuesChange = vi.fn();
    await render(
        <>
            <label htmlFor="organization">Organizations</label>
            {asynchronous ? (
                <AsyncCombobox
                    id="organization"
                    multiple
                    values={['gfz']}
                    selectedOptions={options}
                    onValuesChange={onValuesChange}
                    onSearch={vi.fn().mockResolvedValue(options)}
                />
            ) : (
                <Combobox id="organization" multiple values={['gfz']} options={options} onValuesChange={onValuesChange} />
            )}
        </>,
    );
    const trigger = page.getByRole('combobox');
    expect(trigger.element().tagName).toBe('DIV');
    expect(trigger.element().querySelector('button button')).toBeNull();
    await expect.element(page.getByLabelText('Organizations')).toHaveAttribute('role', 'combobox');
    await page.getByText('Organizations', { exact: true }).click();
    await expect.element(trigger).toHaveFocus();
    await page.getByRole('button', { name: 'Remove GFZ Potsdam' }).click();
    if (asynchronous) expect(onValuesChange).toHaveBeenCalledExactlyOnceWith([], []);
    else expect(onValuesChange).toHaveBeenCalledExactlyOnceWith([]);
    await expect.element(trigger).toHaveAttribute('aria-expanded', 'false');
    trigger.element().focus();
    await user.keyboard('{Enter}');
    await expect.element(page.getByPlaceholder(asynchronous ? 'Type to search...' : 'Search...')).toBeVisible();
    await user.keyboard('{Escape}');
    await expect.element(trigger).toHaveFocus();
});

test.for([false, true])('keeps a disabled multi-select and its remove buttons inactive (async: %s)', async (asynchronous) => {
    await render(
        <>
            <label htmlFor="disabled-organization">Organizations</label>
            {asynchronous ? (
                <AsyncCombobox
                    id="disabled-organization"
                    disabled
                    multiple
                    values={['gfz']}
                    selectedOptions={options}
                    onSearch={vi.fn().mockResolvedValue([])}
                />
            ) : (
                <Combobox id="disabled-organization" disabled multiple values={['gfz']} options={options} />
            )}
        </>,
    );
    const trigger = page.getByRole('combobox');
    await expect.element(trigger).toHaveAttribute('aria-disabled', 'true');
    await expect.element(trigger).toHaveAttribute('tabindex', '-1');
    await expect.element(page.getByRole('button', { name: 'Remove GFZ Potsdam' })).toBeDisabled();
    await page.getByText('Organizations', { exact: true }).click();
    await expect.element(trigger).not.toHaveFocus();
    await expect.element(trigger).toHaveAttribute('aria-expanded', 'false');
});

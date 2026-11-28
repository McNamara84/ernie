import { useState } from 'react';
import { describe, vi } from 'vitest';

import { SelectField } from '@/components/curation/fields/select-field';
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectSeparator, SelectTrigger, SelectValue } from '@/components/ui/select';

import { expect, page, render, test } from '../helpers/test';

function FruitSelect({ open = false }: { open?: boolean }) {
    return (
        <Select defaultValue="apple" defaultOpen={open}>
            <SelectTrigger aria-label="Fruit">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectGroup>
                    <SelectLabel>Fruits</SelectLabel>
                    <SelectItem value="apple">Apple</SelectItem>
                </SelectGroup>
                <SelectSeparator />
                <SelectItem value="banana">Banana</SelectItem>
                <SelectItem value="pear" disabled>
                    Pear
                </SelectItem>
            </SelectContent>
        </Select>
    );
}

describe('Select', () => {
    test('renders groups and items when open', async () => {
        await render(<FruitSelect open />);
        await expect.element(page.getByText('Fruits', { exact: true })).toBeVisible();
        await expect.element(page.getByRole('option', { name: 'Apple' })).toBeVisible();
        await expect.element(page.getByRole('option', { name: 'Banana' })).toBeVisible();
    });

    test('updates trigger text when selecting an item', async () => {
        await render(<FruitSelect />);
        const trigger = page.getByRole('combobox', { name: 'Fruit' });
        await expect.element(trigger).toHaveTextContent('Apple');
        await trigger.click();
        await page.getByRole('option', { name: 'Banana' }).click();
        await expect.element(trigger).toHaveTextContent('Banana');
        await expect.element(trigger).toHaveFocus();
        await expect.element(page.getByRole('listbox')).not.toBeInTheDocument();
    });

    test('supports keyboard selection and skips disabled options', async ({ user }) => {
        await render(<FruitSelect />);
        const trigger = page.getByRole('combobox', { name: 'Fruit' });
        trigger.element().focus();
        await user.keyboard('{ArrowDown}');
        await expect.element(page.getByRole('option', { name: 'Apple' })).toHaveFocus();
        await user.keyboard('{End}');
        await expect.element(page.getByRole('option', { name: 'Banana' })).toHaveFocus();
        await user.keyboard('{Enter}');
        await expect.element(trigger).toHaveTextContent('Banana');
        await expect.element(trigger).toHaveFocus();
    });
});

test('a searchable editor select fits its trigger and can be cleared from the keyboard', async ({ user }) => {
    const onValidationBlur = vi.fn();
    function OptionalFruitField() {
        const [value, setValue] = useState('apple');
        return (
            <div style={{ width: 420 }}>
                <SelectField
                    id="fruit"
                    label="Fruit"
                    searchable
                    clearable
                    value={value}
                    onValueChange={setValue}
                    onValidationBlur={onValidationBlur}
                    options={[{ value: 'apple', label: 'Apple' }]}
                    placeholder="Choose fruit"
                />
            </div>
        );
    }
    await render(<OptionalFruitField />);
    const trigger = page.getByRole('combobox', { name: 'Fruit' });
    trigger.element().focus();
    await user.keyboard('{Enter}');
    const popup = page.getByRole('dialog');
    await expect.element(popup).toBeVisible();
    expect(getComputedStyle(popup.element()).width).toBe(getComputedStyle(trigger.element()).width);
    await user.keyboard('{Enter}');
    await expect.element(trigger).toHaveTextContent('Choose fruit');
    await expect.element(trigger).toHaveFocus();
    await expect.poll(() => onValidationBlur.mock.calls.length).toBe(1);
});

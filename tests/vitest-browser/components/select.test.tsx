import { describe } from 'vitest';

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

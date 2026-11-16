import { useState } from 'react';
import { describe } from 'vitest';

import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';

import { expect, page, render, test, vi } from '../helpers/test';

test('opens a popover with the keyboard and restores focus after Escape', async ({ user }) => {
    await render(
        <Popover>
            <PopoverTrigger>Edit</PopoverTrigger>
            <PopoverContent>
                <input aria-label="Name" />
            </PopoverContent>
        </Popover>,
    );
    page.getByRole('button', { name: 'Edit' }).element().focus();
    await user.keyboard('{Enter}');
    await expect.element(page.getByRole('textbox', { name: 'Name' })).toHaveFocus();
    await user.keyboard('{Escape}');
    await expect.element(page.getByRole('textbox', { name: 'Name' })).not.toBeInTheDocument();
    await expect.element(page.getByRole('button', { name: 'Edit' })).toHaveFocus();
});

test('dismisses a popover on an outside pointer interaction', async () => {
    await render(
        <>
            <button>Outside</button>
            <Popover>
                <PopoverTrigger>Edit</PopoverTrigger>
                <PopoverContent>Popover body</PopoverContent>
            </Popover>
        </>,
    );
    await page.getByRole('button', { name: 'Edit' }).click();
    await expect.element(page.getByText('Popover body', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Outside' }).click();
    await expect.element(page.getByText('Popover body', { exact: true })).not.toBeInTheDocument();
});

describe('Tooltip', () => {
    test('renders content when open', async () => {
        await render(
            <Tooltip defaultOpen>
                <TooltipTrigger>Hover me</TooltipTrigger>
                <TooltipContent>Tooltip text</TooltipContent>
            </Tooltip>,
        );
        await expect.element(page.getByRole('tooltip')).toHaveTextContent('Tooltip text');
        expect(document.querySelector('[data-slot="tooltip-content"]')).toBeVisible();
    });

    test('shows a tooltip on keyboard focus and dismisses it with Escape', async ({ user }) => {
        await render(
            <Tooltip>
                <TooltipTrigger>Help</TooltipTrigger>
                <TooltipContent>Keyboard help</TooltipContent>
            </Tooltip>,
        );
        page.getByRole('button', { name: 'Help' }).element().focus();
        await expect.element(page.getByRole('tooltip')).toHaveTextContent('Keyboard help');
        await user.keyboard('{Escape}');
        await expect.element(page.getByRole('tooltip')).not.toBeInTheDocument();
    });
});

function SettingsMenu({ onExport }: { onExport: () => void }) {
    const [checked, setChecked] = useState(false);
    return (
        <DropdownMenu>
            <DropdownMenuTrigger>Settings</DropdownMenuTrigger>
            <DropdownMenuContent>
                <DropdownMenuCheckboxItem checked={checked} onCheckedChange={setChecked}>
                    Show hidden files
                </DropdownMenuCheckboxItem>
                <DropdownMenuSub>
                    <DropdownMenuSubTrigger>More options</DropdownMenuSubTrigger>
                    <DropdownMenuSubContent>
                        <DropdownMenuItem onSelect={onExport}>Export</DropdownMenuItem>
                    </DropdownMenuSubContent>
                </DropdownMenuSub>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

test('toggles a dropdown checkbox through actual pointer events', async () => {
    await render(<SettingsMenu onExport={vi.fn()} />);
    await page.getByRole('button', { name: 'Settings' }).click();
    await page.getByRole('menuitemcheckbox', { name: 'Show hidden files' }).click();
    await page.getByRole('button', { name: 'Settings' }).click();
    await expect.element(page.getByRole('menuitemcheckbox', { name: 'Show hidden files' })).toBeChecked();
});

test('navigates a dropdown submenu with the keyboard', async ({ user }) => {
    const onExport = vi.fn();
    await render(<SettingsMenu onExport={onExport} />);
    page.getByRole('button', { name: 'Settings' }).element().focus();
    await user.keyboard('{Enter}');
    await expect.element(page.getByRole('menuitemcheckbox', { name: 'Show hidden files' })).toHaveFocus();
    await user.keyboard('{End}');
    await expect.element(page.getByRole('menuitem', { name: 'More options' })).toHaveFocus();
    await user.keyboard('{ArrowRight}');
    await expect.element(page.getByRole('menuitem', { name: 'Export' })).toHaveFocus();
    await user.keyboard('{Enter}');
    expect(onExport).toHaveBeenCalledOnce();
    await expect.element(page.getByRole('button', { name: 'Settings' })).toHaveFocus();
});

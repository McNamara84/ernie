import { describe } from 'vitest';

import { Dialog, DialogContent, DialogDescription, DialogTitle, DialogTrigger } from '@/components/ui/dialog';

import { expect, page, render, test } from '../helpers/test';

function ExampleDialog({ open = false }: { open?: boolean }) {
    return (
        <Dialog defaultOpen={open}>
            <DialogTrigger>Open</DialogTrigger>
            <DialogContent>
                <DialogTitle>Heading</DialogTitle>
                <DialogDescription>Details</DialogDescription>
                <p>Dialog body</p>
            </DialogContent>
        </Dialog>
    );
}

describe('Dialog', () => {
    test('renders overlay and content when open', async () => {
        await render(<ExampleDialog open />);
        await expect.element(page.getByRole('dialog', { name: 'Heading' })).toBeVisible();
        await expect.element(page.getByText('Details', { exact: true })).toBeVisible();
        await expect.element(page.getByText('Dialog body', { exact: true })).toBeVisible();
        expect(document.querySelector('[data-slot="dialog-overlay"]')).toBeVisible();
    });

    test('opens with trigger and closes with close button', async () => {
        await render(<ExampleDialog />);
        await expect.element(page.getByText('Dialog body', { exact: true })).not.toBeInTheDocument();
        await page.getByRole('button', { name: 'Open' }).click();
        await expect.element(page.getByText('Dialog body', { exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'Close' }).click();
        await expect.element(page.getByRole('dialog')).not.toBeInTheDocument();
        await expect.element(page.getByRole('button', { name: 'Open' })).toHaveFocus();
    });

    test('traps keyboard focus and restores it after Escape', async ({ user }) => {
        await render(<ExampleDialog />);
        await page.getByRole('button', { name: 'Open' }).click();
        await user.keyboard('{Tab}{Tab}');
        expect(page.getByRole('dialog').element().contains(document.activeElement)).toBe(true);
        await user.keyboard('{Escape}');
        await expect.element(page.getByRole('dialog')).not.toBeInTheDocument();
        await expect.element(page.getByRole('button', { name: 'Open' })).toHaveFocus();
    });
});

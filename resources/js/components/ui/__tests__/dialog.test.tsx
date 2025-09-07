import '@testing-library/jest-dom/vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect } from 'vitest';
import {
    Dialog,
    DialogTrigger,
    DialogContent,
    DialogTitle,
    DialogDescription,
} from '../dialog';

describe('Dialog', () => {
    it('renders overlay and content when open', () => {
        render(
            <Dialog defaultOpen>
                <DialogContent>
                    <DialogTitle>Title</DialogTitle>
                    <DialogDescription>Description</DialogDescription>
                </DialogContent>
            </Dialog>,
        );
        expect(document.querySelector('[data-slot="dialog-overlay"]')).toBeInTheDocument();
        expect(screen.getByText('Title')).toBeInTheDocument();
        expect(screen.getByText('Description')).toBeInTheDocument();
    });

    it('opens with trigger and closes with close button', async () => {
        render(
            <Dialog>
                <DialogTrigger>Open</DialogTrigger>
                <DialogContent>
                    <DialogTitle>Heading</DialogTitle>
                    <DialogDescription>Details</DialogDescription>
                    <p>Dialog body</p>
                </DialogContent>
            </Dialog>,
        );
        const user = userEvent.setup();
        expect(screen.queryByText('Dialog body')).not.toBeInTheDocument();
        await user.click(screen.getByText('Open'));
        expect(screen.getByText('Dialog body')).toBeInTheDocument();
        const closeButton = screen.getByRole('button', { name: /close/i });
        await user.click(closeButton);
        await waitFor(() => expect(screen.queryByText('Dialog body')).not.toBeInTheDocument());
    });
});


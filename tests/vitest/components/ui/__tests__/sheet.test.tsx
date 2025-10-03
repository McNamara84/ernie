import '@testing-library/jest-dom/vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import {
    Sheet,
    SheetTrigger,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetDescription,
} from '@/components/ui/sheet';

describe('Sheet', () => {
    it('renders overlay and content when open', () => {
        render(
            <Sheet defaultOpen>
                <SheetContent>
                    <SheetHeader>
                        <SheetTitle>Title</SheetTitle>
                        <SheetDescription>Description</SheetDescription>
                    </SheetHeader>
                    <p>Body</p>
                </SheetContent>
            </Sheet>,
        );
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        expect(screen.getByText('Body')).toBeInTheDocument();
    });

    it('opens with trigger and closes with close button', async () => {
        render(
            <Sheet>
                <SheetTrigger>Open</SheetTrigger>
                <SheetContent>
                    <SheetHeader>
                        <SheetTitle>Heading</SheetTitle>
                        <SheetDescription>Details</SheetDescription>
                    </SheetHeader>
                    <p>Content</p>
                </SheetContent>
            </Sheet>,
        );
        const user = userEvent.setup();
        expect(screen.queryByText('Content')).not.toBeInTheDocument();
        await user.click(screen.getByText('Open'));
        expect(screen.getByText('Content')).toBeInTheDocument();
        const closeButton = screen.getByRole('button', { name: /close/i });
        await user.click(closeButton);
        await waitFor(() => expect(screen.queryByText('Content')).not.toBeInTheDocument());
    });
});


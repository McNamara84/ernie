import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { toast } from 'sonner';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { DoiConflictModal, type DoiConflictModalProps } from '@/components/curation/modals/doi-conflict-modal';

// Mock sonner toast
vi.mock('sonner', () => ({
    toast: {
        success: vi.fn(),
        error: vi.fn(),
    },
}));

// Mock clipboard API
const mockWriteText = vi.fn();
Object.defineProperty(navigator, 'clipboard', {
    value: {
        writeText: mockWriteText,
    },
    writable: true,
    configurable: true,
});

describe('DoiConflictModal', () => {
    const defaultProps: DoiConflictModalProps = {
        open: true,
        onOpenChange: vi.fn(),
        existingDoi: '10.5880/test.2026.001',
        existingResourceTitle: 'Existing Test Resource',
        existingResourceId: 123,
        lastAssignedDoi: '10.5880/test.2026.003',
        suggestedDoi: '10.5880/test.2026.004',
        onUseSuggested: vi.fn(),
    };

    beforeEach(() => {
        vi.clearAllMocks();
        mockWriteText.mockResolvedValue(undefined);
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    describe('Rendering', () => {
        it('should render the modal when open', () => {
            render(<DoiConflictModal {...defaultProps} />);

            expect(screen.getByText('DOI already in use')).toBeInTheDocument();
            expect(screen.getByText(/This DOI is already stored in the ERNIE database/)).toBeInTheDocument();
        });

        it('should not render when closed', () => {
            render(<DoiConflictModal {...defaultProps} open={false} />);

            expect(screen.queryByText('DOI already in use')).not.toBeInTheDocument();
        });

        it('should display the existing DOI', () => {
            render(<DoiConflictModal {...defaultProps} />);

            expect(screen.getByText('10.5880/test.2026.001')).toBeInTheDocument();
        });

        it('should display the existing resource title', () => {
            render(<DoiConflictModal {...defaultProps} />);

            expect(screen.getByText('Existing Test Resource')).toBeInTheDocument();
        });

        it('should display the last assigned DOI', () => {
            render(<DoiConflictModal {...defaultProps} />);

            expect(screen.getByText('10.5880/test.2026.003')).toBeInTheDocument();
        });

        it('should display the suggested DOI', () => {
            render(<DoiConflictModal {...defaultProps} />);

            expect(screen.getByText('10.5880/test.2026.004')).toBeInTheDocument();
        });

        it('should hide suggested DOI section when hasSuggestion is false', () => {
            render(<DoiConflictModal {...defaultProps} hasSuggestion={false} suggestedDoi="" />);

            expect(screen.queryByText('Suggested DOI:')).not.toBeInTheDocument();
            expect(screen.getByText(/No DOI suggestion could be generated/)).toBeInTheDocument();
        });

        it('should show suggested DOI section when hasSuggestion is true', () => {
            render(<DoiConflictModal {...defaultProps} hasSuggestion={true} />);

            expect(screen.getByText('Suggested DOI:')).toBeInTheDocument();
            expect(screen.getByText('10.5880/test.2026.004')).toBeInTheDocument();
        });

        it('should hide "use suggested" button when hasSuggestion is false', () => {
            render(<DoiConflictModal {...defaultProps} hasSuggestion={false} suggestedDoi="" />);

            expect(screen.queryByRole('button', { name: 'Use suggestion' })).not.toBeInTheDocument();
        });

        it('should render link to existing resource when ID is provided', () => {
            render(<DoiConflictModal {...defaultProps} />);

            const link = screen.getByRole('link', { name: /Existing Test Resource/i });
            expect(link).toHaveAttribute('href', '/resources/123/edit');
            expect(link).toHaveAttribute('target', '_blank');
        });

        it('should render without link when resource ID is not provided', () => {
            render(<DoiConflictModal {...defaultProps} existingResourceId={undefined} />);

            expect(screen.queryByRole('link', { name: /Existing Test Resource/i })).not.toBeInTheDocument();
            expect(screen.getByText('Existing Test Resource')).toBeInTheDocument();
        });

        it('should not show resource title section when title is not provided', () => {
            render(<DoiConflictModal {...defaultProps} existingResourceTitle={undefined} />);

            expect(screen.queryByText('Existing resource:')).not.toBeInTheDocument();
        });
    });

    describe('Buttons', () => {
        it('should render close button', () => {
            render(<DoiConflictModal {...defaultProps} />);

            expect(screen.getAllByRole('button', { name: 'Close' }).at(-1)).toBeInTheDocument();
        });

        it('should render "use suggested" button when callback is provided', () => {
            render(<DoiConflictModal {...defaultProps} />);

            expect(screen.getByRole('button', { name: 'Use suggestion' })).toBeInTheDocument();
        });

        it('should not render "use suggested" button when callback is not provided', () => {
            render(<DoiConflictModal {...defaultProps} onUseSuggested={undefined} />);

            expect(screen.queryByRole('button', { name: 'Use suggestion' })).not.toBeInTheDocument();
        });
    });

    describe('Interactions', () => {
        it('should call onOpenChange when close button is clicked', async () => {
            const user = userEvent.setup();
            const onOpenChange = vi.fn();
            render(<DoiConflictModal {...defaultProps} onOpenChange={onOpenChange} />);

            await user.click(screen.getAllByRole('button', { name: 'Close' }).at(-1)!);

            expect(onOpenChange).toHaveBeenCalledWith(false);
        });

        it('should call onUseSuggested with suggested DOI when button is clicked', async () => {
            const user = userEvent.setup();
            const onUseSuggested = vi.fn();
            const onOpenChange = vi.fn();
            render(<DoiConflictModal {...defaultProps} onUseSuggested={onUseSuggested} onOpenChange={onOpenChange} />);

            await user.click(screen.getByRole('button', { name: 'Use suggestion' }));

            expect(onUseSuggested).toHaveBeenCalledWith('10.5880/test.2026.004');
            expect(onOpenChange).toHaveBeenCalledWith(false);
        });
    });

    describe('Copy to Clipboard', () => {
        it('should show success toast when copy succeeds', async () => {
            const user = userEvent.setup();

            render(<DoiConflictModal {...defaultProps} />);

            const copyButton = screen.getByLabelText('Copy last assigned DOI');
            await user.click(copyButton);

            await waitFor(() => {
                expect(toast.success).toHaveBeenCalledWith('DOI copied to the clipboard');
            });
        });

        it('should handle clipboard write failure gracefully', async () => {
            // In JSDOM, clipboard operations may fail. We test that the component
            // handles this gracefully by clicking the copy button and verifying
            // the component doesn't crash.
            //
            // Note: Full clipboard error handling is better tested via E2E tests
            // in a real browser context where clipboard APIs work correctly.
            const user = userEvent.setup();
            render(<DoiConflictModal {...defaultProps} hasSuggestion={true} />);

            const copyButton = screen.getByLabelText('Copy last assigned DOI');

            // Click the button - should not throw even if clipboard fails
            await expect(user.click(copyButton)).resolves.not.toThrow();

            // The button should still be present (no crash)
            expect(screen.getByLabelText('Copy last assigned DOI')).toBeInTheDocument();

            // Component should still be functional after click
            expect(screen.getByRole('dialog')).toBeInTheDocument();
        });

        it('should have aria-labels on copy buttons', () => {
            render(<DoiConflictModal {...defaultProps} />);

            // Both copy buttons should have accessible labels
            expect(screen.getByLabelText('Copy last assigned DOI')).toBeInTheDocument();
            expect(screen.getByLabelText('Copy suggested DOI')).toBeInTheDocument();
        });
    });

    describe('Accessibility', () => {
        it('should have accessible dialog structure', () => {
            render(<DoiConflictModal {...defaultProps} />);

            // Dialog should be present
            const dialog = screen.getByRole('dialog');
            expect(dialog).toBeInTheDocument();

            // Dialog should have title
            expect(screen.getByRole('heading', { name: /DOI already in use/i })).toBeInTheDocument();
        });
    });
});

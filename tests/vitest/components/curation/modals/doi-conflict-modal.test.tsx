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

            expect(screen.getByText('DOI bereits vergeben')).toBeInTheDocument();
            expect(screen.getByText(/Die eingegebene DOI ist bereits in der Datenbank registriert/)).toBeInTheDocument();
        });

        it('should not render when closed', () => {
            render(<DoiConflictModal {...defaultProps} open={false} />);

            expect(screen.queryByText('DOI bereits vergeben')).not.toBeInTheDocument();
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

            expect(screen.queryByText('Zugehörige Resource:')).not.toBeInTheDocument();
        });
    });

    describe('Buttons', () => {
        it('should render close button', () => {
            render(<DoiConflictModal {...defaultProps} />);

            expect(screen.getByRole('button', { name: 'Schließen' })).toBeInTheDocument();
        });

        it('should render "use suggested" button when callback is provided', () => {
            render(<DoiConflictModal {...defaultProps} />);

            expect(screen.getByRole('button', { name: 'Vorschlag übernehmen' })).toBeInTheDocument();
        });

        it('should not render "use suggested" button when callback is not provided', () => {
            render(<DoiConflictModal {...defaultProps} onUseSuggested={undefined} />);

            expect(screen.queryByRole('button', { name: 'Vorschlag übernehmen' })).not.toBeInTheDocument();
        });
    });

    describe('Interactions', () => {
        it('should call onOpenChange when close button is clicked', async () => {
            const user = userEvent.setup();
            const onOpenChange = vi.fn();
            render(<DoiConflictModal {...defaultProps} onOpenChange={onOpenChange} />);

            await user.click(screen.getByRole('button', { name: 'Schließen' }));

            expect(onOpenChange).toHaveBeenCalledWith(false);
        });

        it('should call onUseSuggested with suggested DOI when button is clicked', async () => {
            const user = userEvent.setup();
            const onUseSuggested = vi.fn();
            const onOpenChange = vi.fn();
            render(
                <DoiConflictModal
                    {...defaultProps}
                    onUseSuggested={onUseSuggested}
                    onOpenChange={onOpenChange}
                />
            );

            await user.click(screen.getByRole('button', { name: 'Vorschlag übernehmen' }));

            expect(onUseSuggested).toHaveBeenCalledWith('10.5880/test.2026.004');
            expect(onOpenChange).toHaveBeenCalledWith(false);
        });
    });

    describe('Copy to Clipboard', () => {
        it('should show success toast when copy succeeds', async () => {
            const user = userEvent.setup();
            
            render(<DoiConflictModal {...defaultProps} />);

            const copyButton = screen.getByLabelText('Zuletzt vergebene DOI kopieren');
            await user.click(copyButton);

            await waitFor(() => {
                expect(toast.success).toHaveBeenCalledWith('DOI in die Zwischenablage kopiert');
            });
        });

        it('should handle clipboard write failure gracefully', async () => {
            // Override the mock to reject for this test
            mockWriteText.mockImplementationOnce(() => Promise.reject(new Error('Failed')));
            
            const user = userEvent.setup();
            render(<DoiConflictModal {...defaultProps} />);

            const copyButton = screen.getByLabelText('Zuletzt vergebene DOI kopieren');
            
            // Should not throw when clicking
            await expect(user.click(copyButton)).resolves.not.toThrow();
            
            // The button should still be present (no crash)
            expect(screen.getByLabelText('Zuletzt vergebene DOI kopieren')).toBeInTheDocument();
        });

        it('should have aria-labels on copy buttons', () => {
            render(<DoiConflictModal {...defaultProps} />);

            // Both copy buttons should have accessible labels
            expect(screen.getByLabelText('Zuletzt vergebene DOI kopieren')).toBeInTheDocument();
            expect(screen.getByLabelText('Vorgeschlagene DOI kopieren')).toBeInTheDocument();
        });
    });

    describe('Accessibility', () => {
        it('should have accessible dialog structure', () => {
            render(<DoiConflictModal {...defaultProps} />);

            // Dialog should be present
            const dialog = screen.getByRole('dialog');
            expect(dialog).toBeInTheDocument();

            // Dialog should have title
            expect(screen.getByRole('heading', { name: /DOI bereits vergeben/i })).toBeInTheDocument();
        });
    });
});

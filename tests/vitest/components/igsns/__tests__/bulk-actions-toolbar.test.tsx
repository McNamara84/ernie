import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { BulkActionsToolbar } from '@/components/igsns/bulk-actions-toolbar';

describe('BulkActionsToolbar', () => {
    const defaultProps = {
        selectedCount: 3,
        onDelete: vi.fn(),
        canDelete: true,
        isDeleting: false,
        onRegister: vi.fn(),
        isRegistering: false,
    };

    describe('rendering', () => {
        it('renders nothing when selectedCount is 0', () => {
            const { container } = render(<BulkActionsToolbar {...defaultProps} selectedCount={0} />);
            expect(container.firstChild).toBeNull();
        });

        it('renders toolbar when items are selected', () => {
            render(<BulkActionsToolbar {...defaultProps} />);
            expect(screen.getByText('3 items selected')).toBeInTheDocument();
        });

        it('shows singular "item" for 1 selected', () => {
            render(<BulkActionsToolbar {...defaultProps} selectedCount={1} />);
            expect(screen.getByText('1 item selected')).toBeInTheDocument();
        });

        it('shows plural "items" for multiple selected', () => {
            render(<BulkActionsToolbar {...defaultProps} selectedCount={5} />);
            expect(screen.getByText('5 items selected')).toBeInTheDocument();
        });
    });

    describe('delete button', () => {
        it('renders delete button when canDelete is true', () => {
            render(<BulkActionsToolbar {...defaultProps} />);
            expect(screen.getByRole('button', { name: /delete/i })).toBeInTheDocument();
        });

        it('hides delete button when canDelete is false', () => {
            render(<BulkActionsToolbar {...defaultProps} canDelete={false} />);
            expect(screen.queryByRole('button', { name: /delete/i })).not.toBeInTheDocument();
        });

        it('calls onDelete when delete button is clicked', async () => {
            const onDelete = vi.fn();
            render(<BulkActionsToolbar {...defaultProps} onDelete={onDelete} />);

            await userEvent.click(screen.getByRole('button', { name: /delete/i }));
            expect(onDelete).toHaveBeenCalledTimes(1);
        });

        it('disables delete button while deleting', () => {
            render(<BulkActionsToolbar {...defaultProps} isDeleting={true} />);
            expect(screen.getByRole('button', { name: /deleting/i })).toBeDisabled();
        });

        it('shows "Deleting..." text while isDeleting', () => {
            render(<BulkActionsToolbar {...defaultProps} isDeleting={true} />);
            expect(screen.getByText('Deleting...')).toBeInTheDocument();
        });

        it('shows "Delete" text when not deleting', () => {
            render(<BulkActionsToolbar {...defaultProps} isDeleting={false} />);
            expect(screen.getByText('Delete')).toBeInTheDocument();
        });
    });

    describe('register button', () => {
        it('renders register button when items are selected', () => {
            render(<BulkActionsToolbar {...defaultProps} />);
            expect(screen.getByRole('button', { name: /register selected/i })).toBeInTheDocument();
        });

        it('calls onRegister when register button is clicked', async () => {
            const onRegister = vi.fn();
            render(<BulkActionsToolbar {...defaultProps} onRegister={onRegister} />);

            await userEvent.click(screen.getByRole('button', { name: /register selected/i }));
            expect(onRegister).toHaveBeenCalledTimes(1);
        });

        it('disables register button while registering', () => {
            render(<BulkActionsToolbar {...defaultProps} isRegistering={true} />);
            expect(screen.getByRole('button', { name: /registering/i })).toBeDisabled();
        });

        it('shows "Registering..." text while isRegistering', () => {
            render(<BulkActionsToolbar {...defaultProps} isRegistering={true} />);
            expect(screen.getByText('Registering...')).toBeInTheDocument();
        });

        it('shows "Register Selected" text when not registering', () => {
            render(<BulkActionsToolbar {...defaultProps} isRegistering={false} />);
            expect(screen.getByText('Register Selected')).toBeInTheDocument();
        });

        it('disables delete button while registering', () => {
            render(<BulkActionsToolbar {...defaultProps} isRegistering={true} />);
            const deleteButton = screen.getByRole('button', { name: /delete/i });
            expect(deleteButton).toBeDisabled();
        });

        it('disables register button while deleting', () => {
            render(<BulkActionsToolbar {...defaultProps} isDeleting={true} />);
            const registerButton = screen.getByRole('button', { name: /register selected/i });
            expect(registerButton).toBeDisabled();
        });
    });
});

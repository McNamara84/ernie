import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { BulkActionsToolbar } from '@/components/igsns/bulk-actions-toolbar';

describe('BulkActionsToolbar', () => {
    it('renders nothing when selectedCount is 0', () => {
        const { container } = render(
            <BulkActionsToolbar selectedCount={0} onDelete={vi.fn()} canDelete={true} />,
        );
        expect(container.firstChild).toBeNull();
    });

    it('renders selection count for single item', () => {
        render(<BulkActionsToolbar selectedCount={1} onDelete={vi.fn()} canDelete={true} />);
        expect(screen.getByText('1 item selected')).toBeInTheDocument();
    });

    it('renders plural for multiple items', () => {
        render(<BulkActionsToolbar selectedCount={5} onDelete={vi.fn()} canDelete={true} />);
        expect(screen.getByText('5 items selected')).toBeInTheDocument();
    });

    it('renders delete button when canDelete is true', () => {
        render(<BulkActionsToolbar selectedCount={2} onDelete={vi.fn()} canDelete={true} />);
        expect(screen.getByRole('button', { name: /Delete/i })).toBeInTheDocument();
    });

    it('does not render delete button when canDelete is false', () => {
        render(<BulkActionsToolbar selectedCount={2} onDelete={vi.fn()} canDelete={false} />);
        expect(screen.queryByRole('button', { name: /Delete/i })).not.toBeInTheDocument();
    });

    it('calls onDelete when delete button is clicked', async () => {
        const user = userEvent.setup();
        const onDelete = vi.fn();
        render(<BulkActionsToolbar selectedCount={2} onDelete={onDelete} canDelete={true} />);

        await user.click(screen.getByRole('button', { name: /Delete/i }));
        expect(onDelete).toHaveBeenCalledOnce();
    });

    it('disables delete button when isDeleting is true', () => {
        render(
            <BulkActionsToolbar selectedCount={2} onDelete={vi.fn()} canDelete={true} isDeleting={true} />,
        );
        expect(screen.getByRole('button', { name: /Deleting/i })).toBeDisabled();
    });

    it('shows "Deleting..." text when isDeleting', () => {
        render(
            <BulkActionsToolbar selectedCount={2} onDelete={vi.fn()} canDelete={true} isDeleting={true} />,
        );
        expect(screen.getByText(/Deleting\.\.\./)).toBeInTheDocument();
    });
});

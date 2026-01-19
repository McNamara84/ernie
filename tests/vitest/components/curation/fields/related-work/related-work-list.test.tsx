import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import RelatedWorkList from '@/components/curation/fields/related-work/related-work-list';
import type { RelatedIdentifier } from '@/types';

// Mock RelatedWorkItem component
vi.mock('@/components/curation/fields/related-work/related-work-item', () => ({
    default: vi.fn(({ item, index, onRemove, validationStatus, validationMessage }) => (
        <div data-testid={`related-work-item-${index}`}>
            <span>{item.identifier}</span>
            <span>{item.relation_type}</span>
            <span>{item.identifier_type}</span>
            {validationStatus && <span data-testid="validation-status">{validationStatus}</span>}
            {validationMessage && <span data-testid="validation-message">{validationMessage}</span>}
            <button onClick={() => onRemove(index)}>Remove</button>
        </div>
    )),
}));

describe('RelatedWorkList', () => {
    const mockOnRemove = vi.fn();

    const createItem = (overrides: Partial<RelatedIdentifier> = {}): RelatedIdentifier => ({
        identifier: '10.5880/test.2024.001',
        identifier_type: 'DOI',
        relation_type: 'Cites',
        ...overrides,
    });

    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('rendering', () => {
        it('returns null when items array is empty', () => {
            const { container } = render(<RelatedWorkList items={[]} onRemove={mockOnRemove} />);

            expect(container.firstChild).toBeNull();
        });

        it('renders the list when items are present', () => {
            const items = [createItem()];

            render(<RelatedWorkList items={items} onRemove={mockOnRemove} />);

            expect(screen.getByRole('list', { name: /related works/i })).toBeInTheDocument();
        });

        it('displays the correct count of items in header', () => {
            const items = [createItem(), createItem({ identifier: '10.5880/test.2024.002' })];

            render(<RelatedWorkList items={items} onRemove={mockOnRemove} />);

            expect(screen.getByText('Added Relations (2)')).toBeInTheDocument();
        });

        it('shows ordering hint when items are present', () => {
            const items = [createItem()];

            render(<RelatedWorkList items={items} onRemove={mockOnRemove} />);

            expect(screen.getByText('Ordered by addition')).toBeInTheDocument();
        });

        it('renders all items in the list', () => {
            const items = [
                createItem({ identifier: '10.5880/test.001' }),
                createItem({ identifier: '10.5880/test.002' }),
                createItem({ identifier: '10.5880/test.003' }),
            ];

            render(<RelatedWorkList items={items} onRemove={mockOnRemove} />);

            expect(screen.getByTestId('related-work-item-0')).toBeInTheDocument();
            expect(screen.getByTestId('related-work-item-1')).toBeInTheDocument();
            expect(screen.getByTestId('related-work-item-2')).toBeInTheDocument();
        });
    });

    describe('accessibility', () => {
        it('has accessible list with aria-label', () => {
            const items = [createItem()];

            render(<RelatedWorkList items={items} onRemove={mockOnRemove} />);

            expect(screen.getByRole('list', { name: /related works/i })).toBeInTheDocument();
        });

        it('renders items as listitems', () => {
            const items = [createItem()];

            render(<RelatedWorkList items={items} onRemove={mockOnRemove} />);

            expect(screen.getByRole('listitem')).toBeInTheDocument();
        });
    });

    describe('validation statuses', () => {
        it('passes validation status to items', () => {
            const items = [createItem(), createItem({ identifier: '10.5880/test.002' })];
            const validationStatuses = new Map([
                [0, { status: 'valid' as const }],
                [1, { status: 'invalid' as const, message: 'Not found' }],
            ]);

            render(<RelatedWorkList items={items} onRemove={mockOnRemove} validationStatuses={validationStatuses} />);

            const statuses = screen.getAllByTestId('validation-status');
            expect(statuses[0]).toHaveTextContent('valid');
            expect(statuses[1]).toHaveTextContent('invalid');
        });

        it('passes validation message to items', () => {
            const items = [createItem()];
            const validationStatuses = new Map([[0, { status: 'warning' as const, message: 'Could not verify' }]]);

            render(<RelatedWorkList items={items} onRemove={mockOnRemove} validationStatuses={validationStatuses} />);

            expect(screen.getByTestId('validation-message')).toHaveTextContent('Could not verify');
        });

        it('works without validation statuses', () => {
            const items = [createItem()];

            render(<RelatedWorkList items={items} onRemove={mockOnRemove} />);

            expect(screen.queryByTestId('validation-status')).not.toBeInTheDocument();
        });
    });

    describe('remove functionality', () => {
        it('calls onRemove with correct index when remove button is clicked', async () => {
            const user = userEvent.setup();
            const items = [createItem({ identifier: 'first' }), createItem({ identifier: 'second' })];

            render(<RelatedWorkList items={items} onRemove={mockOnRemove} />);

            const removeButtons = screen.getAllByRole('button', { name: /remove/i });
            await user.click(removeButtons[1]);

            expect(mockOnRemove).toHaveBeenCalledWith(1);
        });
    });
});

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import RelatedWorkList from '@/components/curation/fields/related-work/related-work-list';
import type { RelatedIdentifier } from '@/types';

vi.mock('@dnd-kit/core', () => ({
    closestCenter: vi.fn(),
    DndContext: ({ children, onDragEnd }: { children: ReactNode; onDragEnd: (event: { active: { id: string }; over: { id: string } }) => void }) => (
        <div>
            <button data-testid="trigger-drag" onClick={() => onDragEnd({ active: { id: 'related-work-0' }, over: { id: 'related-work-1' } })}>
                Trigger drag
            </button>
            {children}
        </div>
    ),
    KeyboardSensor: vi.fn(),
    PointerSensor: vi.fn(),
    useSensor: vi.fn(() => ({})),
    useSensors: vi.fn(() => []),
}));

vi.mock('@dnd-kit/sortable', () => ({
    arrayMove: <T,>(items: T[], oldIndex: number, newIndex: number) => {
        const next = [...items];
        const [moved] = next.splice(oldIndex, 1);
        next.splice(newIndex, 0, moved);
        return next;
    },
    SortableContext: ({ children }: { children: ReactNode }) => <div>{children}</div>,
    sortableKeyboardCoordinates: vi.fn(),
    verticalListSortingStrategy: vi.fn(),
}));

vi.mock('@/components/curation/fields/related-work/related-work-item', () => ({
    default: ({
        item,
        index,
        onRemove,
        onChange,
        validationStatus,
        validationMessage,
    }: {
        item: RelatedIdentifier;
        index: number;
        onRemove: (index: number) => void;
        onChange: (item: RelatedIdentifier) => void;
        validationStatus?: string;
        validationMessage?: string;
    }) => (
        <div data-testid={`related-work-item-${index}`} role="listitem">
            <span>{item.identifier}</span>
            {validationStatus && <span data-testid={`validation-status-${index}`}>{validationStatus}</span>}
            {validationMessage && <span data-testid={`validation-message-${index}`}>{validationMessage}</span>}
            <button onClick={() => onChange({ ...item, identifier: `${item.identifier}-edited` })}>Edit</button>
            <button onClick={() => onRemove(index)}>Remove</button>
        </div>
    ),
}));

describe('RelatedWorkList', () => {
    const mockOnItemChange = vi.fn();
    const mockOnRemove = vi.fn();
    const mockOnReorder = vi.fn();

    const createItem = (overrides: Partial<RelatedIdentifier> = {}): RelatedIdentifier => ({
        identifier: '10.5880/test.2024.001',
        identifier_type: 'DOI',
        relation_type: 'Cites',
        position: 0,
        ...overrides,
    });

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('returns null when no items are present', () => {
        const { container } = render(
            <RelatedWorkList items={[]} onItemChange={mockOnItemChange} onRemove={mockOnRemove} onReorder={mockOnReorder} />,
        );

        expect(container.firstChild).toBeNull();
    });

    it('renders the list header and accessibility roles', () => {
        render(
            <RelatedWorkList
                items={[createItem(), createItem({ identifier: '10.5880/test.2024.002', position: 1 })]}
                onItemChange={mockOnItemChange}
                onRemove={mockOnRemove}
                onReorder={mockOnReorder}
            />,
        );

        expect(screen.getByText('Added Relations (2)')).toBeInTheDocument();
        expect(screen.getByText('Drag cards to reorder them')).toBeInTheDocument();
        expect(screen.getByRole('list', { name: /related works/i })).toBeInTheDocument();
        expect(screen.getAllByRole('listitem')).toHaveLength(2);
    });

    it('passes validation status and messages to items', () => {
        render(
            <RelatedWorkList
                items={[createItem(), createItem({ identifier: '10.5880/test.2024.002', position: 1 })]}
                onItemChange={mockOnItemChange}
                onRemove={mockOnRemove}
                onReorder={mockOnReorder}
                validationStatuses={new Map([
                    [0, { status: 'valid', message: 'Looks good' }],
                    [1, { status: 'invalid', message: 'Not found' }],
                ])}
            />,
        );

        expect(screen.getByTestId('validation-status-0')).toHaveTextContent('valid');
        expect(screen.getByTestId('validation-message-1')).toHaveTextContent('Not found');
    });

    it('forwards child edits to onItemChange', async () => {
        const user = userEvent.setup();
        render(
            <RelatedWorkList
                items={[createItem()]}
                onItemChange={mockOnItemChange}
                onRemove={mockOnRemove}
                onReorder={mockOnReorder}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Edit' }));

        expect(mockOnItemChange).toHaveBeenCalledWith(
            0,
            expect.objectContaining({
                identifier: '10.5880/test.2024.001-edited',
            }),
        );
    });

    it('forwards remove actions to onRemove', async () => {
        const user = userEvent.setup();
        render(
            <RelatedWorkList
                items={[createItem(), createItem({ identifier: '10.5880/test.2024.002', position: 1 })]}
                onItemChange={mockOnItemChange}
                onRemove={mockOnRemove}
                onReorder={mockOnReorder}
            />,
        );

        const removeButtons = screen.getAllByRole('button', { name: 'Remove' });
        await user.click(removeButtons[1]);

        expect(mockOnRemove).toHaveBeenCalledWith(1);
    });

    it('calls onReorder when drag end moves an item', async () => {
        const user = userEvent.setup();
        render(
            <RelatedWorkList
                items={[createItem(), createItem({ identifier: '10.5880/test.2024.002', position: 1 })]}
                onItemChange={mockOnItemChange}
                onRemove={mockOnRemove}
                onReorder={mockOnReorder}
            />,
        );

        await user.click(screen.getByTestId('trigger-drag'));

        expect(mockOnReorder).toHaveBeenCalledWith([
            expect.objectContaining({ identifier: '10.5880/test.2024.002' }),
            expect.objectContaining({ identifier: '10.5880/test.2024.001' }),
        ]);
    });
});
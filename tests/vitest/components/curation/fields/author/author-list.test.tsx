/**
 * @vitest-environment jsdom
 */

import userEvent from '@testing-library/user-event';
import { cleanup, render, screen } from '@tests/vitest/utils/render';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import AuthorList from '@/components/curation/fields/author/author-list';
import type { AuthorEntry } from '@/components/curation/fields/author/types';

const dndState = vi.hoisted(() => ({
    event: {
        active: { id: 'author-1' },
        over: { id: 'author-2' } as { id: string } | null,
    },
}));

// Mock Drag & Drop
vi.mock('@dnd-kit/core', () => ({
    DndContext: ({ children, onDragEnd }: { children: ReactNode; onDragEnd: (event: { active: { id: string }; over: { id: string } | null }) => void }) => (
        <div>
            <button data-testid="trigger-drag" onClick={() => onDragEnd(dndState.event)}>
                Trigger drag
            </button>
            {children}
        </div>
    ),
    closestCenter: vi.fn(),
    PointerSensor: vi.fn(),
    KeyboardSensor: vi.fn(),
    useSensor: vi.fn(),
    useSensors: vi.fn(() => []),
}));

vi.mock('@dnd-kit/sortable', () => ({
    SortableContext: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
    useSortable: () => ({
        attributes: {},
        listeners: {},
        setNodeRef: vi.fn(),
        transform: null,
        transition: null,
        isDragging: false,
    }),
    arrayMove: vi.fn((array, from, to) => {
        const result = [...array];
        const [removed] = result.splice(from, 1);
        result.splice(to, 0, removed);
        return result;
    }),
    sortableKeyboardCoordinates: vi.fn(),
    verticalListSortingStrategy: vi.fn(),
}));

vi.mock('@dnd-kit/utilities', () => ({
    CSS: {
        Transform: {
            toString: () => '',
        },
    },
}));

describe('AuthorList Component', () => {
    const mockAuthors: AuthorEntry[] = [
        {
            id: 'author-1',
            type: 'person',
            orcid: '',
            firstName: 'John',
            lastName: 'Doe',
            email: 'john@example.com',
            website: '',
            isContact: false,
            orcidVerified: false,
            affiliations: [],
            affiliationsInput: '',
        },
        {
            id: 'author-2',
            type: 'institution',
            institutionName: 'Test University',
            affiliations: [],
            affiliationsInput: '',
        },
    ];

    const mockProps = {
        onAdd: vi.fn(),
        onRemove: vi.fn(),
        onAuthorChange: vi.fn(),
        onReorder: vi.fn(),
        affiliationSuggestions: [],
    };

    beforeEach(() => {
        vi.clearAllMocks();
        dndState.event = {
            active: { id: 'author-1' },
            over: { id: 'author-2' },
        };
    });

    afterEach(async () => {
        cleanup();
        // Wait for Tagify timers to complete (Tagify uses 50ms debounce internally)
        await new Promise((resolve) => setTimeout(resolve, 100));
    });

    it('renders empty state when no authors', () => {
        render(<AuthorList authors={[]} {...mockProps} />);
        
        expect(screen.getByText('No authors yet')).toBeInTheDocument();
        expect(screen.getByLabelText('Add first author')).toBeInTheDocument();
    });

    it('shows CSV import button in empty state', () => {
        render(<AuthorList authors={[]} {...mockProps} />);
        
        expect(screen.getByLabelText('Import authors from CSV file')).toBeInTheDocument();
    });

    it('calls onAdd when Add First Author button is clicked', async () => {
        const user = userEvent.setup({ delay: null });
        render(<AuthorList authors={[]} {...mockProps} />);
        
        const addButton = screen.getByLabelText('Add first author');
        await user.click(addButton);
        
        expect(mockProps.onAdd).toHaveBeenCalledTimes(1);
    });

    it('renders list of authors', () => {
        render(<AuthorList authors={mockAuthors} {...mockProps} />);
        
        expect(screen.getByText('Author 1')).toBeInTheDocument();
        expect(screen.getByText('Author 2')).toBeInTheDocument();
    });

    it('has role="list" for accessibility', () => {
        render(<AuthorList authors={mockAuthors} {...mockProps} />);
        
        expect(screen.getByRole('list', { name: 'Authors' })).toBeInTheDocument();
    });

    it('shows Add Author button when authors exist', () => {
        render(<AuthorList authors={mockAuthors} {...mockProps} />);
        
        expect(screen.getByLabelText('Add another author')).toBeInTheDocument();
    });

    it('shows CSV import button when authors exist', () => {
        render(<AuthorList authors={mockAuthors} {...mockProps} />);
        
        expect(screen.getByLabelText('Import authors from CSV file')).toBeInTheDocument();
    });

    it('calls onRemove with correct index', async () => {
        const user = userEvent.setup({ delay: null });
        render(<AuthorList authors={mockAuthors} {...mockProps} />);
        
        // Both authors should have remove buttons since there are 2 authors
        const removeButtons = screen.getAllByLabelText(/Remove author/i);
        await user.click(removeButtons[0]);
        
        expect(mockProps.onRemove).toHaveBeenCalledWith(0);
    });

    it('handles bulk add via CSV import', async () => {
        const onBulkAdd = vi.fn();
        render(<AuthorList authors={[]} {...mockProps} onBulkAdd={onBulkAdd} />);
        
        // CSV import is tested separately in author-csv-import.test.tsx
        expect(screen.getByLabelText('Import authors from CSV file')).toBeInTheDocument();
    });

    it('calls onReorder with the fully reordered author array after drag end', async () => {
        const user = userEvent.setup({ delay: null });

        render(<AuthorList authors={mockAuthors} {...mockProps} />);

        await user.click(screen.getByTestId('trigger-drag'));

        expect(mockProps.onReorder).toHaveBeenCalledTimes(1);
        expect(mockProps.onReorder).toHaveBeenCalledWith([
            expect.objectContaining({ id: 'author-2', institutionName: 'Test University' }),
            expect.objectContaining({ id: 'author-1', firstName: 'John', lastName: 'Doe' }),
        ]);
        expect(mockProps.onAuthorChange).not.toHaveBeenCalled();
    });

    it('does not call onReorder when the drop target is missing', async () => {
        const user = userEvent.setup({ delay: null });
        dndState.event = {
            active: { id: 'author-1' },
            over: null,
        };

        render(<AuthorList authors={mockAuthors} {...mockProps} />);

        await user.click(screen.getByTestId('trigger-drag'));

        expect(mockProps.onReorder).not.toHaveBeenCalled();
        expect(mockProps.onAuthorChange).not.toHaveBeenCalled();
    });

    it('does not call onReorder when an author is dropped onto itself', async () => {
        const user = userEvent.setup({ delay: null });
        dndState.event = {
            active: { id: 'author-1' },
            over: { id: 'author-1' },
        };

        render(<AuthorList authors={mockAuthors} {...mockProps} />);

        await user.click(screen.getByTestId('trigger-drag'));

        expect(mockProps.onReorder).not.toHaveBeenCalled();
        expect(mockProps.onAuthorChange).not.toHaveBeenCalled();
    });

    it('does not call onReorder when sortable ids cannot be resolved', async () => {
        const user = userEvent.setup({ delay: null });
        dndState.event = {
            active: { id: 'author-missing' },
            over: { id: 'author-2' },
        };

        render(<AuthorList authors={mockAuthors} {...mockProps} />);

        await user.click(screen.getByTestId('trigger-drag'));

        expect(mockProps.onReorder).not.toHaveBeenCalled();
        expect(mockProps.onAuthorChange).not.toHaveBeenCalled();
    });
});

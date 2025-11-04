/**
 * @vitest-environment jsdom
 */

import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import AuthorList from '@/components/curation/fields/author/author-list';
import type { AuthorEntry } from '@/components/curation/fields/author/types';

// Mock Drag & Drop
vi.mock('@dnd-kit/core', () => ({
    DndContext: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
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
        affiliationSuggestions: [],
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        cleanup();
    });

    it('renders empty state when no authors', () => {
        render(<AuthorList authors={[]} {...mockProps} />);
        
        expect(screen.getByText('No authors yet.')).toBeInTheDocument();
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
});

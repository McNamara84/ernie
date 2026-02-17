import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

// Mock DnD-Kit
vi.mock('@dnd-kit/core', () => ({
    DndContext: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
    closestCenter: vi.fn(),
    KeyboardSensor: vi.fn(),
    PointerSensor: vi.fn(),
    useSensor: vi.fn(() => ({})),
    useSensors: vi.fn(() => []),
}));

vi.mock('@dnd-kit/sortable', () => ({
    SortableContext: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
    sortableKeyboardCoordinates: vi.fn(),
    verticalListSortingStrategy: {},
    arrayMove: vi.fn((arr: unknown[], from: number, to: number) => {
        const result = [...arr];
        const [removed] = result.splice(from, 1);
        result.splice(to, 0, removed);
        return result;
    }),
}));

vi.mock('@/components/curation/fields/contributor/contributor-item', () => ({
    default: ({ contributor }: { contributor: { id: string; type: string } }) => (
        <div data-testid="contributor-item">{contributor.id}</div>
    ),
}));

vi.mock('@/components/curation/fields/contributor-csv-import', () => ({
    default: () => <div data-testid="csv-import" />,
}));

import ContributorList from '@/components/curation/fields/contributor/contributor-list';
import type { ContributorEntry } from '@/components/curation/fields/contributor/types';

const defaultProps = {
    contributors: [] as ContributorEntry[],
    onAdd: vi.fn(),
    onRemove: vi.fn(),
    onContributorChange: vi.fn(),
    onBulkAdd: vi.fn(),
    affiliationSuggestions: [],
    personRoleOptions: ['DataCollector', 'DataCurator', 'ProjectLeader'] as readonly string[],
    institutionRoleOptions: ['HostingInstitution', 'ResearchGroup'] as readonly string[],
};

describe('ContributorList', () => {
    it('shows empty state when no contributors', () => {
        render(<ContributorList {...defaultProps} />);
        expect(screen.getByText(/No contributors yet/i)).toBeInTheDocument();
    });

    it('shows Add Contributor button in empty state', () => {
        render(<ContributorList {...defaultProps} />);
        expect(screen.getByRole('button', { name: /Add.*contributor/i })).toBeInTheDocument();
    });

    it('calls onAdd when add button is clicked', async () => {
        const onAdd = vi.fn();
        const user = userEvent.setup();
        render(<ContributorList {...defaultProps} onAdd={onAdd} />);

        await user.click(screen.getByRole('button', { name: /Add.*contributor/i }));
        expect(onAdd).toHaveBeenCalledOnce();
    });

    it('renders contributor items when contributors exist', () => {
        const contributors: ContributorEntry[] = [
            {
                id: 'c1',
                type: 'person' as const,
                orcid: '',
                firstName: 'John',
                lastName: 'Doe',
                roles: [],
                rolesInput: '',
                affiliations: [],
                affiliationsInput: '',
            },
        ];
        render(<ContributorList {...defaultProps} contributors={contributors} />);
        expect(screen.getByTestId('contributor-item')).toBeInTheDocument();
    });

    it('renders Import CSV button', () => {
        render(<ContributorList {...defaultProps} />);
        expect(screen.getByRole('button', { name: /Import.*CSV/i })).toBeInTheDocument();
    });

    it('renders multiple contributors', () => {
        const contributors: ContributorEntry[] = [
            {
                id: 'c1',
                type: 'person' as const,
                orcid: '',
                firstName: 'John',
                lastName: 'Doe',
                roles: [],
                rolesInput: '',
                affiliations: [],
                affiliationsInput: '',
            },
            {
                id: 'c2',
                type: 'institution' as const,
                institutionName: 'GFZ Potsdam',
                roles: [],
                rolesInput: '',
                affiliations: [],
                affiliationsInput: '',
            },
        ];
        render(<ContributorList {...defaultProps} contributors={contributors} />);
        const items = screen.getAllByTestId('contributor-item');
        expect(items).toHaveLength(2);
    });
});

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

vi.mock('@/components/curation/fields/funding-reference/ror-search', () => ({
    loadRorFunders: vi.fn().mockResolvedValue([]),
    getFunderByRorId: vi.fn(),
}));

vi.mock('@/components/curation/fields/funding-reference/sortable-funding-reference-item', () => ({
    SortableFundingReferenceItem: ({ funding }: { funding: { id: string; funderName: string } }) => (
        <div data-testid="funding-item">{funding.funderName || 'Unnamed Funder'}</div>
    ),
}));

import { FundingReferenceField } from '@/components/curation/fields/funding-reference/funding-reference-field';

describe('FundingReferenceField', () => {
    it('shows empty state when no funding references', () => {
        render(<FundingReferenceField value={[]} onChange={vi.fn()} />);
        expect(screen.getByText(/No funding references added/)).toBeInTheDocument();
    });

    it('shows Add Funding Reference buttons in empty state', () => {
        render(<FundingReferenceField value={[]} onChange={vi.fn()} />);
        const buttons = screen.getAllByRole('button', { name: /Add Funding Reference/i });
        expect(buttons.length).toBeGreaterThanOrEqual(1);
    });

    it('calls onChange when Add button is clicked', async () => {
        const onChange = vi.fn();
        const user = userEvent.setup();
        render(<FundingReferenceField value={[]} onChange={onChange} />);

        const buttons = screen.getAllByRole('button', { name: /Add Funding Reference/i });
        await user.click(buttons[0]);
        expect(onChange).toHaveBeenCalledWith([
            expect.objectContaining({
                funderName: '',
                awardNumber: '',
            }),
        ]);
    });

    it('renders funding items when provided', () => {
        const fundings = [
            {
                id: 'f1',
                funderName: 'DFG',
                funderIdentifier: '',
                funderIdentifierType: null,
                awardNumber: 'ABC-123',
                awardUri: '',
                awardTitle: '',
                isExpanded: false,
            },
        ];
        render(<FundingReferenceField value={fundings} onChange={vi.fn()} />);
        expect(screen.getByTestId('funding-item')).toBeInTheDocument();
        expect(screen.getByText('DFG')).toBeInTheDocument();
    });

    it('shows count header', () => {
        render(<FundingReferenceField value={[]} onChange={vi.fn()} />);
        expect(screen.getByText(/0 \//)).toBeInTheDocument();
    });

    it('shows loading state for ROR data', () => {
        render(<FundingReferenceField value={[]} onChange={vi.fn()} />);
        expect(screen.getByText(/Loading ROR data/)).toBeInTheDocument();
    });
});

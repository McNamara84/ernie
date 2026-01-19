import { DndContext, KeyboardSensor, PointerSensor, useSensor, useSensors } from '@dnd-kit/core';
import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { SortableFundingReferenceItem } from '@/components/curation/fields/funding-reference/sortable-funding-reference-item';
import type { FundingReferenceEntry } from '@/components/curation/fields/funding-reference/types';

// Wrapper component to provide DnD context
function DndWrapper({ children }: { children: React.ReactNode }) {
    const sensors = useSensors(useSensor(PointerSensor), useSensor(KeyboardSensor));

    return (
        <DndContext sensors={sensors}>
            <SortableContext items={['test-funding-1']} strategy={verticalListSortingStrategy}>
                {children}
            </SortableContext>
        </DndContext>
    );
}

describe('SortableFundingReferenceItem', () => {
    const mockFunding: FundingReferenceEntry = {
        id: 'test-funding-1',
        funderName: 'German Research Foundation',
        funderIdentifier: 'https://ror.org/018mejw64',
        funderIdentifierType: 'ROR',
        awardNumber: 'DFG-123',
        awardUri: 'https://gepris.dfg.de/123',
        awardTitle: 'Test Research Grant',
        isExpanded: true,
    };

    const defaultProps = {
        funding: mockFunding,
        index: 0,
        onFunderNameChange: vi.fn(),
        onFieldsChange: vi.fn(),
        onAwardNumberChange: vi.fn(),
        onAwardUriChange: vi.fn(),
        onAwardTitleChange: vi.fn(),
        onToggleExpanded: vi.fn(),
        onRemove: vi.fn(),
        canRemove: true,
        rorFunders: [],
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the funding reference item', () => {
        render(
            <DndWrapper>
                <SortableFundingReferenceItem {...defaultProps} />
            </DndWrapper>,
        );

        // Funder name is in an input field
        expect(screen.getByDisplayValue('German Research Foundation')).toBeInTheDocument();
    });

    it('displays the drag handle', () => {
        render(
            <DndWrapper>
                <SortableFundingReferenceItem {...defaultProps} />
            </DndWrapper>,
        );

        expect(screen.getByLabelText('Drag to reorder')).toBeInTheDocument();
    });

    it('shows the funder identifier badge', () => {
        render(
            <DndWrapper>
                <SortableFundingReferenceItem {...defaultProps} />
            </DndWrapper>,
        );

        // ROR identifier is shown as a badge
        expect(screen.getByText(/ROR/)).toBeInTheDocument();
    });

    it('renders the funding heading', () => {
        render(
            <DndWrapper>
                <SortableFundingReferenceItem {...defaultProps} />
            </DndWrapper>,
        );

        expect(screen.getByText(/Funding #/)).toBeInTheDocument();
    });

    it('renders collapsed item when expanded is false', () => {
        const collapsedFunding: FundingReferenceEntry = {
            ...mockFunding,
            isExpanded: false,
        };

        render(
            <DndWrapper>
                <SortableFundingReferenceItem {...defaultProps} funding={collapsedFunding} />
            </DndWrapper>,
        );

        // In collapsed state, funder name input should still be visible
        expect(screen.getByDisplayValue('German Research Foundation')).toBeInTheDocument();
        // Show award details button should be visible
        expect(screen.getByText(/Show award details/)).toBeInTheDocument();
    });

    it('passes canRemove to child component', () => {
        render(
            <DndWrapper>
                <SortableFundingReferenceItem {...defaultProps} canRemove={true} />
            </DndWrapper>,
        );

        const removeButton = screen.getByRole('button', { name: /remove/i });
        expect(removeButton).toBeInTheDocument();
    });

    it('hides remove button when canRemove is false', () => {
        render(
            <DndWrapper>
                <SortableFundingReferenceItem {...defaultProps} canRemove={false} />
            </DndWrapper>,
        );

        // Use queryByRole since it might not exist
        const removeButton = screen.queryByRole('button', { name: /remove funding/i });
        expect(removeButton).not.toBeInTheDocument();
    });
});

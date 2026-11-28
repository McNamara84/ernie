import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import RelatedWorkList from '@/components/curation/fields/related-work/related-work-list';
import type { RelatedWorkEditorEntry } from '@/components/curation/fields/related-work/types';
import type { RelatedIdentifier } from '@/types';

vi.mock('@/components/curation/fields/related-work/related-work-item', () => ({
    default: ({
        item,
        index,
        onRemove,
        onChange,
        onIdentifierBlur,
        validationStatus,
        validationMessage,
        citationResolutionStatus,
        citationResolutionMessage,
    }: {
        item: RelatedIdentifier;
        index: number;
        onRemove: (index: number) => void;
        onChange: (item: RelatedIdentifier) => void;
        onIdentifierBlur?: () => void;
        validationStatus?: string;
        validationMessage?: string;
        citationResolutionStatus?: string;
        citationResolutionMessage?: string;
    }) => (
        <div role="listitem">
            <span>{item.identifier}</span>
            <span>{validationStatus}</span>
            <span>{validationMessage}</span>
            <span>{citationResolutionStatus}</span>
            <span>{citationResolutionMessage}</span>
            <button onClick={() => onChange({ ...item, identifier: item.identifier + '-edited' })}>Edit {index + 1}</button>
            <button onClick={onIdentifierBlur}>Blur identifier {index + 1}</button>
            <button onClick={() => onRemove(index)}>Remove {index + 1}</button>
        </div>
    ),
}));

const createEntry = (clientId: string, identifier = clientId): RelatedWorkEditorEntry => ({
    clientId,
    data: { identifier, identifier_type: 'DOI', relation_type: 'Cites' },
});

describe('RelatedWorkList', () => {
    const onItemChange = vi.fn();
    const onRemove = vi.fn();
    const props = { onItemChange, onRemove };

    it('returns null for an empty list', () => {
        const { container } = render(<RelatedWorkList entries={[]} {...props} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders accessible list roles and counts only completed entries', () => {
        render(<RelatedWorkList entries={[createEntry('one'), createEntry('empty', ' ')]} {...props} />);
        expect(screen.getByText('Added Relations (1)')).toBeInTheDocument();
        expect(screen.getByRole('list', { name: 'Related works' })).toBeInTheDocument();
        expect(screen.getAllByRole('listitem')).toHaveLength(2);
        expect(screen.queryByText('Drag cards to reorder them')).not.toBeInTheDocument();
    });

    it('accepts a completed count supplied by the field', () => {
        render(<RelatedWorkList entries={[createEntry('one')]} completedItemCount={7} {...props} />);
        expect(screen.getByText('Added Relations (7)')).toBeInTheDocument();
    });

    it('addresses edits and removals by stable identity in display order', async () => {
        const user = userEvent.setup();
        render(<RelatedWorkList entries={[createEntry('second'), createEntry('first')]} {...props} />);
        await user.click(screen.getByRole('button', { name: 'Edit 1' }));
        expect(onItemChange).toHaveBeenCalledWith('second', expect.objectContaining({ identifier: 'second-edited' }));
        await user.click(screen.getByRole('button', { name: 'Remove 2' }));
        expect(onRemove).toHaveBeenCalledWith('first');
    });

    it('forwards identifier blur by identity and supports an omitted blur callback', async () => {
        const user = userEvent.setup();
        const onIdentifierBlur = vi.fn();
        const { rerender } = render(
            <RelatedWorkList entries={[createEntry('second'), createEntry('first')]} onIdentifierBlur={onIdentifierBlur} {...props} />,
        );
        await user.click(screen.getByRole('button', { name: 'Blur identifier 2' }));
        expect(onIdentifierBlur).toHaveBeenCalledWith('first');
        rerender(<RelatedWorkList entries={[createEntry('second')]} {...props} />);
        await user.click(screen.getByRole('button', { name: 'Blur identifier 1' }));
        expect(onIdentifierBlur).toHaveBeenCalledTimes(1);
    });

    it('keeps validation and citation states attached to the correct displayed entry', () => {
        render(
            <RelatedWorkList
                entries={[createEntry('second'), createEntry('first')]}
                validationStatuses={new Map([['first', { status: 'invalid', message: 'Invalid identifier' }]])}
                citationResolutionStates={new Map([['second', { status: 'unavailable', message: 'No citation found' }]])}
                {...props}
            />,
        );
        const rows = screen.getAllByRole('listitem');
        expect(rows[0]).toHaveTextContent('No citation found');
        expect(rows[0]).not.toHaveTextContent('Invalid identifier');
        expect(rows[1]).toHaveTextContent('Invalid identifier');
        expect(rows[1]).not.toHaveTextContent('No citation found');
    });
});
